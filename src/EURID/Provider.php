<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EURID;

use Carbon\Carbon;
use ErrorException;
use Illuminate\Support\Str;
use Metaregistrar\EPP\eppException;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Data\FinishTransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\InitiateTransferResult;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\EURID\Helper\EppHelper;
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
use Upmind\ProvisionProviders\DomainNames\EURID\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\EURID\EppExtension\EppConnection;

/**
 * EURID provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    protected EppConnection $connection;

    protected EppHelper $epp;

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
            ->setName('EURID')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/eurid-logo.png')
            ->setDescription(
                'Register, transfer, renew and manage .eu domains with EURID'
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
            fn($tld) => $sld . "." . Utils::normalizeTld($tld),
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
            if (!$checkResult[0]->can_transfer) {
                throw $this->errorResult('This domain is not supported');
            }

            throw $this->errorResult('This domain is not available to register');
        }

        try {
            $contactIds = [
                'registrant' => $params->registrant->id
                    ?? $this->epp()->createContact($params->registrant->register, 'registrant'),
                'tech' => $this->configuration->tech_contact_id,
                'billing' => $this->configuration->billing_contact_id
            ];

            $this->epp()->register(
                $domainName,
                intval($params->renew_years),
                $params->nameservers->pluckHosts(),
                $contactIds,
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
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
            // initiate transfer ...
        }

        if (!$params->registrant) {
            throw $this->errorResult('Registrant contact is required!', $params);
        }

        try {
            $contactIds = [
                'registrant' => $params->registrant->id
                    ?? $this->epp()->createContact($params->registrant->register, 'registrant'),
                'tech' => $this->configuration->tech_contact_id,
                'billing' => $this->configuration->billing_contact_id
            ];

            $transferId = $this->epp()->initiateTransfer(
                $domainName,
                intval($params->renew_years),
                $eppCode,
                $contactIds
            );

            throw $this->errorResult(sprintf('Transfer for %s domain successfully initiated!', $domainName), [
                'transfer_id' => $transferId
            ]);
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
        }
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

        if (!$params->registrant) {
            throw $this->errorResult('Registrant contact is required!', $params);
        }

        try {
            $contactIds = [
                'registrant' => $params->registrant->id
                    ?? $this->epp()->createContact($params->registrant->register, 'registrant'),
                'tech' => $this->configuration->tech_contact_id,
                'billing' => $this->configuration->billing_contact_id
            ];

            $transferId = $this->epp()->initiateTransfer(
                $domainName,
                intval($params->renew_years),
                $eppCode,
                $contactIds
            );

            return InitiateTransferResult::create([
                'domain' => $domainName,
                'transfer_status' => 'in_progress',
                'transfer_order_id' => $transferId
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
        }

        try {
            $status = $this->epp()->getTransferInfo($domainName);

            throw $this->errorResult(
                sprintf('Transfer order status for %s: %s', $domainName, $status),
                [],
                $params
            );
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
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

        try {
            $nameservers = array_unique($params->pluckHosts());
            $this->epp()->updateNameservers($domainName, $nameservers);

            $result = collect($nameservers)
                ->mapWithKeys(fn($ns, $i) => ['ns' . ($i + 1) => ['host' => $ns]]);

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e);
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        throw $this->errorResult('Operation not supported');
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        throw $this->errorResult('Operation not supported');
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

        try {
            $eppCode = $this->epp()->getEppcode($domainName);

            if (!$eppCode) {
                $eppCode = $this->epp()->getEppCode($domainName, true);
            }

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
                $connection->setPort(700);
                $connection->setUsername($this->configuration->username);
                $connection->setPassword($this->configuration->password);

                $connection->login();

                return $this->connection = $connection;
            }

            return $this->connection;
        } catch (eppException $e) {
            switch ($e->getCode()) {
                case 2501:
                case 2200:
                case 2001:
                    $errorMessage = 'Authentication error; check credentials';
                    break;
                default:
                    $errorMessage = 'Unexpected provider connection error';
            }

            throw $this->errorResult(trim(sprintf('%s %s', $e->getCode() ?: null, $errorMessage)), [], [], $e);
        } catch (ErrorException $e) {
            if (Str::containsAll($e->getMessage(), ['stream_socket_client()', 'SSL'])) {
                // this usually means they've not whitelisted our IPs
                $errorMessage = 'Connection error; check whitelisted IPs';
            } else {
                $errorMessage = 'Unexpected provider connection error';
            }

            throw $this->errorResult($errorMessage, [
                'connection_error' => $e->getMessage(),
            ], [], $e);
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
            ? 'ssl://epp.tryout.registry.eu'
            : 'ssl://epp.registry.eu';
    }
}
