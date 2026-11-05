<?php

declare(strict_types=1);

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protections that cost nothing: no guessing of file types, no framing of our pages by other
 * sites, no leaking of full addresses to other sites, no access to camera or microphone, https pinned
 * once it is in use, and a content security policy that only lets our own scripts run.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // The nonce must exist before the page is rendered, so the inline scripts of the layout can carry it.
        $mode = (string) config('security.csp');
        $usesPolicy = in_array($mode, ['enforce', 'report-only'], true) && ! file_exists(public_path('hot'));

        if ($usesPolicy) {
            Vite::useCspNonce();
        }

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=(), usb=()');

        // Browsers ignore this (and log a warning) on plain http, so it is only sent where it can work.
        if ($request->isSecure()) {
            $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        }

        $hsts = (int) config('security.hsts_seconds');

        if ($hsts > 0 && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', "max-age={$hsts}; includeSubDomains");
        }

        // A file served by us may set its own stricter policy (attachments do); never loosen it.
        if ($usesPolicy && ! $headers->has('Content-Security-Policy') && ! $headers->has('Content-Security-Policy-Report-Only')) {
            $headers->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $this->policy());
        }

        return $response;
    }

    private function policy(): string
    {
        $nonce = Vite::cspNonce();

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Components set inline styles for sizing; styles cannot run code, so this is the accepted trade-off.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
