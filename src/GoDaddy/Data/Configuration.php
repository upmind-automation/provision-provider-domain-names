<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\GoDaddy\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * GoDaddy configuration.
 *
 * @property-read string $api_key Key
 * @property-read string $api_secret Secret
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_key' => ['required', 'string', 'min:3'],
            'api_secret' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
