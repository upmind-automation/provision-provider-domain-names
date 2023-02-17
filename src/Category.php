<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames;

use Upmind\ProvisionBase\Provider\BaseCategory;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;

abstract class Category extends BaseCategory
{
    public static function aboutCategory(): AboutData
    {
        return AboutData::create()
            ->setName('Domain Names')
            ->setDescription('Register, transfer, renew and manage domain names through various registries and registrar/reseller platforms')
            ->setIcon('world');
    }

    /**
     * Poll for the latest relevant domain event notifications e.g., successful transfer-in, domain deletion etc.
     */
    abstract public function poll(PollParams $params): PollResult;

    /**
     * Check the availability of a domain SLD across one or more TLDs.
     */
    abstract public function domainAvailabilityCheck(DacParams $params): DacResult;

    /**
     * Register a new domain name.
     */
    abstract public function register(RegisterDomainParams $params): DomainResult;

    /**
     * Initiate and/or check a domain name transfer, returning successfully if transfer is complete.
     */
    abstract public function transfer(TransferParams $params): DomainResult;

    /**
     * Renew a domain name for a given number of years.
     */
    abstract public function renew(RenewParams $params): DomainResult;

    /**
     * Get information about a domain name including status, expiry date, nameservers, contacts etc.
     */
    abstract public function getInfo(DomainInfoParams $params): DomainResult;

    /**
     * Update the registrant contact details of a domain name.
     */
    abstract public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult;

    /**
     * Update a domain's nameservers
     */
    abstract public function updateNameservers(UpdateNameserversParams $params): NameserversResult;

    /**
     * Lock or unlock a domain name for transfers and changes.
     */
    abstract public function setLock(LockParams $params): DomainResult;

    /**
     * Toggle registry auto-renewal for a domain name.
     */
    abstract public function setAutoRenew(AutoRenewParams $params): DomainResult;

    /**
     * Get the EPP/Auth code of a domain name.
     */
    abstract public function getEppCode(EppParams $params): EppCodeResult;

    /**
     * Release a domain name to a new IPS tag (UK-only).
     */
    abstract public function updateIpsTag(IpsTagParams $params): ResultData;
}
