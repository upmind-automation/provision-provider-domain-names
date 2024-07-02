<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\HRS\Data;

use Upmind\ProvisionBase\Provider\DataSet\Rules;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Data\OpenSrsConfiguration;

/**
 * Even though the configuration extends the OpenSrsConfiguration, different property-reads are available.
 * Do Not Use any inherited property-reads from the OpenSrsConfiguration.
 *
 * @property-read string $hostname Hostname of the HRS API
 * @property-read int|null $port Port of the HRS API
 * @property-read string $username Username of the account
 * @property-read string $key API key of the account
 */
class Configuration extends OpenSrsConfiguration
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'port' => ['nullable', 'numeric'],
            'username' => ['required', 'string', 'min:3'],
            'key' => ['required', 'string', 'min:6'],
        ]);
    }
}
