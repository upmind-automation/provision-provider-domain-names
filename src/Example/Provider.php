<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Example;

use GuzzleHttp\Client;
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
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Example\Data\Configuration;

/**
 * Example provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    protected Client $client;

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
            ->setName('Example Provider')
            ->setDescription('Empty provider for demonstration purposes');
    }

    /**
     * @inheritDoc
     */
    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function transfer(TransferParams $params): DomainResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function renew(RenewParams $params): DomainResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function setLock(LockParams $params): DomainResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * Get a Guzzle HTTP client instance.
     */
    protected function client(): Client
    {
        return $this->client ??= new Client([
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug))
        ]);
    }
}
