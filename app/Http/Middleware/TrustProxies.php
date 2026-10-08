<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    // The client's nginx (and later Caddy) forwards requests from inside the Docker network.
    // Trust X-Forwarded-* only from private ranges, so the real visitor IP is used for rate
    // limiting and logs, while nobody on the internet can spoof it.
    protected $proxies = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
