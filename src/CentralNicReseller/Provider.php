<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNicReseller;

use Carbon\Carbon;
use ErrorException;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppContactHandle;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper\EppHelper;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper\CentralNicResellerApi;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\FinishTransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\InitiateTransferResult;
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
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\EppExtension\EppConnection;

/**
 * CentralNicReseller provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    protected EppConnection $connection;

    protected EppHelper $epp;
    protected CentralNicResellerApi $api;

    private const MAX_CUSTOM_NAMESERVERS = 13;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function __destruct()
    {
        if (isset($this->connection) && $this->connection->isLoggedin()) {
            $this->connection->logout();
        }
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('CentralNic Reseller')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/centralnic-reseller-logo.png')
            ->setDescription(
                'Register, transfer, renew and manage over 1,100 TLDs with CentralNicReseller'
                . ' (formerly RRPproxy) domains'
            );
    }

    public function poll(PollParams $params): PollResult
    {
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;

        try {
            $poll = $this->epp()->poll(intval($params->limit), $since);

            return PollResult::create($poll);
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
        }
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $domains = array_map(
            fn ($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        try {
            $dacDomains = $this->epp()->checkMultipleDomains($domains);

            return DacResult::create([
                'domains' => $dacDomains,
            ]);
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
        }
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $checkResult = $this->epp()->checkMultipleDomains([$domainName]);

        if (count($checkResult) < 1) {
            throw $this->errorResult('Empty domain availability check result');
        }

        if (!$checkResult[0]->can_register) {
            throw $this->errorResult('This domain is not available to register');
        }

        //$contactIds = $this->getRegisterContactIds($params);

        /** @var Nameserver[] $nameServers */
        $nameServers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $nameServers['nameserver' . ($i - 1)] = Arr::get($params, 'nameservers.ns' . $i)->host;
            }
        }

        try {
            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $nameServers,
                $params->registrant,
                $params->admin,
                $params->tech,
                $params->billing
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function transfer(TransferParams $params): DomainResult
    {
        throw $this->errorResult('Operation not supported');
    }

    public function initiateTransfer(TransferParams $params): InitiateTransferResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $eppCode = $params->epp_code ?: null;

        try {
            $domain = $this->_getInfo($domainName, '');

            return InitiateTransferResult::create([
                'domain' => $domainName,
                'transfer_status' => 'complete',
                'domain_info' => $domain,
            ])->setMessage('Domain active in registrar account');
        } catch (eppException $e) {
            // initiate transfer ...
        }

        try {
            $transferId = $this->api()->initiateTransfer(
                $domainName,
                intval($params->renew_years),
                $eppCode,
                $params->registrant ?? null,
                $params->admin ?? null,
                $params->tech ?? null,
                $params->billing ?? null
            );

            return InitiateTransferResult::create([
                'domain' => $domainName,
                'transfer_status' => 'in_progress',
                'transfer_order_id' => $transferId,
            ])->setMessage(sprintf('Transfer for %s domain successfully created!', $domainName));
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
        }
    }

    public function finishTransfer(FinishTransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (eppException $e) {
            // continue on to initiate transfer
        }
        try {
            $status = $this->epp()->getTransferInfo($domainName);

            throw $this->errorResult(
                sprintf('Transfer order status for %s: %s', $domainName, $status),
                [],
                $params
            );
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $period = intval($params->renew_years);

        try {
            $this->epp()->renew($domainName, $period);

            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            return $this->_getInfo($domainName);
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function _getInfo(string $domain, $msg = 'Domain data obtained'): DomainResult
    {
        $domainInfo = $this->epp()->getDomainInfo($domain);

        return DomainResult::create($domainInfo, false)->setMessage($msg);
    }

    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            $contact = $this->epp()->updateRegistrantContact($domainName, $params->contact);

            return ContactResult::create($contact);
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);

        $domainName = Utils::getDomain($sld, $tld);

        $nameServers = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameServers[] = Arr::get($params, 'ns' . $i);
            }
        }

        try {
            $this->api()->updateNameservers($domainName, $params->pluckHosts());

            $result = collect($params->pluckHosts())
                ->mapWithKeys(fn ($ns, $i) => ['ns' . ($i + 1) => ['host' => $ns]]);

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $lock = !!$params->lock;

        try {
            $currentLockStatuses = $this->epp()->getRegistrarLockStatuses($domainName);
            $lockedStatuses = $this->epp()->getLockedStatuses();

            $addStatuses = [];
            $removeStatuses = [];

            if ($lock) {
                if (!$addStatuses = array_diff($lockedStatuses, $currentLockStatuses)) {
                    return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
                }
            } else {
                if (!$removeStatuses = array_intersect($lockedStatuses, $currentLockStatuses)) {
                    return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
                }
            }

            $this->epp()->setRegistrarLock($domainName, $addStatuses, $removeStatuses);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $autoRenew = !!$params->auto_renew;

        try {
            $this->epp()->setRenewalMode($domainName, $autoRenew);

            return $this->_getInfo($domainName, 'Auto-renew mode updated');
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            $eppCode = $this->epp()->getDomainEppCode($domainName);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported');
    }

    private function _eppExceptionHandler(eppException $exception, array $data = [], array $debug = []): void
    {
        $data['error_reason'] = $exception->getReason();
        $data['error_code'] = $exception->getCode();

        if ($response = $exception->getResponse()) {
            $debug['response_xml'] = $response->saveXML();
        }

        switch ($exception->getCode()) {
            case 2001:
                $errorMessage = 'Invalid request data';
                break;
            case 2201:
                $errorMessage = 'Permission denied';
                break;
            default:
                $errorMessage = $exception->getMessage();
        }

        throw $this->errorResult(sprintf('Registry Error: %s', $errorMessage), $data, $debug, $exception);
    }

    protected function connect(): EppConnection
    {
        try {
            if (!isset($this->connection) || !$this->connection->isConnected() || !$this->connection->isLoggedin()) {
                $connection = new EppConnection(!!$this->configuration->debug);
                $connection->setPsrLogger($this->getLogger());

                // Set connection data
                $connection->setHostname($this->resolveAPIURL());
                $connection->setPort($this->resolveAPIPort());
                $connection->setUsername($this->configuration->username);
                $connection->setPassword($this->configuration->password);

                $connection->login();

                return $this->connection = $connection;
            }

            return $this->connection;
        } catch (eppException $e) {
            switch ($e->getCode()) {
                case 2200:
                case 2001:
                    $errorMessage = 'Authentication error; check credentials';
                    break;
                default:
                    $errorMessage = 'Unexpected provider connection error';
            }

            throw $this->errorResult(trim(sprintf('%s %s', $e->getCode() ?: null, $errorMessage)), [], [], $e);
        } catch (ErrorException $e) {
            throw $this->errorResult('Unexpected provider connection error', [], [], $e);
        }
    }

    private function epp(): EppHelper
    {
        if (isset($this->epp)) {
            return $this->epp;
        }

        $this->connect();

        return $this->epp ??= new EppHelper($this->connection, $this->configuration);
    }

    private function resolveAPIURL(): string
    {
        return $this->configuration->sandbox
            ? 'ssl://epp-ote.rrpproxy.net'
            : 'ssl://epp.rrpproxy.net';
    }

    private function resolveAPIPort(): int
    {
        return $this->configuration->sandbox
            ? 1700
            : 700;
    }

    protected function api(): CentralNicResellerApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        return $this->api ??= new CentralNicResellerApi($this->configuration, $this->getLogger());
    }
}
