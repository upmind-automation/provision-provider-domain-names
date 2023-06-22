<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * CentralNicReseller configuration.
 *
 * @property-read string $username
 * @property-read string $password
 * @property-read bool|null $sandbox
 * @property-read bool|null $debug Whether or not to enable debug logging
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
            'debug' => ['nullable', 'boolean'],
        ]);
    }
}
