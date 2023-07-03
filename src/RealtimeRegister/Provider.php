<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\RealtimeRegister;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Throwable;
use Illuminate\Support\Arr;
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
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Helper\RealtimeRegisterApi;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

/**
 * Realtime Register provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    private const MAX_CUSTOM_NAMESERVERS = 5;

    protected RealtimeRegisterApi $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Realtime Register')
            ->setDescription('Registering, hosting, and managing Realtime Register domains');
    }

    public function poll(PollParams $params): PollResult
    {
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;
        try {
            $poll = $this->api()->poll(intval($params->limit), $since);
            return PollResult::create($poll);
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
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

    /**
     * @throws Throwable
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);
        $domainName = Utils::getDomain($sld, $tld);

        $checkResult = $this->api()->checkMultipleDomains([$domainName]);

        if (count($checkResult) < 1) {
            throw $this->errorResult('Empty domain availability check result');
        }

        if (!$checkResult[0]->can_register) {
            throw $this->errorResult('This domain is not available to register');
        }

        $contactParams = [
            'registrant' => $params->registrant,
            'tech' => $params->tech,
            'admin' => $params->admin,
            'billing' => $params->billing,
        ];

        $contacts = $this->getRegisterParams($contactParams);

        // $nameServers = [];
        // for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
        //     if (Arr::has($params, 'nameservers.ns' . $i)) {
        //         $host = strtolower(Arr::get($params, 'nameservers.ns' . $i)['host']);
        //         $ip = Arr::get($params, 'nameservers.ns' . $i)['ip'] ?? Utils::lookupIpAddress($host);

        //         $nameServers[] = [
        //             'host' => $host,
        //             'ip' => $ip
        //         ];

        //         try {
        //             $this->api()->createHost($host, $ip);
        //         } catch (RequestException $e) {
        //             if ($this->getRequestExceptionMessage($e) !== 'Hosts can only be created subordinate to a domain in your account') {
        //                 $this->handleException($e, $params);
        //             }
        //         } catch (\Throwable $e) {
        //             $this->handleException($e, $params);
        //         }
        //     }
        // }

        try {
            $this->api()->register($domainName, $contacts, $params->nameservers->pluckHosts());
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }

        // try {
        //     $this->api()->updateNameservers($domainName, $nameServers);
        // } catch (RequestException $e) {
        //     $message = $this->getRequestExceptionMessage($e);
        // } catch (\Throwable $e) {
        //     $message = $e->getMessage();
        // }

        try {
            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @return array<string,int>|string[]
     */
    private function getRegisterParams(array $params): array
    {
        if (Arr::has($params, 'registrant.id')) {
            $registrantID = $params['registrant']['id'];

            if (!$this->api()->getContact($registrantID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }

        } else {
            if (!Arr::has($params, 'registrant.register')) {
                throw $this->errorResult('Registrant contact data is required!');
            }

            $registrantID = $this->api()->createContact(
                $params['registrant']['register'],
            );
        }

        if (Arr::has($params, 'admin.id')) {
            $adminID = $params['admin']['id'];

            if (!$this->api()->getContact($adminID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }

        } else {
            if (!Arr::has($params, 'admin.register')) {
                throw $this->errorResult('Admin contact data is required!');
            }

            $adminID = $this->api()->createContact(
                $params['admin']['register'],
            );
        }

        if (Arr::has($params, 'tech.id')) {
            $techID = $params['tech']['id'];

            if (!$this->api()->getContact($techID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }
        } else {
            if (!Arr::has($params, 'tech.register')) {
                throw $this->errorResult('Tech contact data is required!');
            }

            $techID = $this->api()->createContact(
                $params['tech']['register'],
            );
        }

        if (Arr::has($params, 'billing.id')) {
            $billingID = $params['billing']['id'];

            if (!$this->api()->getContact($billingID)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }
        } else {
            if (!Arr::has($params, 'billing.register')) {
                throw $this->errorResult('Billing contact data is required!');
            }

            $billingID = $this->api()->createContact(
                $params['billing']['register'],
            );
        }

        return [
            RealtimeRegisterApi::CONTACT_TYPE_REGISTRANT => $registrantID,
            RealtimeRegisterApi::CONTACT_TYPE_ADMIN => $adminID,
            RealtimeRegisterApi::CONTACT_TYPE_TECH => $techID,
            RealtimeRegisterApi::CONTACT_TYPE_BILLING => $billingID,
        ];
    }

    public function transfer(TransferParams $params): DomainResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);

        $domainName = Utils::getDomain($sld, $tld);

        $eppCode = $params->epp_code ?: '0000';

        $contactParams = [
            'registrant' => $params->registrant,
            'tech' => $params->tech,
            'admin' => $params->admin,
            'billing' => $params->billing,
        ];

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        try {
            $contacts = $this->getRegisterParams($contactParams);

            $transferId = $this->api()->initiateTransfer($domainName, $eppCode, $contacts);

            throw $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), ['transfer_id' => $transferId]);

        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld),
        );
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
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

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
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );

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
            $this->api()->setRegistrarLock($domainName, $lock);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            if (Str::contains($e->getMessage(), ['is prohibited'])) {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->handleException($e, $params);
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
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
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

            if (!$eppCode) {
                $eppCode = $this->api()->setAuthCode($domainName);
            }

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Not implemented');
    }

    protected function handleException(Throwable $e, $params = null): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $reason = $response->getReasonPhrase();
                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);
                $errorMessage = $responseData['message'] ?? null;

                throw $this->errorResult(
                    sprintf('Provider API error: %s', $errorMessage ?? $reason ?? null),
                    [],
                    ['response_data' => $responseData ?? null],
                    $e
                );
            }
        }

        throw $e;
    }

    /**
     * @param $e
     * @return string|null
     */
    public function getRequestExceptionMessage($e): ?string
    {
        $message = null;
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);
                $message = $responseData['message'] ?? null;
            }
        }

        return $message;
    }

    protected function api(): RealtimeRegisterApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->resolveAPIURL(),
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/RealtimeRegister',
                'Content-Type' => 'application/json',
                'Authorization' => 'ApiKey ' . $this->configuration->api_key,
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
        ]);

        return $this->api = new RealtimeRegisterApi($client, $this->configuration);
    }

    /**
     * @return string
     */
    private function resolveAPIURL(): string
    {
        return $this->configuration->sandbox
            ? 'https://api.yoursrs-ote.com'
            : 'https://api.yoursrs.com';
    }
}
