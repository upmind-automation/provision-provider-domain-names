<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Example;

use Carbon\Carbon;
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
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Example\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

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
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function renew(RenewParams $params): DomainResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        // $domainInfo = $this->client()->get(sprintf('domains/%s', $domain));

        // Example data:
        return DomainResult::create()
            ->setDomain($domain)
            ->setStatuses(['Expired'])
            ->setLocked(false)
            ->setNs([
                'ns1' => Nameserver::create()->setHost('ns1.foo.com'),
                'ns2' => Nameserver::create()->setHost('ns2.foo.com')
            ])
            ->setRegistrant(
                ContactData::create()
                    ->setName('John Doe')
                    ->setEmail('john@doe.com')
                    ->setPhone(Utils::eppPhoneToInternational('+1.2125551212'))
                    ->setAddress1('123 Main St')
                    ->setCity('New York')
                    ->setState('NY')
                    ->setPostcode('10001')
                    ->setCountryCode('US')
            )
            ->setCreatedAt(Carbon::now()->subDays(365))
            ->setExpiresAt(Carbon::now()->addDays(100));
    }

    /**
     * @inheritDoc
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function setLock(LockParams $params): DomainResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Not implemented');
    }

    /**
     * Get a Guzzle HTTP client instance.
     */
    protected function client(): Client
    {
        return $this->client ??= new Client([
            'handler' => $this->getGuzzleHandlerStack(),
            'base_uri' => 'https://api.example.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->api_token,
            ],
        ]);
    }
}
