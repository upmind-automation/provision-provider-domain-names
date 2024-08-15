<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\SynergyWholesale;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use SoapClient;
use SoapFault;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Helper\SynergyWholesaleApi;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected Configuration $configuration;


    /**
     * @var SynergyWholesaleApi
     */
    protected SynergyWholesaleApi $api;


    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Synergy Wholesale')
            ->setDescription('Register, transfer, renew and manage domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/synergy-wholesale-logo.png');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);

        $domains = array_map(
            fn($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        $dacDomains = $this->api()->checkMultipleDomains($domains);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Operation not supported');
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        if (!Arr::has($params, 'registrant.register')) {
            throw $this->errorResult('Registrant contact data is required!');
        }

        $contacts = [
            SynergyWholesaleApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
            SynergyWholesaleApi::CONTACT_TYPE_ADMIN => $params->admin->register,
            SynergyWholesaleApi::CONTACT_TYPE_TECH => $params->tech->register,
            SynergyWholesaleApi::CONTACT_TYPE_BILLING => $params->billing->register,
        ];

        try {
            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contacts,
                $params->nameservers->pluckHosts(),
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }


    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '0000';

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        if (!Arr::has($params, 'registrant.register')) {
            throw $this->errorResult('Registrant contact data is required!');
        }

        try {
            $this->api()->initiateTransfer(
                $domainName,
                $eppCode,
                $params->registrant->register,
            );

            throw $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), [
                'transaction_id' => null
            ]);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $period = intval($params->renew_years);

        try {
            $this->api()->renew($domainName, $period);
            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $contact = $this->api()->updateRegistrantContact($domainName, $params->contact);

            return ContactResult::create($contact);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $result = $this->api()->updateNameservers(
                $domainName,
                $params->pluckHosts(),
            );

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            $currentLockStatus = $this->api()->getRegistrarLockStatus($domainName);
            if (!$lock && !$currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->api()->setRegistrarLock($domainName, $lock);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $autoRenew = !!$params->auto_renew;

        try {
            $this->api()->setRenewalMode($domainName, $autoRenew);

            return $this->_getInfo($domainName, 'Auto-renew mode updated');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $eppCode = $this->api()->getDomainEppCode($domainName);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @throws Throwable
     *
     * @return no-return
     * @return never
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof SoapFault) {
            $errorMessage = sprintf('Provider API Soap Error [%s]', $e->faultcode);
            $errorData = [
                'error' => [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'soap_fault' => [
                        'code' => $e->faultcode,
                        'string' => $e->faultstring,
                        'detail' => $e->detail,
                        'actor' => $e->faultactor,
                        'headerfault' => $e->headerfault,
                    ],
                ],
            ];

            if (Str::contains($e->getMessage(), 'Parsing WSDL')) {
                $errorMessage = 'Provider API Soap Connection Error';
            }

            $this->errorResult($errorMessage, $errorData, [], $e);
        }

        throw $e;
    }

    protected function api(): SynergyWholesaleApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        try {
            $client = new SoapClient("https://api.synergywholesale.com/?wsdl");

            return $this->api = new SynergyWholesaleApi($client, $this->configuration, $this->getLogger());
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }
}
