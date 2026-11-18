<?php

namespace App\Support\InApp;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The iPhone app asks a member once, before their first voice, whether their
 * text and voice may be sent to the AI provider that makes the audio, and
 * says who that is. Apple's guideline 5.1.2(i): "clearly disclose where
 * personal data will be shared with third parties, including with third-party
 * AI, and obtain explicit permission before doing so."
 *
 * Until the member agrees, the tools that send something (the studio, the
 * demo, cloning, design, agents, dubbing, speech to text) are closed in the
 * iPhone app: a page leads to the question, a request is refused. The account
 * page, help, the legal pages and signing out stay open, and so does deleting
 * the account. The member can take the agreement back on the same page.
 *
 * Only in the iPhone app. The website, the Android app, the browser extension
 * and the developer API are not touched.
 */
class AiConsent
{
    public const COLUMN = 'ai_consent_at';

    /**
     * The tools that send a member's text, voice or video to the AI provider, by
     * route name. Every other route is listed in OPEN, and a test fails when a
     * new route is in neither: someone must then say which it is.
     */
    public const TOOLS = [
        'dashboard', 'dashboard.*', 'demo.*', 'onboarding.start',
        'voice-cloning.*', 'voice-design.*', 'voice-agents.*', 'agent-api.*',
        'dub.*', 'transcribe.*',
    ];

    /** Whether this request is one the iPhone app must ask about first. */
    public static function stands(Request $request): bool
    {
        if (! InApp::onIphone($request)) {
            return false;
        }
        $user = $request->user();
        if (! $user instanceof User || self::given($user)) {
            return false;
        }

        return $request->routeIs(...self::TOOLS);
    }

    public static function given(User $user): bool
    {
        // Before the column exists (between a deploy and its migration) nobody is asked:
        // the app is not in anyone's hands until both are done.
        return ! self::available() || $user->getAttribute(self::COLUMN) !== null;
    }

    public static function give(User $user): void
    {
        if (self::available() && $user->getAttribute(self::COLUMN) === null) {
            $user->forceFill([self::COLUMN => now()])->save();
        }
    }

    public static function withdraw(User $user): void
    {
        if (self::available() && $user->getAttribute(self::COLUMN) !== null) {
            $user->forceFill([self::COLUMN => null])->save();
        }
    }

    private static ?bool $available = null;

    public static function available(): bool
    {
        return self::$available ??= rescue(fn (): bool => Schema::hasColumn('users', self::COLUMN), false, false);
    }

    /** For tests, which add and remove the column. */
    public static function forgetAvailability(): void
    {
        self::$available = null;
    }
}
