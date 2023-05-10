<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNicReseller;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppContactHandle;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper\CentralNicResellerApi;
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
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\EppExtension\EppConnection;

/**
 * CentralNicReseller provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    protected EppConnection $client;

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
            ->setName('CentralNicReseller Provider')
            ->setDescription('Register, transfer, renew and manage CentralNicReseller domains');
    }

    public function poll(PollParams $params): PollResult
    {
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;

        try {
            $poll = $this->api()->poll(intval($params->limit), $since);

            return PollResult::create($poll);
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $domains = array_map(
            fn($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        try {
            $dacDomains = $this->api()->checkMultipleDomains($domains);

            return DacResult::create([
                'domains' => $dacDomains,
            ]);
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $checkResult = $this->api()->checkMultipleDomains([$domainName]);

        if (count($checkResult) < 1) {
            throw $this->errorResult('Empty domain availability check result');
        }

        if (!$checkResult[0]->can_register) {
            throw $this->errorResult('This domain is not available to register');
        }

        $contacts = $this->getRegisterParams($params);

        $nameServers = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $nameServers[] = Arr::get($params, 'nameservers.ns' . $i);
            }
        }

        try {
            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contacts,
                $nameServers,
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    private function getRegisterParams(RegisterDomainParams $params): array
    {
        if (Arr::has($params, 'registrant.id')) {
            $registrantID = $params->registrant->id;

            if (!$this->api()->getContactInfo($registrantID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }

        } else {
            if (!Arr::has($params, 'registrant.register')) {
                throw $this->errorResult('Registrant contact data is required!');
            }

            $registrantID = $this->api()->createContact(
                $params->registrant->register,
            );
        }

        if (Arr::has($params, 'admin.id')) {
            $adminID = $params->admin->id;

            if (!$this->api()->getContactInfo($adminID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }

        } else {
            if (!Arr::has($params, 'admin.register')) {
                throw $this->errorResult('Admin contact data is required!');
            }

            $adminID = $this->api()->createContact(
                $params->admin->register,
            );
        }

        if (Arr::has($params, 'tech.id')) {
            $techID = $params->tech->id;

            if (!$this->api()->getContactInfo($techID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }
        } else {
            if (!Arr::has($params, 'tech.register')) {
                throw $this->errorResult('Tech contact data is required!');
            }

            $techID = $this->api()->createContact(
                $params->tech->register,
            );
        }


        if (Arr::has($params, 'billing.id')) {
            $billingID = $params->billing->id;

            if (!$this->api()->getContactInfo($billingID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }
        } else {
            if (!Arr::has($params, 'billing.register')) {
                throw $this->errorResult('Billing contact data is required!');
            }

            $billingID = $this->api()->createContact(
                $params->billing->register,
            );
        }

        return [
            eppContactHandle::CONTACT_TYPE_REGISTRANT => $registrantID,
            eppContactHandle::CONTACT_TYPE_ADMIN => $adminID,
            eppContactHandle::CONTACT_TYPE_TECH => $techID,
            eppContactHandle::CONTACT_TYPE_BILLING => $billingID,
        ];
    }

    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        $eppCode = $params->epp_code ?: null;

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (eppException $e) {

        }

        try {
            $transferId = $this->api()->initiateTransfer($domainName, $eppCode, intval($params->renew_years));

            throw $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), ['transfer_id' => $transferId]);

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
            $this->api()->renew($domainName, $period);

            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params->toArray());
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
            return $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    public function _getInfo(string $domain, $msg = 'Domain data obtained'): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domain);

        return DomainResult::create($domainInfo, false)->setMessage($msg);
    }

    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            $contact = $this->api()->updateRegistrantContact($domainName, $params->contact);

            return ContactResult::create($contact);
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params->toArray());
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

            $this->api()->updateNameServers($domainName, $nameServers);

            $hosts = $this->api()->getHosts($domainName);

            $returnNameservers = [];
            foreach ($hosts as $i => $ns) {
                $returnNameservers['ns' . ($i + 1)] = $ns->getHostname();
            }

            return NameserversResult::create($returnNameservers)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params->toArray());
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
            $currentLockStatuses = $this->api()->getRegistrarLockStatuses($domainName);
            $lockedStatuses = $this->api()->getLockedStatuses();

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

            $this->api()->setRegistrarLock($domainName, $addStatuses, $removeStatuses);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));

        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params->toArray());
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
            $this->api()->setRenewalMode($domainName, $autoRenew);

            return $this->_getInfo($domainName, 'Auto-renew mode updated');
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            $eppCode = $this->api()->getDomainEppCode($domainName);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Not implemented');
    }

    private function _eppExceptionHandler(eppException $exception, array $data = [], array $debug = []): void
    {
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

    private function api(): CentralNicResellerApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $this->connect();

        return $this->api ??= new CentralNicResellerApi($this->connection, $this->configuration);
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
}
