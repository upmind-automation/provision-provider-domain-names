<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Namecheap;

use ArrayAccess;
use Throwable;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
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
use Upmind\ProvisionProviders\DomainNames\Namecheap\Data\NamecheapConfiguration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Namecheap\Helper\NamecheapApi;

/**
 * Class Provider
 *
 * @package Upmind\ProvisionProviders\DomainNames\Namecheap
 */
class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var NamecheapConfiguration
     */
    protected NamecheapConfiguration $configuration;

    protected NamecheapApi|null $api = null;

    /**
     * Min and max count of name servers that we can expect in a request
     */
    private const MIN_CUSTOM_NAMESERVERS = 2;
    private const MAX_CUSTOM_NAMESERVERS = 5;

    /**
     * Common nameservers for Namecheap
     */
    private const DEFAULT_NAMESERVERS = [
        'dns1.registrar-servers.com',
        'dns2.registrar-servers.com',
        'dns3.registrar-servers.com',
        'dns4.registrar-servers.com',
        'dns5.registrar-servers.com',
    ];

    /**
     * @param  NamecheapConfiguration  $configuration
     */
    public function __construct(NamecheapConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return AboutData
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Namecheap')
            ->setDescription('Registering, hosting, and managing Namecheap domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/namecheap-logo@2x.png');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $domains = array_map(
            fn ($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );
        $domainList = rtrim(implode(",", $domains), ',');

        $dacDomains = $this->api()->checkMultipleDomains($domainList);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);
        $domainName = Utils::getDomain($sld, $tld);

        $this->checkRegisterParams($params);

        $checkResult = $this->api()->checkMultipleDomains($domainName);

        if (count($checkResult) < 1) {
            $this->errorResult('Empty domain availability check result');
        }

        if (!$checkResult[0]->can_register) {
            $this->errorResult('This domain is not available to register');
        }

        $contacts = [
            NamecheapApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
            NamecheapApi::CONTACT_TYPE_ADMIN => $params->admin->register,
            NamecheapApi::CONTACT_TYPE_TECH => $params->tech->register,
            NamecheapApi::CONTACT_TYPE_BILLING => $params->billing->register,
        ];

        try {
            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contacts,
                $this->prepareNameservers($params, 'nameservers.ns'),
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function checkRegisterParams(RegisterDomainParams $params): void
    {
        if (!Arr::has($params, 'registrant.register')) {
            $this->errorResult('Registrant contact data is required!');
        }

        if (!Arr::has($params, 'tech.register')) {
            $this->errorResult('Tech contact data is required!');
        }

        if (!Arr::has($params, 'admin.register')) {
            $this->errorResult('Admin contact data is required!');
        }

        if (!Arr::has($params, 'billing.register')) {
            $this->errorResult('Billing contact data is required!');
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function prepareNameservers(ArrayAccess $params, string $prefix): string
    {
        $nameServers = "";

        $custom = 0;
        $default = 0;

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, $prefix . $i)) {
                $host = Arr::get($params, $prefix . $i)->host;
                if (!in_array($host, self::DEFAULT_NAMESERVERS)) {
                    $nameServers .= $host . ",";
                    $custom++;
                } else {
                    $default++;
                }
            }
        }

        if ($custom != 0 && $default != 0) {
            $this->errorResult(
                "It's not possible to mix provider default nameservers with other ones",
                $params
            );
        }

        if ($custom + $default < self::MIN_CUSTOM_NAMESERVERS) {
            $this->errorResult('Minimum two nameservers are required!', $params);
        }

        return $nameServers;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);

        $domainName = Utils::getDomain($sld, $tld);

        $eppCode = $params->epp_code ?: '0000';

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        try {
            $prevOrder = $this->api()->getDomainTransferOrders($domainName);

            if (is_null($prevOrder)) {
                $transferId = $this->api()->initiateTransfer($domainName, $eppCode);

                $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), ['transfer_id' => $transferId]);
            } else {
                $this->errorResult(
                    sprintf('Transfer order(s) for %s already exists!', $domainName),
                    $prevOrder,
                    $params
                );
            }
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld),
        );
        $period = intval($params->renew_years);

        try {
            try {
                $this->api()->renew($domainName, $period);
            } catch (Throwable $e) {
                /** @link  */
                if (Str::contains($e->getMessage(), '[2020166]')) {
                    // domain already expired - renew using reactivate method
                    $this->api()->reactivate($domainName, $period);
                    return $this->_getInfo($domainName, 'Domain reactivated + renewed successfully');
                }

                throw $e;
            }
            return $this->_getInfo($domainName, 'Domain renewed successfully');
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        try {
            $contact = $this->api()
                ->updateRegistrantContact(
                    Utils::getDomain(
                        Utils::normalizeSld($params->sld),
                        Utils::normalizeTld($params->tld)
                    ),
                    $params->contact
                );

            return ContactResult::create($contact);
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);

        $domainName = Utils::getDomain($sld, $tld);

        $nameServers = $this->prepareNameservers($params, 'ns');

        try {
            $result = $this->api()->updateNameservers(
                $sld,
                $tld,
                $nameServers,
            );

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            if (!Utils::tldSupportsLocking($params->tld)) {
                $this->errorResult(sprintf('%s domains do not support locking', $params->tld));
            }

            $currentLockStatus = $this->api()->getRegistrarLockStatus($domainName);
            if (!$lock && !$currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->api()->setRegistrarLock($domainName, $lock);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleException(Throwable $e, $params = null): void
    {
        if (!$e instanceof ProvisionFunctionError) {
            $e = new ProvisionFunctionError('Unexpected Provider Error', $e->getCode(), $e);
        }

        throw $e->withDebug([
            'params' => $params,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    /**
     * @throws \LogicException
     */
    protected function api(): NamecheapApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->resolveAPIURL(),
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/Namecheap',
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->api = new NamecheapApi($client, $this->configuration, $this->getSystemInfo());
    }

    /**
     * @return string
     */
    private function resolveAPIURL(): string
    {
        return $this->configuration->sandbox
            ? 'https://api.sandbox.namecheap.com'
            : 'https://api.namecheap.com';
    }
}
