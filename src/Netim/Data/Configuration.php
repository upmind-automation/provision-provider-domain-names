<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netim\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example configuration.
 *
 * @property-read string $username Login id
 * @property-read string $api_token API token
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 * @property-read bool|null $debug Whether or not to log API requests and responses
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'Username' => ['required', 'string', 'min:3'],
            'Das_Password' => ['required', 'string', 'min:6'],
            'Sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
