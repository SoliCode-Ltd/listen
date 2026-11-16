<?php

namespace App\Services;

use App\Models\DemoFraudSignal;
use App\Models\DemoGuestUsage;
use App\Models\DemoIdentityLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DemoFraudService
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const STRONG_IDENTITY_TYPES = ['canvas', 'webgl', 'audio'];

    private const STRONG_LINK_GROUP_PREFIX = 'sg:';

    // Signals that prove a script is driving the browser.
    private const HARD_SIGNAL_TYPES = ['automation_detected', 'webdriver_detected'];

    // Identities specific enough that an admin block on one is safe to honour.
    private const BLOCKABLE_IDENTITY_TYPES = ['fp', 'canvas', 'webgl', 'audio'];

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
        if (! empty($clientFingerprints['primary'])) {
            $identities['fp'] = 'fp:'.hash('sha256', $clientFingerprints['primary']);
            $fingerprints['primary'] = $clientFingerprints['primary'];
        }

        // 2. Canvas fingerprint (very stable, survives incognito)
        if (! empty($clientFingerprints['canvas'])) {
            $identities['canvas'] = 'cv:'.hash('sha256', $clientFingerprints['canvas']);
            $fingerprints['canvas'] = $clientFingerprints['canvas'];
        }

        // 3. WebGL fingerprint (GPU-based, very stable)
        if (! empty($clientFingerprints['webgl'])) {
            $identities['webgl'] = 'gl:'.hash('sha256', $clientFingerprints['webgl']);
            $fingerprints['webgl'] = $clientFingerprints['webgl'];
        }

        // 4. Audio fingerprint (survives most spoofing)
        if (! empty($clientFingerprints['audio'])) {
            $identities['audio'] = 'au:'.hash('sha256', $clientFingerprints['audio']);
            $fingerprints['audio'] = $clientFingerprints['audio'];
        }

        // 5. Fonts fingerprint
        if (! empty($clientFingerprints['fonts'])) {
            $identities['fonts'] = 'ft:'.hash('sha256', $clientFingerprints['fonts']);
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
        $identities['dv'] = 'dv:'.hash('sha256', $deviceSignature);
        $fingerprints['device'] = $deviceSignature;

        // 7. UA-only identity (less reliable but still useful)
        $uaSignature = implode('|', [$ua, $acceptLanguage, $secChUa, $secChUaPlatform, $secChUaMobile]);
        $identities['ua'] = 'ua:'.hash('sha256', $uaSignature);

        // 8. IP identity (least reliable, can change)
        if ($ip !== '') {
            $identities['ip'] = 'ip:'.hash('sha256', $ip);
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
        if (! empty($fingerprints['webdriver']) && $fingerprints['webdriver'] === 'true') {
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
        if (! empty($ipAnalysis['signals'])) {
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
        $cacheKey = 'ip_analysis:'.hash('sha256', $ip);
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
        $rateKey = 'demo:fp_rate:'.$ipHash;
        $windowKey = 'demo:fp_set:'.$ipHash;

        $recentCount = (int) Cache::get($rateKey, 0);
        $recentFingerprints = Cache::get($windowKey, []);

        // Check if this is a new fingerprint from this IP
        $currentFp = $fingerprints['canvas'] ?? $fingerprints['webgl'] ?? $fingerprints['primary'] ?? '';
        $isNewFp = $currentFp !== '' && ! in_array($currentFp, $recentFingerprints, true);

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

                if (! $existing) {
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
     * Why this demo request must be refused, or null to let it through.
     *
     * Only hard evidence refuses a request: an automated browser, or an
     * identity an admin blocked. Soft signals (VPN, blocked fingerprinting,
     * missing headers) are recorded elsewhere but never block, because
     * privacy browsers and shared networks trip them all the time.
     */
    public function demoBlockReason(Request $request): ?string
    {
        $extracted = $this->extractIdentities($request);

        $hardSignals = array_values(array_filter(
            $extracted['fraud_signals'],
            static fn (array $signal) => in_array($signal['type'] ?? '', self::HARD_SIGNAL_TYPES, true)
        ));

        if ($hardSignals !== []) {
            $this->recordFraudSignals($this->strongIdentitiesFrom($extracted['identity_types']), $hardSignals);

            return 'automation';
        }

        if ($this->isExplicitlyBlocked($this->strongIdentitiesFrom($extracted['identity_types']))) {
            return 'blocked';
        }

        return null;
    }

    /**
     * An admin set is_blocked on one of these identities, or on one linked
     * to them through a strong-signal group. Weak identities (user agent,
     * device headers) are left out: thousands of people share them.
     *
     * @param  array<int, string>  $identities
     */
    public function isExplicitlyBlocked(array $identities): bool
    {
        if ($identities === []) {
            return false;
        }

        $allIdentities = $this->getLinkedStrongIdentities($identities);

        return DemoGuestUsage::query()
            ->whereIn('identity_hash', $allIdentities)
            ->where('is_blocked', true)
            ->exists();
    }

    /**
     * @param  array<string, string>  $identityTypes
     * @return array<int, string>
     */
    private function strongIdentitiesFrom(array $identityTypes): array
    {
        $strong = [];
        foreach (self::BLOCKABLE_IDENTITY_TYPES as $type) {
            if (! empty($identityTypes[$type])) {
                $strong[] = $identityTypes[$type];
            }
        }

        return $this->normalizeIdentityHashes($strong);
    }

    /**
     * @param  array<int, mixed>  $identities
     * @return array<int, string>
     */
    private function normalizeIdentityHashes(array $identities): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($value) => trim((string) $value), $identities),
            static fn (string $value) => $value !== ''
        )));
    }

    /**
     * Expand only through strong-signal link groups.
     *
     * @param  array<int, string>  $identities
     * @return array<int, string>
     */
    private function getLinkedStrongIdentities(array $identities): array
    {
        if ($identities === []) {
            return [];
        }

        $linkGroups = DemoIdentityLink::query()
            ->whereIn('identity_hash', $identities)
            ->whereIn('identity_type', self::STRONG_IDENTITY_TYPES)
            ->where('link_group', 'like', self::STRONG_LINK_GROUP_PREFIX.'%')
            ->pluck('link_group')
            ->filter(static fn ($group) => is_string($group) && trim($group) !== '')
            ->unique()
            ->values()
            ->all();

        if ($linkGroups === []) {
            return $identities;
        }

        $allLinked = DemoIdentityLink::query()
            ->whereIn('link_group', $linkGroups)
            ->whereIn('identity_type', self::STRONG_IDENTITY_TYPES)
            ->pluck('identity_hash')
            ->filter(static fn ($identity) => is_string($identity) && trim($identity) !== '')
            ->unique()
            ->values()
            ->all();

        return array_values(array_unique(array_merge($identities, $allLinked)));
    }
}
