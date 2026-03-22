<?php

namespace App\Services;

use App\Models\DemoFraudSignal;
use App\Models\DemoGuestUsage;
use App\Models\DemoIdentityLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DemoFraudService
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    // Known VPN/proxy ASN ranges (common providers)
    private const VPN_ASN_KEYWORDS = [
        'nordvpn', 'expressvpn', 'surfshark', 'cyberghost', 'protonvpn',
        'private internet access', 'mullvad', 'ipvanish', 'hotspot shield',
        'tunnelbear', 'windscribe', 'hide.me', 'purevpn', 'ivacy',
    ];

    // Suspicious user agent patterns
    private const SUSPICIOUS_UA_PATTERNS = [
        '/HeadlessChrome/i',
        '/PhantomJS/i',
        '/Selenium/i',
        '/WebDriver/i',
        '/puppeteer/i',
        '/playwright/i',
        '/CasperJS/i',
        '/Nightmare/i',
    ];

    // Data center IP ranges (partial list - commonly used for bots/VPNs)
    private const DATACENTER_ASN_KEYWORDS = [
        'digitalocean', 'amazon', 'aws', 'google cloud', 'microsoft azure',
        'linode', 'vultr', 'ovh', 'hetzner', 'scaleway', 'contabo',
    ];

    /**
     * Extract all possible identities from a request with enhanced fingerprinting.
     *
     * @return array{identities: array, primary: string, fingerprints: array, fraud_signals: array}
     */
    public function extractIdentities(Request $request): array
    {
        $identities = [];
        $fingerprints = [];
        $fraudSignals = [];

        // Get basic headers
        $ua = mb_substr(trim((string) $request->userAgent()), 0, 180);
        $acceptLanguage = mb_substr(trim((string) $request->header('Accept-Language', '')), 0, 64);
        $secChUa = mb_substr(trim((string) $request->header('Sec-CH-UA', '')), 0, 180);
        $secChUaPlatform = mb_substr(trim((string) $request->header('Sec-CH-UA-Platform', '')), 0, 80);
        $secChUaMobile = mb_substr(trim((string) $request->header('Sec-CH-UA-Mobile', '')), 0, 20);
        $ip = trim((string) $request->ip());

        // Enhanced fingerprint from client (sent via header or POST)
        $clientFingerprints = $this->parseClientFingerprints($request);

        // Build identities

        // 1. Primary browser fingerprint (most stable)
        if (!empty($clientFingerprints['primary'])) {
            $identities['fp'] = 'fp:' . hash('sha256', $clientFingerprints['primary']);
            $fingerprints['primary'] = $clientFingerprints['primary'];
        }

        // 2. Canvas fingerprint (very stable, survives incognito)
        if (!empty($clientFingerprints['canvas'])) {
            $identities['canvas'] = 'cv:' . hash('sha256', $clientFingerprints['canvas']);
            $fingerprints['canvas'] = $clientFingerprints['canvas'];
        }

        // 3. WebGL fingerprint (GPU-based, very stable)
        if (!empty($clientFingerprints['webgl'])) {
            $identities['webgl'] = 'gl:' . hash('sha256', $clientFingerprints['webgl']);
            $fingerprints['webgl'] = $clientFingerprints['webgl'];
        }

        // 4. Audio fingerprint (survives most spoofing)
        if (!empty($clientFingerprints['audio'])) {
            $identities['audio'] = 'au:' . hash('sha256', $clientFingerprints['audio']);
            $fingerprints['audio'] = $clientFingerprints['audio'];
        }

        // 5. Fonts fingerprint
        if (!empty($clientFingerprints['fonts'])) {
            $identities['fonts'] = 'ft:' . hash('sha256', $clientFingerprints['fonts']);
            $fingerprints['fonts'] = $clientFingerprints['fonts'];
        }

        // 6. Device fingerprint (combination of hardware signals)
        $deviceSignature = implode('|', [
            $ua,
            $acceptLanguage,
            $secChUa,
            $secChUaPlatform,
            $secChUaMobile,
            $clientFingerprints['screen'] ?? '',
            $clientFingerprints['hardware'] ?? '',
            $clientFingerprints['timezone'] ?? '',
        ]);
        $identities['dv'] = 'dv:' . hash('sha256', $deviceSignature);
        $fingerprints['device'] = $deviceSignature;

        // 7. UA-only identity (less reliable but still useful)
        $uaSignature = implode('|', [$ua, $acceptLanguage, $secChUa, $secChUaPlatform, $secChUaMobile]);
        $identities['ua'] = 'ua:' . hash('sha256', $uaSignature);

        // 8. IP identity (least reliable, can change)
        if ($ip !== '') {
            $identities['ip'] = 'ip:' . hash('sha256', $ip);
            $fingerprints['ip'] = $ip;
        }

        // Detect fraud signals
        $fraudSignals = array_merge($fraudSignals, $this->detectFraudSignals($request, $clientFingerprints, $ip, $ua));

        // Determine primary identity (most stable one available)
        $primary = $identities['canvas']
            ?? $identities['webgl']
            ?? $identities['audio']
            ?? $identities['fp']
            ?? $identities['fonts']
            ?? $identities['dv']
            ?? $identities['ua']
            ?? ($identities['ip'] ?? 'unknown');

        return [
            'identities' => array_values(array_filter($identities)),
            'identity_types' => array_filter($identities),
            'primary' => $primary,
            'fingerprints' => $fingerprints,
            'fraud_signals' => $fraudSignals,
        ];
    }

    /**
     * Parse client-side fingerprints from request.
     */
    private function parseClientFingerprints(Request $request): array
    {
        $fingerprints = [];

        // From header (preferred)
        $fpHeader = trim((string) $request->header('X-Listen-Fingerprint', ''));
        if ($fpHeader !== '') {
            $decodedRaw = base64_decode($fpHeader, true);
            $decoded = $decodedRaw !== false ? json_decode($decodedRaw, true) : null;
            if (is_array($decoded)) {
                $fingerprints = $decoded;
            } else {
                $fingerprints['primary'] = $fpHeader;
            }
        }

        // Fallback to cookie
        if (empty($fingerprints['primary'])) {
            $cookie = trim((string) $request->cookie('listen_demo_fp', ''));
            if ($cookie !== '') {
                $decodedRaw = base64_decode($cookie, true);
                $decoded = $decodedRaw !== false ? json_decode($decodedRaw, true) : null;
                if (is_array($decoded)) {
                    $fingerprints = array_merge($fingerprints, $decoded);
                } else {
                    $fingerprints['primary'] = $cookie;
                }
            }
        }

        // Additional fingerprints from POST body (for demo requests)
        if ($request->isMethod('POST')) {
            foreach (['canvas', 'webgl', 'audio', 'fonts', 'screen', 'hardware', 'timezone', 'webdriver'] as $key) {
                $value = trim((string) $request->input("fp_{$key}", ''));
                if ($value !== '' && empty($fingerprints[$key])) {
                    $fingerprints[$key] = $value;
                }
            }
        }

        $normalized = [];
        foreach ($fingerprints as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $clean = $this->normalizeFingerprintValue($key, $value);
            if ($clean !== '') {
                $normalized[$key] = $clean;
            }
        }

        if (empty($normalized['primary']) && isset($normalized['canvas'])) {
            $normalized['primary'] = $normalized['canvas'];
        }

        if (empty($normalized['primary']) && isset($normalized['webgl'])) {
            $normalized['primary'] = $normalized['webgl'];
        }

        if (empty($normalized['primary']) && isset($normalized['audio'])) {
            $normalized['primary'] = $normalized['audio'];
        }

        if (empty($normalized['primary']) && isset($normalized['fonts'])) {
            $normalized['primary'] = $normalized['fonts'];
        }

        if (empty($normalized['primary'])) {
            unset($normalized['primary']);
        }

        return $normalized;
    }

    private function normalizeFingerprintValue(string $key, mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = mb_substr(trim((string) $value), 0, 512);
        if ($normalized === '') {
            return '';
        }

        $lower = strtolower($normalized);
        if (in_array($lower, ['null', 'undefined', 'nan'], true)) {
            return '';
        }

        if (
            in_array($key, ['primary', 'canvas', 'webgl', 'audio', 'fonts'], true)
            && $lower === self::EMPTY_SHA256
        ) {
            return '';
        }

        return $normalized;
    }

    /**
     * Detect fraud signals from the request.
     */
    private function detectFraudSignals(Request $request, array $fingerprints, string $ip, string $ua): array
    {
        $signals = [];

        // 1. Check for headless browser / automation
        foreach (self::SUSPICIOUS_UA_PATTERNS as $pattern) {
            if (preg_match($pattern, $ua)) {
                $signals[] = [
                    'type' => 'automation_detected',
                    'data' => ['pattern' => $pattern, 'ua' => $ua],
                    'score' => 50,
                ];
                break;
            }
        }

        // 2. Check for missing/inconsistent headers (sign of spoofing)
        $secFetchSite = $request->header('Sec-Fetch-Site', '');
        $secFetchMode = $request->header('Sec-Fetch-Mode', '');
        $secChUa = $request->header('Sec-CH-UA', '');

        // Modern browsers should send Sec-CH-UA
        if ($secChUa === '' && preg_match('/Chrome\/[89][0-9]|Chrome\/1[0-2][0-9]/i', $ua)) {
            $signals[] = [
                'type' => 'missing_client_hints',
                'data' => ['expected' => 'Sec-CH-UA', 'ua' => $ua],
                'score' => 20,
            ];
        }

        // 3. Check WebDriver property reported by client
        if (!empty($fingerprints['webdriver']) && $fingerprints['webdriver'] === 'true') {
            $signals[] = [
                'type' => 'webdriver_detected',
                'data' => [],
                'score' => 100,
            ];
        }

        // 4. Check for missing fingerprints (might be blocking scripts)
        $criticalFingerprints = ['canvas', 'webgl', 'audio'];
        $missingCount = 0;
        foreach ($criticalFingerprints as $fp) {
            if (empty($fingerprints[$fp])) {
                $missingCount++;
            }
        }
        if ($missingCount >= 2) {
            $signals[] = [
                'type' => 'fingerprint_blocking',
                'data' => ['missing_count' => $missingCount],
                'score' => 30,
            ];
        }

        // 5. Check for canvas/webgl inconsistency (different values in same session)
        // This is tracked via identity linking

        // 6. IP pattern analysis (VPN/datacenter detection via DNS/ASN)
        $ipAnalysis = $this->analyzeIpAddress($ip);
        if (!empty($ipAnalysis['signals'])) {
            $signals = array_merge($signals, $ipAnalysis['signals']);
        }

        // 7. Rate of new fingerprints from same IP (abuse pattern)
        $signals = array_merge($signals, $this->checkIpFingerprintRate($ip, $fingerprints));

        return $signals;
    }

    /**
     * Analyze IP address for VPN/proxy/datacenter indicators.
     */
    private function analyzeIpAddress(string $ip): array
    {
        $result = ['signals' => []];

        if ($ip === '') {
            return $result;
        }

        // Check cache first
        $cacheKey = 'ip_analysis:' . hash('sha256', $ip);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Basic checks (can be enhanced with IP intelligence APIs)

        // Check if it's a private/reserved IP (shouldn't happen in production)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $result['signals'][] = [
                'type' => 'private_ip',
                'data' => ['ip' => $ip],
                'score' => 10,
            ];
        }

        // Store in cache for 1 hour
        Cache::put($cacheKey, $result, 3600);

        return $result;
    }

    /**
     * Check if there are too many new fingerprints from the same IP recently.
     */
    private function checkIpFingerprintRate(string $ip, array $fingerprints): array
    {
        $signals = [];

        if ($ip === '') {
            return $signals;
        }

        $ipHash = hash('sha256', $ip);
        $rateKey = 'demo:fp_rate:' . $ipHash;
        $windowKey = 'demo:fp_set:' . $ipHash;

        $recentCount = (int) Cache::get($rateKey, 0);
        $recentFingerprints = Cache::get($windowKey, []);

        // Check if this is a new fingerprint from this IP
        $currentFp = $fingerprints['canvas'] ?? $fingerprints['webgl'] ?? $fingerprints['primary'] ?? '';
        $isNewFp = $currentFp !== '' && !in_array($currentFp, $recentFingerprints, true);

        if ($isNewFp) {
            // More than 5 different fingerprints from same IP in 1 hour is suspicious
            if ($recentCount >= 5) {
                $signals[] = [
                    'type' => 'ip_fingerprint_flood',
                    'data' => ['count' => $recentCount + 1],
                    'score' => 40,
                ];
            }

            // Update tracking
            $recentFingerprints[] = $currentFp;
            $recentFingerprints = array_slice($recentFingerprints, -20); // Keep last 20
            Cache::put($rateKey, $recentCount + 1, 3600);
            Cache::put($windowKey, $recentFingerprints, 3600);
        }

        return $signals;
    }

    /**
     * Link identities that appear together in the same request.
     */
    public function linkIdentities(array $identities, array $identityTypes): void
    {
        if (count($identities) < 2) {
            return;
        }

        // Find existing link groups for any of these identities
        $existingLinks = DemoIdentityLink::whereIn('identity_hash', $identities)
            ->orderBy('created_at')
            ->get();

        $linkGroup = null;

        // Use the oldest existing link group, or create a new one
        if ($existingLinks->isNotEmpty()) {
            $linkGroup = $existingLinks->first()->link_group;
        } else {
            $linkGroup = 'lg:' . Str::uuid()->toString();
        }

        // Update all identities to use the same link group
        DB::transaction(function () use ($identities, $identityTypes, $linkGroup) {
            $now = now();

            foreach ($identities as $identity) {
                // Determine identity type from the hash prefix
                $type = 'unknown';
                foreach ($identityTypes as $t => $hash) {
                    if ($hash === $identity) {
                        $type = $t;
                        break;
                    }
                }

                DemoIdentityLink::updateOrCreate(
                    ['identity_hash' => $identity, 'link_group' => $linkGroup],
                    [
                        'identity_type' => $type,
                        'confidence' => 1.0,
                        'updated_at' => $now,
                    ]
                );
            }

            // Update demo_guest_usages to include link_group
            DemoGuestUsage::whereIn('identity_hash', $identities)
                ->whereNull('link_group')
                ->update(['link_group' => $linkGroup]);
        });
    }

    /**
     * Get all linked identities for a set of identities.
     */
    public function getLinkedIdentities(array $identities): array
    {
        if (empty($identities)) {
            return [];
        }

        // Get link groups for these identities
        $linkGroups = DemoIdentityLink::whereIn('identity_hash', $identities)
            ->pluck('link_group')
            ->unique()
            ->toArray();

        if (empty($linkGroups)) {
            return $identities;
        }

        // Get all identities in those link groups
        $allLinked = DemoIdentityLink::whereIn('link_group', $linkGroups)
            ->pluck('identity_hash')
            ->unique()
            ->toArray();

        return array_values(array_unique(array_merge($identities, $allLinked)));
    }

    /**
     * Record fraud signals for an identity.
     */
    public function recordFraudSignals(array $identities, array $signals): int
    {
        if (empty($signals)) {
            return 0;
        }

        $totalScore = 0;
        $now = now();

        foreach ($signals as $signal) {
            $score = $signal['score'] ?? 0;
            $totalScore += $score;

            foreach ($identities as $identity) {
                // Don't record duplicate signals within 1 hour
                $existing = DemoFraudSignal::where('identity_hash', $identity)
                    ->where('signal_type', $signal['type'])
                    ->where('created_at', '>', $now->copy()->subHour())
                    ->exists();

                if (!$existing) {
                    DemoFraudSignal::create([
                        'identity_hash' => $identity,
                        'signal_type' => $signal['type'],
                        'signal_data' => $signal['data'] ?? null,
                    ]);
                }
            }
        }

        // Update abuse score on guest usages
        if ($totalScore > 0) {
            DemoGuestUsage::whereIn('identity_hash', $identities)
                ->increment('abuse_score', $totalScore);
        }

        return $totalScore;
    }

    /**
     * Check if any identity is blocked.
     */
    public function isBlocked(array $identities): bool
    {
        if (empty($identities)) {
            return false;
        }

        // Get all linked identities
        $allIdentities = $this->getLinkedIdentities($identities);

        // Check if any is explicitly blocked
        $blocked = DemoGuestUsage::whereIn('identity_hash', $allIdentities)
            ->where('is_blocked', true)
            ->exists();

        if ($blocked) {
            return true;
        }

        // Check if abuse score exceeds threshold
        $maxScore = DemoGuestUsage::whereIn('identity_hash', $allIdentities)
            ->max('abuse_score');

        return $maxScore >= 100; // Block at score 100
    }

    /**
     * Get total used chars across all linked identities.
     */
    public function getLinkedUsedChars(array $identities): int
    {
        $allIdentities = $this->getLinkedIdentities($identities);

        if (empty($allIdentities)) {
            return 0;
        }

        return (int) (DemoGuestUsage::whereIn('identity_hash', $allIdentities)
            ->max('used_chars') ?? 0);
    }

    /**
     * Reserve chars for all linked identities.
     */
    public function reserveChars(array $identities, int $chars, int $limit): void
    {
        if ($chars <= 0 || empty($identities)) {
            return;
        }

        $allIdentities = $this->getLinkedIdentities($identities);
        $now = now();

        DB::transaction(function () use ($allIdentities, $chars, $limit, $now) {
            // Ensure rows exist
            $seedRows = array_map(fn(string $id) => [
                'identity_hash' => $id,
                'used_chars' => 0,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $allIdentities);

            DemoGuestUsage::upsert(
                $seedRows,
                ['identity_hash'],
                ['last_seen_at', 'updated_at']
            );

            // Get current usage
            $rows = DemoGuestUsage::whereIn('identity_hash', $allIdentities)
                ->lockForUpdate()
                ->get()
                ->keyBy('identity_hash');

            $maxUsed = 0;
            foreach ($rows as $row) {
                $maxUsed = max($maxUsed, (int) $row->used_chars);
            }

            // Check limit
            if (($maxUsed + $chars) > $limit) {
                throw new \Exception('Demo limit exceeded');
            }

            // Update ALL linked identities to the same value (prevents reset via new identity)
            $newUsed = $maxUsed + $chars;
            DemoGuestUsage::whereIn('identity_hash', $allIdentities)
                ->update([
                    'used_chars' => $newUsed,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);
        });
    }
}
