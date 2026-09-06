<?php

namespace Theme\Backend\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who is reading this page, when a candidate is signed in.
 *
 * **Storefront auth is headless, so `auth()->user()` is useless here.** There is no `web`
 * session for a customer: signing in mints a Sanctum token, the browser keeps it in
 * `localStorage` for its own fetches, and the server sets the same value as an HttpOnly cookie
 * named `customer_access_token` so a rendered page can recognise the reader. Core says so
 * itself in {@see \App\Services\Storefront\CustomerToken} — *"there is no `web` guard session
 * for a customer, so `auth()->check()` answers about the admin and is useless here."*
 *
 * That cookie is what makes a server-rendered dashboard possible at all. It is `SameSite=Lax`,
 * so it travels on exactly the request this needs — a top-level GET navigation — and not on a
 * cross-site POST.
 *
 * **This duplicates logic core keeps private, and that is worth fixing rather than hiding.**
 * `CustomerToken` answers `usable()` and keeps its cookie read private, so a package that needs
 * the *user* rather than a yes/no has to repeat the read. Repeated here as faithfully as
 * possible; recorded as a core gap (`ISSUES-CORE.md` C7), whose fix is one public method
 * returning the user so the two can never disagree about who is signed in.
 *
 * Nothing here grants access on the strength of a cookie: the token is verified against the
 * database and its expiry, exactly as core verifies it.
 */
class CurrentCandidate
{
    /** Resolved once per request — a page asks several sections the same question. */
    private static ?bool $resolved = null;
    private static ?User $user = null;

    public static function get(?Request $request = null): ?User
    {
        if (self::$resolved === true) {
            return self::$user;
        }

        self::$resolved = true;
        self::$user     = null;

        $request ??= request();
        $raw = self::rawToken($request);

        if ($raw === null) {
            return null;
        }

        $token = PersonalAccessToken::findToken($raw);

        if ($token === null) {
            return null;
        }

        // Presence is not enough — a revoked or expired token that merely exists would render a
        // page that looks signed in and whose every action then fails. Core makes the same check
        // for the same reason.
        if ($token->expires_at !== null && ! $token->expires_at->isFuture()) {
            return null;
        }

        // **Only a storefront token counts.** Core names tokens `ovynt-{platform}-token` and
        // sets this cookie only when the platform is `storefront`, so an admin token should
        // never be in it. Checked anyway: a page that renders a candidate's exam results is not
        // the place to assume a cookie contains what it is supposed to.
        if ($token->name !== 'ovynt-storefront-token') {
            return null;
        }

        $tokenable = $token->tokenable;

        return self::$user = $tokenable instanceof User ? $tokenable : null;
    }

    public static function id(?Request $request = null): ?int
    {
        return self::get($request)?->id;
    }

    public static function check(?Request $request = null): bool
    {
        return self::get($request) !== null;
    }

    /** For tests, and for anything that changes who is signed in mid-request. */
    public static function flush(): void
    {
        self::$resolved = null;
        self::$user     = null;
    }

    /**
     * The token as the browser holds it.
     *
     * The `$_COOKIE` fallback is core's, and its reason is worth carrying with it:
     * `EncryptCookies` runs in the `web` group and the login section writes this cookie from
     * page script, so `$request->cookie()` alone **always returned null** and the guard never
     * fired. Falling through is not a way around a security control — the value is verified
     * above rather than trusted.
     */
    private static function rawToken(Request $request): ?string
    {
        $cookie = $request->cookie('customer_access_token') ?: ($_COOKIE['customer_access_token'] ?? null);

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }
}
