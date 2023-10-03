<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OVHDomains;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
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
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\OVHDomains\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\OVHDomains\Helper\OVHDomainsApi;

/**
 * OVH Domains provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    /**
     * @var OVHDomainsApi
     */
    protected OVHDomainsApi $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('OVHDomains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/ovh-domains-logo.png')
            ->setDescription('Register, transfer, renew and manage OVH domains');
    }

    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @throws Throwable
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);

        $domains = array_map(
            fn($tld) => $sld . '.' . Utils::normalizeTld($tld),
            $params->tlds
        );

        try {
            $dacDomains = $this->api()->checkMultipleDomainsAsync($domains);

            return DacResult::create([
                'domains' => $dacDomains,
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws Throwable
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $this->checkRegisterParams($params);

        $checkResult = $this->api()->checkMultipleDomains([$domainName]);

        if (count($checkResult) < 1) {
            throw $this->errorResult('Empty domain availability check result');
        }

        if (!$checkResult[0]->can_register) {
            throw $this->errorResult('This domain is not available to register');
        }

        $contacts = [
            OVHDomainsApi::CONTACT_TYPE_REGISTRANT => $params->registrant,
            OVHDomainsApi::CONTACT_TYPE_ADMIN => $params->admin,
            OVHDomainsApi::CONTACT_TYPE_TECH => $params->tech,
            OVHDomainsApi::CONTACT_TYPE_BILLING => $params->billing,
        ];

        try {
            $url = $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contacts,
            );

            throw $this->errorResult(
                sprintf('Order for %s domain successfully created!', $domainName),
                ['url' => $url]
            );
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    private function checkRegisterParams(RegisterDomainParams $params): void
    {
        if (!Arr::has($params, 'tech.id')) {
            throw $this->errorResult('Tech contact ID is required!');
        }

        if (!Arr::has($params, 'admin.id')) {
            throw $this->errorResult('Admin contact ID is required!');
        }

        if (!Arr::has($params, 'billing.id')) {
            throw $this->errorResult('Billing contact ID is required!');
        }
    }

    /**
     * @param TransferParams $params
     *
     * @return DomainResult
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '';

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        $contacts = array_filter([
            OVHDomainsApi::CONTACT_TYPE_REGISTRANT => $params->registrant,
            OVHDomainsApi::CONTACT_TYPE_ADMIN => $params->admin,
            OVHDomainsApi::CONTACT_TYPE_TECH => $params->tech,
            OVHDomainsApi::CONTACT_TYPE_BILLING => $params->billing,
        ]);

        try {
            $url = $this->api()->initiateTransfer(
                $domainName,
                $eppCode,
                $contacts,
                intval($params->renew_years)
            );

            throw $this->errorResult(
                sprintf('Transfer for %s domain successfully created!', $domainName),
                ['url' => $url]
            );
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @param  TransferParams  $params
     *
     * @return InitiateTransferResult
     */
    public function initiateTransfer(TransferParams $params): InitiateTransferResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '';

        try {
            $domain = $this->_getInfo($domainName, '');

            return InitiateTransferResult::create([
                'domain' => $domainName,
                'transfer_status' => 'complete',
                'domain_info' => $domain,
            ])->setMessage('Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        $contacts = array_filter([
            OVHDomainsApi::CONTACT_TYPE_REGISTRANT => $params->registrant,
            OVHDomainsApi::CONTACT_TYPE_ADMIN => $params->admin,
            OVHDomainsApi::CONTACT_TYPE_TECH => $params->tech,
            OVHDomainsApi::CONTACT_TYPE_BILLING => $params->billing,
        ]);

        try {
            $url = $this->api()->initiateTransfer(
                $domainName,
                $eppCode,
                $contacts,
                intval($params->renew_years)
            );

            return InitiateTransferResult::create([
                'domain' => $domainName,
                'transfer_status' => 'in_progress',
                'transfer_order_id' => $url
            ])->setMessage(sprintf('Transfer for %s domain successfully created!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function finishTransfer(FinishTransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        if (!$params->transfer_order_id) {
            throw $this->errorResult('Transfer order ID is required!');
        }

        try {
            $info = $this->api()->getTransferInfo($params->transfer_order_id);

            if ($info) {
                throw $this->errorResult(
                    sprintf('Domain transfer in progress'),
                    [],
                    $params
                );
            } else {
                throw $this->errorResult(
                    sprintf('Transfer order does not exist'),
                    [],
                    $params
                );
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $period = intval($params->renew_years);

        try {
            $url = $this->api()->renew($domainName, $period);

            throw $this->errorResult(
                sprintf('Order for renewing %s domain successfully created!', $domainName),
                ['url' => $url]
            );
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
            return $this->api()->updateRegistrantContact($domainName, $params->contact);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $hosts = [];
        foreach ($params->pluckHosts() as $nameserver) {
            $hosts[] = ['host' => $nameserver];
        }

        try {
            $result = $this->api()->updateNameservers(
                $domainName,
                $hosts,
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
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @return no-return
     * @throws Throwable If error is completely unexpected
     *
     * @throws ProvisionFunctionError
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $httpCode = $response->getStatusCode();
                $body = trim($response->getBody()->__toString());
                $responseData = json_decode($body, true);

                $errorMessage = $responseData['message'] ?? 'unknown error';

                throw $this->errorResult(
                    sprintf('Provider API Error [%s]: %s', $httpCode ?? 'unknown', $errorMessage),
                    ['response_data' => $responseData],
                    [],
                    $e
                );
            }
        }

        // totally unexpected error - re-throw and let provision system handle
        throw $e;
    }

    protected function api(): OVHDomainsApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        return $this->api = new OVHDomainsApi($this->configuration);
    }
}
