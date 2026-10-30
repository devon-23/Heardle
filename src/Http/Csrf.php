<?php

declare(strict_types=1);

namespace HeardLive\Http;

/** Per-session token required on every POST, sent as a `_token` field or `X-CSRF-Token` header. */
final class Csrf
{
    private const KEY = 'csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::KEY];
    }

    public static function verify(Request $request): bool
    {
        $sent = $request->body['_token'] ?? $request->header('x-csrf-token');

        return is_string($sent) && $sent !== '' && hash_equals(self::token(), $sent);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }
}
