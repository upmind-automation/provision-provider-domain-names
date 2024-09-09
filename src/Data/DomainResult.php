<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use DateTimeInterface;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Domain response data.
 *
 * @property-read string $id Domain ID
 * @property-read string $domain Domain
 * @property-read string[] $statuses Active domain statuses
 * @property-read bool|null $locked Transfer and/or update lock enabled
 * @property-read bool|null $whois_privacy WHOIS privacy/protection enabled
 * @property-read bool|null $auto_renew Auto renew enabled
 * @property-read ContactData|null $registrant Registrant contact
 * @property-read ContactData|null $billing Billing contact
 * @property-read ContactData|null $tech Tech contact
 * @property-read ContactData|null $admin Admin contact
 * @property-read NameserversParams $ns Nameservers
 * @property-read string $created_at Date of creation in format - Y-m-d H:i:s
 * @property-read string $updated_at Date of last update in format - Y-m-d H:i:s
 * @property-read string $expires_at Date of domain renewing in format - Y-m-d H:i:s
 */
class DomainResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            // 'sld' => ['required', 'alpha-dash'],
            // 'tld' => ['required', 'alpha-dash-dot'],
            'id' => ['required', 'string'],
            'domain' => ['required', 'string'],
            'statuses' => ['present', 'array'],
            'statuses.*' => ['filled', 'string'],
            'locked' => ['nullable', 'boolean'],
            'whois_privacy' => ['nullable', 'boolean'],
            'auto_renew' => ['nullable', 'boolean'],
            'registrant' => ['nullable', ContactData::class],
            'billing' => ['nullable', ContactData::class],
            'tech' => ['nullable', ContactData::class],
            'admin' => ['nullable', ContactData::class],
            'ns' => ['present', NameserversParams::class],
            'created_at' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'],
            'updated_at' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'],
            'expires_at' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'],
        ]);
    }

    /**
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
    public function setDomain(string $domain)
    {
        $this->setValue('domain', $domain);
        return $this;
    }

    /**
     * @param string[] $statuses
     *
     * @return static $this
     */
    public function setStatuses(array $statuses)
    {
        $this->setValue('statuses', $statuses);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setLocked(?bool $locked)
    {
        $this->setValue('locked', $locked);
        return $this;
    }

    /**
     * @param array|ContactData|null $registrant
     *
     * @return static $this
     */
    public function setRegistrant($registrant)
    {
        $this->setValue('registrant', $registrant);
        return $this;
    }

    /**
     * @param array|ContactData|null $billing
     *
     * @return static $this
     */
    public function setBilling($billing)
    {
        $this->setValue('billing', $billing);
        return $this;
    }

    /**
     * @param array|ContactData|null $tech
     *
     * @return static $this
     */
    public function setTech($tech)
    {
        $this->setValue('tech', $tech);
        return $this;
    }

    /**
     * @param array|ContactData|null $admin
     *
     * @return static $this
     */
    public function setAdmin($admin)
    {
        $this->setValue('admin', $admin);
        return $this;
    }

    /**
     * @param array|NameserversParams $ns
     *
     * @return static $this
     */
    public function setNs($ns)
    {
        $this->setValue('ns', $ns);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setCreatedAt(?DateTimeInterface $createdAt)
    {
        $this->setValue('created_at', $createdAt ? $createdAt->format('Y-m-d H:i:s') : null);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setUpdatedAt(?DateTimeInterface $updatedAt)
    {
        $this->setValue('updated_at', $updatedAt ? $updatedAt->format('Y-m-d H:i:s') : null);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setExpiresAt(?DateTimeInterface $expiresAt)
    {
        $this->setValue('expires_at', $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null);
        return $this;
    }
}
