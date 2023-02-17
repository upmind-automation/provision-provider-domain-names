<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Contact data for registering new one.
 *
 * @property-read string|null $id Existing contact ID, if no new contact data is passed
 * @property-read ContactParams|null $register New contact data, if no ID is passed
 */
class RegisterContactParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'id' => ['required_without:register', 'string'],
            'register' => ['required_without:id', ContactParams::class],
        ]);
    }
}
