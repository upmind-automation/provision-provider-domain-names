<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenProvider\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * OpenProvider EPP credentials configuration
 *
 * @property-read string $username username of the account
 * @property-read string $password password of the account
 * @property-read bool|null $test_mode Whether or not to connect to the CTE
 * @property-read bool|null $debug Whether or not to debug log
 */
class OpenProviderConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'test_mode' => ['boolean'],
            'debug' => ['boolean'],
        ]);
    }
}
