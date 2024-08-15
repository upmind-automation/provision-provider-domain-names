<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * SynergyWholesale configuration
 *
 * @property-read string $reseller_id resellerID
 * @property-read string $api_key apiKey
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'reseller_id' => ['required', 'string'],
            'api_key' => ['required', 'string'],
        ]);
    }
}
