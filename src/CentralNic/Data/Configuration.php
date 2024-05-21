<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNic\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example configuration.
 *
 * @property-read string $registrar_handle_id
 * @property-read string $password
 * @property-read bool|null $sandbox
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'registrar_handle_id' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
