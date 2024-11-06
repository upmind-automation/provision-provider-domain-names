<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Norid\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Norid configuration.
 *
 * @property-read string $username
 * @property-read string $password
 * @property-read string $organisationNumber
 * @property-read bool|null $sandbox
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'organisationNumber' => ['required', 'string'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
