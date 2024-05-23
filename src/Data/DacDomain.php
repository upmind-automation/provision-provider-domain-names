<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Domain Availability Check (DAC) result domain.
 *
 * @property-read string $domain Domain name
 * @property-read string $tld Tld
 * @property-read bool $can_register Whether or not the domain can be registered
 * @property-read bool $can_transfer Whether or not the domain can be transferred
 * @property-read bool $is_premium Whether or not the domain is premium
 * @property-read string $description Description of availability
 */
class DacDomain extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'domain' => ['required', 'domain_name'],
            'tld' => ['required', 'alpha-dash-dot'],
            'can_register' => ['required', 'boolean'],
            'can_transfer' => ['required', 'boolean'],
            'is_premium' => ['required', 'boolean'],
            'description' => ['required', 'string'],
        ]);
    }

    /**
     * @return DacDomain $this
     */
    public function setDomain(string $domain): self
    {
        $this->setValue('domain', $domain);
        return $this;
    }

    /**
     * @return DacDomain $this
     */
    public function setTld(string $tld): self
    {
        $this->setValue('tld', $tld);
        return $this;
    }

    /**
     * @return DacDomain $this
     */
    public function setCanRegister(bool $canRegister): self
    {
        $this->setValue('can_register', $canRegister);
        return $this;
    }

    /**
     * @return DacDomain $this
     */
    public function setCanTransfer(bool $canTransfer): self
    {
        $this->setValue('can_transfer', $canTransfer);
        return $this;
    }

    /**
     * @return DacDomain $this
     */
    public function setIsPremium(bool $isPremium): self
    {
        $this->setValue('is_premium', $isPremium);
        return $this;
    }

    /**
     * @return DacDomain $this
     */
    public function setDescription(string $description): self
    {
        $this->setValue('description', $description);
        return $this;
    }
}
