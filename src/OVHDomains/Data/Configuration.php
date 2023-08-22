<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OVHDomains\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * OVH Domains configuration.
 *
 * @property-read string $api_key Key
 * @property-read string $api_secret Secret
 * @property-read string $consumer_key Consumer Key
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 * @property-read bool|null $debug Whether or not to log API requests and responses
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_key' => ['required', 'string', 'min:3'],
            'api_secret' => ['required', 'string', 'min:6'],
            'consumer_key' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
            'debug' => ['nullable', 'boolean'],
        ]);
    }
}
