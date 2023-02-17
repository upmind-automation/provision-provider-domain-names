<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nira\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Nira configuration.
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'epp_username' => ['required', 'string', 'min:1'],
            'epp_password' => ['required', 'string', 'min:6'],
        ]);
    }
}
