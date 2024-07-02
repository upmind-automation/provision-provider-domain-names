<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Nominet EPP credentials configuration
 *
 * @property-read string $ips_tag IPS tag of the account
 * @property-read string $password Password of the account
 * @property-read bool|null $sandbox Used for switching to testing environment
 */
class NominetConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'ips_tag' => ['required', 'string', 'min:2', 'max:16'],
            'password' => ['required', 'string', 'min:6'],
            'sandbox' => ['boolean'],
        ]);
    }
}
