<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\LogicBoxes\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * LogicBoxes configuration
 *
 * @property-read string $reseller_id User ID
 * @property-read string $api_key API key
 * @property-read bool|null $sandbox Used for switching to testing environment
 * @property-read bool|null $debug Enable API request debug logging
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'reseller_id' => ['required', 'string'],
            'api_key' => ['required', 'string'],
            'sandbox' => ['boolean'],
            'debug' => ['boolean'],
        ]);
    }
}
