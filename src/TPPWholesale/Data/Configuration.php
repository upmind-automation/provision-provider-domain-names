<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TPPWholesale\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * TPPWholesale configuration.
 *
 * @property-read string $accountNo Account NO
 * @property-read string $userId User ID
 * @property-read string $password Password
 * @property-read string|null $api_hostname Optionally override API hostname
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'accountNo' => ['required', 'string', 'min:6'],
            'userId' => ['required', 'string', 'min:6'],
            'password' => ['required', 'string', 'min:3'],
            'api_hostname' => ['domain_name'],
        ]);
    }
}
