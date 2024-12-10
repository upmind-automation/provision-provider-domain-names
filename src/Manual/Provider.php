<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Manual;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
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
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Manual\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

/**
 * Manual domain provider which doesn't actually provision anything, but will pretend to.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Manual Provider')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/Demo-logo.png')
            ->setDescription('Manual provider which doesn\'t actually provision anything, but will return an error message for all requests.');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function renew(RenewParams $params): DomainResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     */
    public function setLock(LockParams $params): DomainResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $this->errorResult('Manual provisioning required');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Manual provisioning required');
    }
}
