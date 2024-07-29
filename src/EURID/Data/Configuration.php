<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EURID\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * EURID configuration.
 *
 * @property-read string $username EPP username
 * @property-read string $password EPP password
 * @property-read string $billing_contact_id Billing contact ID
 * @property-read string $tech_contact_id Tech contact ID
 * @property-read bool|null $sandbox

 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'billing_contact_id' => ['required', 'string'],
            'tech_contact_id' => ['required', 'string'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
