<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TPPWholesale\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * TPPWholesale configuration.
 *
 * @property-read string $account_no Account NO field found in API Login Credentials
 * @property-read string $api_login Login field found in API Login Credentials
 * @property-read string $api_password Password Password field found in API Login Credentials
 * @property-read string|null $api_hostname Optionally override API hostname
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'account_no' => ['required', 'string', 'min:6'],
            'api_login' => ['required', 'string', 'min:6'],
            'api_password' => ['required', 'string', 'min:3'],
            'api_hostname' => ['domain_name'],
        ]);
    }
}
