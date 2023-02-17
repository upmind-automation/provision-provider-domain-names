<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Nameserver hostname + ip address.
 *
 * @property-read string $host Nameserver hostname
 * @property-read string|null $ip Nameserver IP address
 */
class Nameserver extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'host' => ['required', 'alpha-dash-dot'],
            'ip' => ['nullable', 'ip'],
        ]);
    }
}
