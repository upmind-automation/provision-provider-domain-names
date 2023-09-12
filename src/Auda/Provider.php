<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Auda;

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
use Upmind\ProvisionProviders\DomainNames\Auda\Helper\EppHelper;
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
use Upmind\ProvisionProviders\DomainNames\Auda\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Auda\EppExtension\EppConnection;

/**
 * Auda provider.
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
            ->setName('Auda')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/auda-logo.png')
            ->setDescription(
                'Register, transfer, renew and manage Auda domains'
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
            throw $this->errorResult('This domain is not available to register');
        }

        $contactParams = [
            'registrant' => $params->registrant,
            'admin' => $params->admin,
            'tech' => $params->tech,
            'billing' => $params->billing,
        ];

        try {
            $contactsId = $this->getContactsId($contactParams);

            $this->epp()->register(
                $domainName,
                intval($params->renew_years),
                $params->nameservers->pluckHosts(),
                $contactsId,
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
        }
    }

    /**
     * @return array<string,int>|string[]
     */
    private function getContactsId(array $params): array
    {
        if (Arr::has($params, 'billing.id')) {
            $billingId = $params['billing']['id'];

            if (!$this->epp()->getContactInfo($billingId)) {
                throw $this->errorResult("Invalid billing ID provided!", $params);
            }
        } else if (Arr::has($params, 'billing.register')) {
            $billingId = $this->epp()->createContact($params['billing']['register'], 'billing');
        }

        if (Arr::has($params, 'tech.id')) {
            $techId = $params['tech']['id'];

            if (!$this->epp()->getContactInfo($techId)) {
                throw $this->errorResult("Invalid tech ID provided!", $params);
            }
        } else if (Arr::has($params, 'tech.register')) {
            $techId = $this->epp()->createContact($params['tech']['register'], 'tech');
        }

        if (Arr::has($params, 'registrant.id')) {
            $registrantId = $params['registrant']['id'];

            if (!$this->epp()->getContactInfo($registrantId)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }
        } else if (Arr::has($params, 'registrant.register')) {
            $registrantId = $this->epp()->createContact($params['registrant']['register'], 'registrant');
        }

        if (Arr::has($params, 'admin.id')) {
            $adminId = $params['admin']['id'];

            if (!$this->epp()->getContactInfo($adminId)) {
                throw $this->errorResult("Invalid admin ID provided!", $params);
            }
        } else if (Arr::has($params, 'admin.register')) {
            $adminId = $this->epp()->createContact($params['admin']['register'], 'admin');
        }

        return array(
            'registrant' => $registrantId ?? null,
            'admin' => $adminId ?? null,
            'tech' => $techId ?? null,
            'billing' => $billingId ?? null,
        );
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
        } catch (\Throwable $e) {
            // initiate transfer ...
        }

        $contactParams = [
            'registrant' => $params->registrant,
            'admin' => $params->admin,
            'tech' => $params->tech,
            'billing' => $params->billing,
        ];

        try {
            $contactsId = $this->getContactsId($contactParams);

            $transferId = $this->epp()->initiateTransfer(
                $domainName,
                intval($params->renew_years),
                $eppCode,
                $contactsId
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

        $contactParams = [
            'registrant' => $params->registrant,
            'admin' => $params->admin,
            'tech' => $params->tech,
            'billing' => $params->billing,
        ];

        try {
            $contactsId = $this->getContactsId($contactParams);

            $transferId = $this->epp()->initiateTransfer(
                $domainName,
                intval($params->renew_years),
                $eppCode,
                $contactsId
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
            $this->_eppExceptionHandler($e);
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
            $this->_eppExceptionHandler($e);
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
            $this->_eppExceptionHandler($e);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);

        $domainName = Utils::getDomain($sld, $tld);

        try {
            $this->epp()->updateNameservers($domainName, $params->pluckHosts());

            $result = collect($params->pluckHosts())
                ->mapWithKeys(fn($ns, $i) => ['ns' . ($i + 1) => ['host' => $ns]]);

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
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
            $this->_eppExceptionHandler($e);
        }
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
            $eppCode = $this->epp()->getDomainEppCode($domainName);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported');
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
                $connection->setPort(700);
                $connection->setUsername($this->configuration->username);
                $connection->setPassword($this->configuration->password);

                $cert = $this->configuration->certificate
                    ? $this->getCertificatePath($this->configuration->certificate)
                    : __DIR__ . '/cert.pem';

                $connection->enableCertification($cert, null);

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

    /**
     * Returns a filesystem path to use for the given certificate PEM, creating
     * the file if necessary.
     *
     * @param string|null $certificate Certificate PEM
     *
     * @return string|null Filesystem path to the certificate
     */
    protected function getCertificatePath(?string $certificate): ?string
    {
        if (empty($certificate)) {
            return null;
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sha1($certificate);

        if (!file_exists($path)) {
            file_put_contents($path, $certificate, LOCK_EX);
        }

        return $path;
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
            ? 'ssl://ote1.auda.ltd'
            : 'ssl://auda.ltd';
    }
}
