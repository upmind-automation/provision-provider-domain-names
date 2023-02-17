<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\UGRegistry\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * UGRegistry configuration
 *
 * @property-read string $api_key API key
 * @property-read bool|null $debug Enables logging of API calls
 */
class UGRegistryConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_key' => ['required', 'string'],
            'debug' => ['boolean'],
        ]);
    }
}
