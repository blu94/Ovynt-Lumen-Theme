<?php

namespace Theme\Backend\Support;

use App\Models\User;
use App\Services\Storefront\CustomerToken;
use Illuminate\Http\Request;

/**
 * Who is reading this page, when a candidate is signed in.
 *
 * **Storefront auth is headless, so `auth()->user()` is useless here.** There is no `web`
 * session for a customer: signing in mints a Sanctum token, the browser keeps it for its own
 * fetches, and the server sets the same value as an HttpOnly cookie named
 * `customer_access_token` so a rendered page can recognise the reader.
 *
 * The answer is core's: {@see CustomerToken::user()} reads the bearer or the cookie, verifies
 * the token and its expiry, and requires the storefront token name so an admin's token in that
 * cookie identifies nobody. This class used to repeat all of that for want of that one method
 * (`ISSUES-CORE.md` C7, since built); now it only memoises the answer for the request, because
 * a page asks several sections the same question and each is one database lookup.
 *
 * Keeping the class rather than calling core from every driver keeps one name for "the
 * candidate" across the theme — and the memo, which core's stateless service does not carry.
 */
class CurrentCandidate
{
    /** Resolved once per request. */
    private static ?bool $resolved = null;
    private static ?User $user = null;

    public static function get(?Request $request = null): ?User
    {
        if (self::$resolved === true) {
            return self::$user;
        }

        self::$resolved = true;
        self::$user     = app(CustomerToken::class)->user($request ?? request());

        return self::$user;
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
}
