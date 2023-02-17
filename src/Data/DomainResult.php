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
 * @property-read bool|null $locked
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
     * Set the expires_at value.
     *
     * @return static $this
     */
    public function setExpiresAt(DateTimeInterface $expiresAt)
    {
        $this->setValue('expires_at', $expiresAt->format('Y-m-d H:i:s'));
        return $this;
    }

    /**
     * Set the domain lock status.
     *
     * @return static $this
     */
    public function setLocked(?bool $locked)
    {
        $this->setValue('locked', $locked);
        return $this;
    }
}
