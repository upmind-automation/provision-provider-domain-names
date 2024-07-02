<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EuroDNS\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * EuroDNSApi configuration.
 *
 * @property-read string $username Username
 * @property-read string $password Password
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
