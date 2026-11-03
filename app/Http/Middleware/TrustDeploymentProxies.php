<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustDeploymentProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $proxies = config('operations.trusted_proxies', []);
        $this->setTrustedProxyIpAddressesToSpecificIps($request, is_array($proxies) ? array_values(array_filter($proxies, self::validAddress(...))) : []);
    }

    public static function validAddress(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $parts = explode('/', $value);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return count($parts) === 1 || (ctype_digit($parts[1]) && (int) $parts[1] > 0 && (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32));
    }
}
