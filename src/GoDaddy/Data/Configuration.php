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
 * @property-read string $customer_id CustomerId
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
            'customer_id' => ['nullable', 'string'],
            'sandbox' => ['nullable', 'boolean'],
            'debug' => ['nullable', 'boolean'],
        ]);
    }
}
