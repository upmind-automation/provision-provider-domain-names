<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Contact data.
 *
 * @property-read string|null $name Name of new contact
 * @property-read string|null $organisation Organisation of new contact
 * @property-read string $email Email of new contact
 * @property-read string $phone Phone of new contact
 * @property-read string $address1 Full address of new contact
 * @property-read string $city City of new contact
 * @property-read string|null $state State name
 * @property-read string $postcode Postcode of new contact
 * @property-read string $country_code Country of new contact
 * @property-read string|null $type Type of new contact
 * @property-read string|null $password Password of new contact
 */
class ContactParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'name' => ['required_without:organisation', 'string'],
            'organisation' => ['required_without:name', 'string'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string', 'international_phone'],
            'address1' => ['required', 'string'],
            'city' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'postcode' => ['required', 'string'],
            'country_code' => ['required', 'string', 'size:2', 'country_code'],
            'type' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
        ]);
    }
}
