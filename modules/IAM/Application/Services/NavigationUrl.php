<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

/**
 * What an administrator may type as the address of a custom menu: a plain path inside this application, such as
 * /tickets or /master/documents?status=draft. Anything that could leave the site or run code (a full address,
 * a protocol-relative "//host", a backslash, "javascript:" and the like) is refused.
 */
final class NavigationUrl
{
    public static function isSafe(string $url): bool
    {
        return strlen($url) <= 255
            && preg_match('#^/(?!/)[A-Za-z0-9\-._~%/?=&\#:@+,;]*$#', $url) === 1
            && ! str_contains($url, '..');
    }
}
