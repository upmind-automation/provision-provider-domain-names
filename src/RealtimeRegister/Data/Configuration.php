<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * RealtimeRegister configuration.
 *
 * @property-read string $customer Customer
 * @property-read string $api_key API key
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'customer' => ['required', 'string'],
            'api_key' => ['required', 'string'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
