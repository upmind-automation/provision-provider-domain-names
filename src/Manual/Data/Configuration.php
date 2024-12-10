<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Manual\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Manual configuration.
 *
 * @property-read string $api_token API token
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_token' => ['required', 'string'],
        ]);
    }
}
