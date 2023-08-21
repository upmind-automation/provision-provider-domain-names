<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\GoDaddy;

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
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\GoDaddy\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\GoDaddy\Helper\GoDaddyApi;

/**
 * GoDaddy provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    /**
     * @var GoDaddyApi
     */
    protected GoDaddyApi $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('GoDaddy')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/godaddy-logo.png')
            ->setDescription('Register, transfer, renew and manage GoDaddy domains');
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
            fn ($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        $dacDomains = $this->api()->checkMultipleDomains($domains);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
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
            GoDaddyApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
            GoDaddyApi::CONTACT_TYPE_ADMIN => $params->admin->register,
            GoDaddyApi::CONTACT_TYPE_TECH => $params->tech->register,
            GoDaddyApi::CONTACT_TYPE_BILLING => $params->billing->register,
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
            $this->handleException($e, $params);
        }
    }

    private function checkRegisterParams(RegisterDomainParams $params): void
    {
        if (!Arr::has($params, 'registrant.register')) {
            throw $this->errorResult('Registrant contact data is required!');
        }

        if (!Arr::has($params, 'tech.register')) {
            throw $this->errorResult('Tech contact data is required!');
        }

        if (!Arr::has($params, 'admin.register')) {
            throw $this->errorResult('Admin contact data is required!');
        }

        if (!Arr::has($params, 'billing.register')) {
            throw $this->errorResult('Billing contact data is required!');
        }
    }

    public function transfer(TransferParams $params): DomainResult
    {
        throw $this->errorResult('Operation not supported');
    }

    public function initiateTransfer(TransferParams $params): InitiateTransferResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '0000';

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

        if (!Arr::has($params, 'registrant.register')) {
            return $this->errorResult('Registrant contact data is required!');
        }

        $contacts = array_filter([
            GoDaddyApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register ?? null,
            GoDaddyApi::CONTACT_TYPE_ADMIN => $params->admin->register ?? null,
            GoDaddyApi::CONTACT_TYPE_TECH => $params->tech->register ?? null,
            GoDaddyApi::CONTACT_TYPE_BILLING => $params->billing->register ?? null,
        ]);

        try {
            $transferId = $this->api()->initiateTransfer(
                $domainName,
                $eppCode,
                $contacts,
                intval($params->renew_years)
            );

            return InitiateTransferResult::create([
                'domain' => $domainName,
                'transfer_status' => 'in_progress',
                'transfer_order_id' => $transferId
            ])->setMessage(sprintf('Transfer for %s domain successfully created!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function finishTransfer(FinishTransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (\Throwable $e) {
            // continue on to initiate transfer
        }

        try {
            $status = $this->api()->getDomainStatus($domainName);

            throw $this->errorResult(
                sprintf('Transfer order status for %s: %s', $domainName, $status),
                [],
                $params
            );
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
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
            $this->handleException($e, $params);
        }
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
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
            $this->handleException($e, $params);
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
            $this->handleException($e, $params);
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
            $this->handleException($e, $params);
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
            $this->handleException($e, $params);
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
            $this->handleException($e, $params);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @return no-return
     * @throws ProvisionFunctionError
     */
    protected function handleException(Throwable $e, $params = null): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();

                $body = trim($response->getBody()->__toString());
                $responseData = json_decode($body, true);

                $errorMessage = $responseData['message'] ?? 'unknown error';
                if (Str::startsWith($errorMessage, "Request body doesn't fulfill schema")) {
                    $errorMessage = 'Invalid request data';
                }

                throw $this->errorResult(
                    sprintf('Provider API Error [%s]: %s', $responseData['code'] ?? 'unknown', $errorMessage),
                    ['response_data' => $responseData],
                    [],
                    $e
                );
            }
        }

        throw $e;
    }

    protected function api(): GoDaddyApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->resolveAPIURL(),
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/GoDaddy',
                'Authorization' => "sso-key {$this->configuration->api_key}:{$this->configuration->api_secret}",
                'Content-Type' => 'application/json',
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
        ]);

        return $this->api = new GoDaddyApi($client, $this->configuration);
    }

    private function resolveAPIURL(): string
    {
        return $this->configuration->sandbox
            ? 'https://api.ote-godaddy.com'
            : 'https://api.godaddy.com';
    }
}
