<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\NameSilo\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * NameSilo configuration
 *
 * @property-read string $api_key API key
 */
class NameSiloConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_key' => ['required', 'string'],
        ]);
    }
}
