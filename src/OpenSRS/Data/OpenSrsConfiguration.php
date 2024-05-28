<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenSRS\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string $username Username of the account
 * @property-read string $key API key of the account
 * @property-read bool|null $sandbox Used for switching to testing environment
 */
class OpenSrsConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'key' => ['required', 'string', 'min:6'],
            'sandbox' => ['boolean'],
        ]);
    }
}
