<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\NRGConsole\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * NRGConsole configuration.
 *
 * @property-read string $accountNo Account NO
 * @property-read string $userId User ID
 * @property-read string $password Password
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'accountNo' => ['required', 'string', 'min:6'],
            'userId' => ['required', 'string', 'min:6'],
            'password' => ['required', 'string', 'min:3'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
