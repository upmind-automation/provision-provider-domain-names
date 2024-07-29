<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Demo;

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
use Upmind\ProvisionProviders\DomainNames\Demo\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

/**
 * Demo domain provider which doesn't actually provision anything, but will pretend to.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    /**
     * @var DomainFaker[]|array<string,DomainFaker>
     */
    protected array $fakers = [];

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
            ->setName('Demo Provider')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/demo-logo.png')
            ->setDescription('Demo provider which doesn\'t actually provision anything, but will pretend to');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
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
        $sld = $params->sld;

        $dacDomains = array_map(function (string $tld) use ($sld) {
            $faker = $this->getFaker($sld, $tld);

            return DacDomain::create()
                ->setDomain($faker->getDomain())
                ->setTld($faker->getTld())
                ->setCanRegister($faker->canRegister())
                ->setCanTransfer(!$faker->canRegister())
                ->setIsPremium(false)
                ->setDescription('Demo domain availability check');
        }, $params->tlds);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $faker = $this->getFaker($params->sld, $params->tld);
        $result = $faker->getDomainResult();

        $now = CarbonImmutable::now();
        return $result->setMessage('Demo domain registered')
            ->setStatuses(['Active'])
            ->setNs($params->nameservers)
            ->setRegistrant($params->registrant->register)
            ->setAdmin($params->admin->register)
            ->setTech($params->tech->register)
            ->setBilling($params->billing->register)
            ->setCreatedAt($now)
            ->setUpdatedAt($now)
            ->setExpiresAt($now->addYears($params->renew_years));
    }

    /**
     * @inheritDoc
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $faker = $this->getFaker($params->sld, $params->tld);
        $result = $faker->getDomainResult();

        $expires = $faker->getExpiresAt();
        while ($expires->isPast()) {
            $expires = $expires->addYear();
        }

        return $result->setMessage('Demo domain transfer complete')
            ->setStatuses(['Active'])
            ->setLocked(false)
            ->setRegistrant($params->registrant->register ?? null)
            ->setAdmin($params->admin->register ?? null)
            ->setTech($params->tech->register ?? null)
            ->setBilling($params->billing->register ?? null)
            ->setUpdatedAt(Carbon::now())
            ->setExpiresAt($expires->addYears($params->renew_years));
    }

    /**
     * @inheritDoc
     */
    public function renew(RenewParams $params): DomainResult
    {
        $faker = $this->getFaker($params->sld, $params->tld);
        $result = $faker->getDomainResult();

        $expires = $faker->getExpiresAt();
        while ($expires->lessThan(Carbon::now()->subMonths(3))) {
            $expires = $expires->addYear();
        }

        return $result->setMessage('Demo domain renewed')
            ->setStatuses(['Active'])
            ->setUpdatedAt(Carbon::now())
            ->setExpiresAt($expires->addYears($params->renew_years));
    }

    /**
     * @inheritDoc
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $faker = $this->getFaker($params->sld, $params->tld);

        return $faker->getDomainResult()->setMessage('Domain info retrieved');
    }

    /**
     * @inheritDoc
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        return ContactResult::create($params->contact)
            ->setMessage('Demo domain registrant updated');
    }

    /**
     * @inheritDoc
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $ns = array_filter([
            'ns1' => $params->ns1,
            'ns2' => $params->ns2,
            'ns3' => $params->ns3,
            'ns4' => $params->ns4,
            'ns5' => $params->ns5,
        ]);

        return NameserversResult::create($ns)
            ->setMessage('Demo domain nameservers updated');
    }

    /**
     * @inheritDoc
     */
    public function setLock(LockParams $params): DomainResult
    {
        $faker = $this->getFaker($params->sld, $params->tld);
        $result = $faker->getDomainResult();

        return $result->setMessage(sprintf('Demo domain %s', $params->lock ? 'locked' : 'unlocked'))
            ->setLocked(boolval($params->lock));
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $faker = $this->getFaker($params->sld, $params->tld);

        if (Str::endsWith($faker->getDomain(), '.uk')) {
            $this->errorResult('Operation not available for this TLD');
        }

        return EppCodeResult::create()
            ->setMessage('Demo domain EPP code retrieved')
            ->setEppCode($faker->getEppCode());
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $faker = $this->getFaker($params->sld, $params->tld);

        if (!Str::endsWith($faker->getDomain(), '.uk')) {
            $this->errorResult('Operation not available for this TLD');
        }

        return ResultData::create()
            ->setMessage('Demo domain IPS tag updated');
    }

    protected function getFaker(string $sld, string $tld): DomainFaker
    {
        return $this->fakers[Utils::getDomain($sld, $tld)] ??= new DomainFaker($this->configuration, $sld, $tld);
    }
}
