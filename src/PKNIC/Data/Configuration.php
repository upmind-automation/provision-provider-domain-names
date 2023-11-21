<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\PKNIC\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * PKNIC configuration.
 *
 * @property-read string $username Login id
 * @property-read string $api_token API token
 * @property-read string $api_url API url
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 * @property-read bool|null $debug Whether or not to log API requests and responses
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'api_token' => ['required', 'string', 'min:6'],
            'api_url' => ['nullable', 'string'],
            'sandbox' => ['nullable', 'boolean'],
            'debug' => ['nullable', 'boolean'],
        ]);
    }
}
