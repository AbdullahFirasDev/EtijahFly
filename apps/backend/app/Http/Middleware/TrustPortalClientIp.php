<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the API is hosted apart from the portals (e.g. on a public host behind its own load
 * balancer), it sees the portal server's address instead of the end user's. The portals then
 * send the user's IP in X-Portal-Client-Ip together with the shared PORTAL_PROXY_SECRET, and only
 * that pairing is trusted as the request IP (rate limits, audit log). Without a configured secret
 * nothing changes and the regular trusted-proxy rules apply.
 */
class TrustPortalClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('platform.portal_proxy_secret');
        $given = (string) $request->headers->get('X-Portal-Secret', '');
        $ip = (string) $request->headers->get('X-Portal-Client-Ip', '');

        if ($secret !== '' && $given !== '' && hash_equals($secret, $given) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $ip);
            $request->headers->remove('X-Forwarded-For');
        }

        // The secret must not travel further (logs, provider calls).
        $request->headers->remove('X-Portal-Secret');

        return $next($request);
    }
}
