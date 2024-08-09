<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * SynergyWholesale configuration
 *
 * @property-read string $apiKey apiKey
 * @property-read string $resellerID resellerID
 * @property-read bool|null $sandbox Use OTE
 * @property-read bool|null $debug Enable debug logging
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'apiKey' => ['required', 'string'],
            'resellerID' => ['required', 'string'],
            'sandbox' => ['boolean'],
            'debug' => ['boolean'],
        ]);
    }
}
