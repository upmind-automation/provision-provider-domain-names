<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Namecheap\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example configuration.
 *
 * @property-read string $username Login id
 * @property-read string $api_token API token
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class NamecheapConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3', 'max:20'],
            'api_token' => ['required', 'string', 'min:6', 'max:50'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
