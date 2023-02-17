<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * EPP domain contact data.
 *
 * @property-read string $contact_id Internal contact id
 * @property-read string|null $name
 * @property-read string|null $email
 * @property-read string|null $phone
 * @property-read string|null $organisation
 * @property-read string|null $address1
 * @property-read string|null $city
 * @property-read string|null $state
 * @property-read string|null $postcode
 * @property-read string|null $country_code
 * @property-read string|null $type EPP contact type (One of: loc, int, auto)
 */
class DomainContactInfo extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'contact_id' => ['required', 'string'],
            'name' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'organisation' => ['nullable', 'string'],
            'address1' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'postcode' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);
    }
}
