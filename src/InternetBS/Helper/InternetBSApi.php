<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\InternetBS\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\InternetBS\Data\Configuration;

/**
 * InternetBS Domains API client.
 */
class InternetBSApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'Registrant';
    public const CONTACT_TYPE_TECH = 'Technical';
    public const CONTACT_TYPE_ADMIN = 'Admin';
    public const CONTACT_TYPE_BILLING = 'Billing';

    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    public function getApiBaseUrl(): string
    {
        return $this->configuration->sandbox
            ? 'https://testapi.internet.bs'
            : 'https://api.internet.bs';
    }

    public function makeRequest(
        string  $command,
        ?array  $params = null,
        ?array  $body = null,
        ?string $method = 'POST'
    ): ?array
    {
        $url = sprintf('%s/%s', $this->getApiBaseUrl(), ltrim($command, '/'));

        $params = array_merge($params,
            [
                'ApiKey' => $this->configuration->api_key,
                'Password' => $this->configuration->password,
                'ResponseFormat' => 'JSON'
            ]
        );

        $requestParams = [];

        if ($params) {
            $requestParams['query'] = $params;
        }

        if ($body) {
            $requestParams['body'] = json_encode($body);
        }

        $response = $this->client->request($method, $url, $requestParams);
        $result = $response->getBody()->getContents();

        $response->getBody()->close();

        if ($result === '') {
            return null;
        }

        return $this->parseResponseData($result);
    }

    private function parseResponseData(string $result): array
    {
        $parsedResult = json_decode($result, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        if ($error = $this->getResponseErrorMessage($parsedResult)) {
            throw ProvisionFunctionError::create($error)
                ->withData([
                    'response' => $parsedResult,
                ]);
        }

        return $parsedResult;
    }

    protected function getResponseErrorMessage($responseData): ?string
    {
        if ($responseData['status'] == 'FAILURE') {
            if (isset($responseData['message'])) {
                $errorMessage = $responseData['message'];
            } elseif (isset($responseData['product']) && isset($responseData['product'][0]['message'])) {
                $errorMessage = $responseData['product'][0]['message'];
            }
        }

        return $errorMessage ?? null;
    }

    public function checkMultipleDomains(array $domains)
    {
        $dacDomains = [];

        foreach ($domains as $domain) {
            $result = $this->makeRequest('/Domain/Check', ['Domain' => $domain]);

            $available = $result['status'] == 'AVAILABLE';

            $dacDomains[] = DacDomain::create([
                'domain' => $result['domain'],
                'description' => sprintf(
                    'Domain is %s to register',
                    $available ? 'available' : 'not available'
                ),
                'tld' => Utils::getTld($result['domain']),
                'can_register' => $available,
                'can_transfer' => !$available,
                'is_premium' => false,
            ]);
        }

        return $dacDomains;
    }

    public function getDomainInfo(string $domainName): array
    {
        $response = $this->makeRequest('/Domain/Info', ['Domain' => $domainName]);

        return [
            'id' => 'N/A',
            'domain' => (string)$response['domain'],
            'statuses' => [$response['domainstatus']],
            'locked' => $response['registrarlock'] == 'ENABLED',
            'registrant' => isset($response['contacts']['registrant'])
                ? $this->parseContact($response['contacts']['registrant'])
                : null,
            'billing' => isset($response['contacts']['billing'])
                ? $this->parseContact($response['contacts']['billing'])
                : null,
            'tech' => isset($response['contacts']['technical'])
                ? $this->parseContact($response['contacts']['technical'])
                : null,
            'admin' => isset($response['contacts']['admin'])
                ? $this->parseContact($response['contacts']['admin'])
                : null,
            'ns' => NameserversResult::create($this->parseNameservers($response['nameserver'] ?? [])),
            'created_at' => $response['registrationdate'] != 'n/a'
                ? Utils::formatDate((string)$response['registrationdate'])
                : null,
            'updated_at' => null,
            'expires_at' => isset($response['expirationdate']) && $response['expirationdate'] != 'n/a'
                ? Utils::formatDate($response['expirationdate'])
                : null,
        ];
    }

    private function parseContact(array $contact): ContactData
    {
        return ContactData::create([
            'organisation' => (string)$contact['organization'] ?: null,
            'name' => $contact['firstname'] . ' ' . $contact['lastname'],
            'address1' => (string)$contact['street'],
            'city' => (string)$contact['city'],
            'state' => (string)$contact['state'] ?: null,
            'postcode' => (string)$contact['postalcode'],
            'country_code' => Utils::normalizeCountryCode((string)$contact['countrycode']),
            'email' => (string)$contact['email'],
            'phone' => (string)$contact['phonenumber'],
        ]);
    }

    private function parseNameservers(array $nameservers): array
    {
        $result = [];

        if (count($nameservers) > 0) {
            foreach ($nameservers as $i => $ns) {
                $result['ns' . ($i + 1)] = ['host' => (string)$ns];

            }
        }

        return $result;
    }

    /**
     * @param ContactParams[] $contacts
     * @param string[] $nameservers
     */
    public function register(string $domainName, int $years, array $contacts, array $nameServers): void
    {
        $params = [
            'Domain' => $domainName,
            'Ns_list' => implode(', ', $nameServers),
            'Period' => $years . 'Y',
        ];

        foreach ($contacts as $type => $contact) {
            $contactParams = $this->setContactParams($contact, $type);
            $params = array_merge($params, $contactParams);
        }

        $this->makeRequest('/Domain/Create', $params);
    }

    private function setContactParams(ContactParams $contactParams, string $type): array
    {
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        return [
            "{$type}_Street" => $contactParams->address1,
            "{$type}_City" => $contactParams->city,
            "{$type}_CountryCode" => Utils::normalizeCountryCode($contactParams->country_code),
            "{$type}_PostalCode" => $contactParams->postcode,
            "{$type}_State" => $contactParams->state ?: '',
            "{$type}_Organization" => $contactParams->organisation ?: '',
            "{$type}_FirstName" => $nameParts['firstName'],
            "{$type}_LastName" => $nameParts['lastName'] ?: $nameParts['firstName'],
            "{$type}_Email" => $contactParams->email,
            "{$type}_PhoneNumber" => Utils::internationalPhoneToEpp($contactParams->phone),
        ];
    }

    /**
     * @param string|null $name
     *
     * @return array
     */
    private function getNameParts(?string $name): array
    {
        $nameParts = explode(" ", $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(" ", $nameParts);

        return compact('firstName', 'lastName');
    }

    public function getDomainEppCode(string $domainName): ?string
    {
        $response = $this->makeRequest('/Domain/Info', ['Domain' => $domainName]);

        return $response['transferauthinfo'] ?? null;
    }

    public function setRenewalMode(string $domainName, bool $autoRenew)
    {
        $params = [
            'Domain' => $domainName,
            'AutoRenew' => $autoRenew ? 'YES' : 'NO',
        ];

        $this->makeRequest('/Domain/Update', $params);
    }

    public function getRegistrarLockStatus(string $domainName): bool
    {
        $response = $this->makeRequest('/Domain/RegistrarLock/Status', ['Domain' => $domainName]);

        return $response['registrar_lock_status'] == 'LOCKED';
    }

    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $params = [
            'Domain' => $domainName,
            'registrarLock' => $lock ? 'ENABLED' : 'DISABLED',
        ];

        $this->makeRequest('/Domain/Update', $params);
    }

    public function updateNameservers(string $domainName, array $nameServers): NameserversResult
    {
        $params = [
            'Domain' => $domainName,
            'Ns_list' => implode(', ', $nameServers),
        ];

        $this->makeRequest('/Domain/Update', $params);

        return $this->getDomainInfo($domainName)['ns'];
    }

    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): ContactData
    {
        $params = [
            'Domain' => $domainName,
        ];

        $registrantParams = $this->setContactParams($contactParams, self::CONTACT_TYPE_REGISTRANT);

        $params = array_merge($params, $registrantParams);

        $this->makeRequest('/Domain/Update', $params);

        return $this->getDomainInfo($domainName)['registrant'];
    }

    public function renew(string $domainName, int $period): void
    {
        $params = [
            'Domain' => $domainName,
            'Period' => $period . 'Y',
        ];

        $this->makeRequest('/Domain/Renew', $params);
    }

    public function initiateTransfer(string $domainName, string $eppCode, array $contacts): string
    {
        $params = [
            'Domain' => $domainName,
            'transferAuthInfo' => $eppCode,
        ];

        foreach ($contacts as $type => $contact) {
            $contactParams = $this->setContactParams($contact, $type);
            $params = array_merge($params, $contactParams);
        }

        $response = $this->makeRequest('/Domain/Transfer/Initiate', $params);

        return (string)$response['transactid'];
    }
}
