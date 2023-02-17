<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Enom\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Enom configuration.
 *
 * @property-read string $username Login id of the account
 * @property-read string $api_token API token of the account
 * @property-read bool|null $sandbox Used for switching to testing environment
 * @property-read bool|null $debug Whether or not to debug log
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'api_token' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
            'debug' => ['nullable', 'boolean'],
        ]);
    }
}
