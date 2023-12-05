<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\HRS\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string $hostname Hostname of the HRS API
 * @property-read int|null $port Port of the HRS API
 * @property-read string $username Username of the account
 * @property-read string $key API key of the account
 * @property-read bool|null $debug Whether or not to debug log
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'port' => ['nullable', 'numeric'],
            'username' => ['required', 'string', 'min:3'],
            'key' => ['required', 'string', 'min:6'],
            'debug' => ['boolean'],
        ]);
    }
}
