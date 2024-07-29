<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainNameApi\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * DomainNamemApi configuration
 *
 * @property-read string $username Username
 * @property-read string $password Password
 * @property-read bool|null $sandbox Use OTE
 */
class DomainNameApiConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'sandbox' => ['boolean'],
        ]);
    }
}
