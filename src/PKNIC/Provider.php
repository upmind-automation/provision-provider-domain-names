<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\PKNIC;

use Carbon\Carbon;
use GuzzleHttp\Client;
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
use Upmind\ProvisionProviders\DomainNames\PKNIC\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\PKNIC\Helper\PKNICApi;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

/**
 * PKNIC provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    protected PKNICApi $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('PKNIC')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/pknic-logo.png')
            ->setDescription('Register, transfer, renew and manage PKNIC domains');
    }


    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Operation not supported');
    }


    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        throw $this->errorResult('Operation not supported');
    }


    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $contactParams = [
            'registrant' => $params->registrant,
            'tech' => $params->tech,
            'billing' => $params->billing,
        ];

        try {
            $contacts = $this->getRegisterParams($contactParams);

            $this->api()->register(
                $domainName,
                $contacts,
                $params->nameservers->pluckHosts(),
            );

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
        if (!Arr::has($params, 'registrant.register')) {
                throw $this->errorResult('Registrant contact data is required!');
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
                $params['tech']['register'], 'tech'
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
                $params['billing']['register'], 'billing'
            );
        }

        return [
            PKNICApi::CONTACT_TYPE_REGISTRANT => $params['registrant']['register'],
            PKNICApi::CONTACT_TYPE_TECH => $techID,
            PKNICApi::CONTACT_TYPE_BILLING => $billingID,
        ];
    }

    public function transfer(TransferParams $params): DomainResult
    {
        throw $this->errorResult('Operation not supported');
    }


    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->renew($domainName);
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
        throw $this->errorResult('Operation not supported');
    }


    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $result= $this->api()->updateNameservers(
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
        throw $this->errorResult('Operation not supported');
    }


    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        throw $this->errorResult('Operation not supported');
    }


    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->getDomainInfo($domainName);

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
     * @throws Throwable
     */
    protected function handleException(Throwable $e, $params = null): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();

                $body = trim($response->getBody()->__toString());
                $responseData = json_decode($body, true);

                $errorMessage = $responseData['message'] ?? 'unknown error';

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

    protected function api(): PKNICApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $credentials = base64_encode("{$this->configuration->username}:{$this->configuration->api_token}");

        $client = new Client([
            'base_uri' => $this->resolveAPIURL(),
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/PKNIC',
                'Authorization' => ['Basic ' . $credentials],
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
        ]);

        return $this->api = new PKNICApi($client, $this->configuration);
    }

    /**
     * @return string
     */
    private function resolveAPIURL(): string
    {
        if (!$this->configuration->sandbox && $this->configuration->api_url == null) {
            throw $this->errorResult('API url is not set');
        }

        return $this->configuration->sandbox
            ? 'https://wmz5-01.pknic.net.pk:8006'
            : ($this->configuration->api_url);
    }
}
