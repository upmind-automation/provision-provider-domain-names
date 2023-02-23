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

    /**
     * @param int|string $id
     *
     * @return static $this
     */
    public function setId($id)
    {
        $this->setValue('id', $id);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setName(?string $name)
    {
        $this->setValue('name', $name);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setOrganisation(?string $organisation)
    {
        $this->setValue('organisation', $organisation);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setEmail(?string $email)
    {
        $this->setValue('email', $email);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setPhone(?string $phone)
    {
        $this->setValue('phone', $phone);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setAddress1(?string $address1)
    {
        $this->setValue('address1', $address1);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setCity(?string $city)
    {
        $this->setValue('city', $city);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setState(?string $state)
    {
        $this->setValue('state', $state);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setPostcode(?string $postcode)
    {
        $this->setValue('postcode', $postcode);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setCountryCode(?string $countryCode)
    {
        $this->setValue('country_code', $countryCode);
        return $this;
    }
}
