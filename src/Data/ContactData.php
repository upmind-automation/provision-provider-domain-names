<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Contact data.
 *
 * @property-read string|int|null $id Contact id
 * @property-read string|null $name Name
 * @property-read string|null $organisation Organisation
 * @property-read string|null $email Email
 * @property-read string|null $phone Phone
 * @property-read string|null $address1 Full address
 * @property-read string|null $city City
 * @property-read string|null $state State
 * @property-read string|null $postcode Postcode
 * @property-read string|null $country_code Country
 */
class ContactData extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'id' => ['nullable'],
            'name' => ['nullable', 'string'],
            'organisation' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'address1' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'postcode' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'size:2', 'country_code'],
        ]);
    }
}
