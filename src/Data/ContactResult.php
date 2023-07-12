<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Contact data.
 *
 * @property-read string $name Name of new contact
 * @property-read string $organisation Organisation of new contact
 * @property-read string $email Email of new contact
 * @property-read string|null $phone Phone of new contact
 * @property-read string $address1 Full address of new contact
 * @property-read string $city City of new contact
 * @property-read string|null $state
 * @property-read string|null $postcode Postcode of new contact
 * @property-read string|null $country_code Country of new contact
 * @property-read string|null $type Type of new contact
 * @property-read string|null $password Password of new contact
 * @property-read string|int|null $id ID of contact
 */
class ContactResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'name' => ['nullable', 'string'],
            'organisation' => ['nullable', 'string'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'international_phone'],
            'address1' => ['required', 'string'],
            'city' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'postcode' => ['nullable', 'string'],
            'country_code' => ['nullable', 'string', 'size:2', 'country_code'],
            'type' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'id' => ['nullable'],
        ]);
    }
}
