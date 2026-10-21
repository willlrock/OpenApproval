<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustConfiguredProxies extends TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $proxies = array_values(array_diff(config('app.trusted_proxies', []), ['*', '**', 'REMOTE_ADDR']));
        $this->setTrustedProxyIpAddressesToSpecificIps($request, $proxies);
    }
}
