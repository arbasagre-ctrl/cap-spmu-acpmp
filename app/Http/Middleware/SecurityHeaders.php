<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $response = $next($request);

        $response->headers->set(
            'X-Content-Type-Options',
            'nosniff'
        );

        $response->headers->set(
            'Referrer-Policy',
            'same-origin'
        );

        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()'
        );

        if ($request->routeIs('files.show', 'administration.document-templates.sample')) {
            /*
             * A protected PDF/image may be displayed only inside the same
             * SPMU-ACPMP application. The generated template sample remains
             * protected by its route authorization; this exception exists
             * solely for the authenticated, same-origin preview iframe.
             */
            $response->headers->set(
                'X-Frame-Options',
                'SAMEORIGIN'
            );

            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; ".
                "base-uri 'self'; ".
                "frame-ancestors 'self'; ".
                "form-action 'self'; ".
                "img-src 'self' data: blob:; ".
                "style-src 'self' 'unsafe-inline'; ".
                "script-src 'self' 'unsafe-inline'; ".
                "object-src 'self';"
            );
        } else {
            $response->headers->set(
                'X-Frame-Options',
                'DENY'
            );

            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; ".
                "base-uri 'self'; ".
                "frame-ancestors 'none'; ".
                "frame-src 'self'; ".
                "form-action 'self'; ".
                "img-src 'self' data: blob:; ".
                "style-src 'self' 'unsafe-inline'; ".
                "script-src 'self' 'unsafe-inline'; ".
                "object-src 'self';"
            );
        }

        if (
            app()->environment('production')
            && $request->isSecure()
        ) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
