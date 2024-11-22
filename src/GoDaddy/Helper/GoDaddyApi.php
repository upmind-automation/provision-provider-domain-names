<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\GoDaddy\Helper;

use GuzzleHttp\Client;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\GoDaddy\Data\Configuration;

/**
 * GoDaddy Domains API client.
 */
class GoDaddyApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'Registrant';
    public const CONTACT_TYPE_TECH = 'Tech';
    public const CONTACT_TYPE_ADMIN = 'Admin';
    public const CONTACT_TYPE_BILLING = 'Billing';

    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @param string[] $domainList
     *
     * @return DacDomain[]
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function checkMultipleDomains(array $domainList): array
    {
        $response = $this->makeRequest('/v1/domains/available', null, $domainList, 'POST');

        $dacDomains = [];

        foreach ($response['domains'] ?? [] as $result) {
            $available = boolval($result['available']);

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

        foreach ($response['errors'] ?? [] as $result) {
            $dacDomains[] = DacDomain::create([
                'domain' => $result['domain'],
                'description' => sprintf('[%s] %s', $result['code'], $result['message']),
                'tld' => Utils::getTld($result['domain']),
                'can_register' => false,
                'can_transfer' => false,
                'is_premium' => false,
            ]);
        }

        return $dacDomains;
    }

    /**
     * @param ContactParams[] $contacts
     * @param string[] $nameServers
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(string $domainName, int $years, array $contacts, array $nameServers): void
    {
        $command = "/v1/domains/purchase";

        $body = [
            'domain' => $domainName,
            'nameServers' => $nameServers,
            'period' => $years,
        ];

        $consent = [
            'agreedAt' => date('Y-m-d\TH:i:s\Z'),
            'agreedBy' => $contacts[self::CONTACT_TYPE_REGISTRANT]['name'],
            'agreementKeys' => [$this->getAgreementKey(Utils::getTld($domainName))],
        ];

        $body['consent'] = $consent;

        foreach ($contacts as $type => $contact) {
            $contactParams = $this->setContactParams($contact, $type);
            $body = array_merge($body, $contactParams);
        }

        $this->makeRequest($command, null, $body, "POST");
    }

    /**
     * @param array<string,ContactParams|null> $contacts
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function initiateTransfer(string $domainName, string $eppCode, array $contacts, int $period): string
    {
        $command = "/v1/domains/{$domainName}/transfer";

        $body = [
            'authCode' => $eppCode,
            'period' => $period,
        ];

        $consent = [
            'agreedAt' => date('Y-m-d\TH:i:s\Z'),
            'agreedBy' => $contacts[self::CONTACT_TYPE_REGISTRANT]['name'],
            'agreementKeys' => ["DNTA"],
        ];

        $body['consent'] = $consent;

        foreach ($contacts as $type => $contact) {
            if ($contact !== null) {
                $contactParams = $this->setContactParams($contact, $type);
                $body = array_merge($body, $contactParams);
            }
        }

        $response = $this->makeRequest($command, null, $body, "POST");

        return (string)$response['orderId'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getAgreementKey(string $tld)
    {
        $command = "/v1/domains/agreements";

        $params = ['tlds' => [$tld]];

        $response = $this->makeRequest($command, $params);

        return $response[0]['agreementKey'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainInfo(string $domainName): array
    {
        $command = "/v1/domains/{$domainName}";
        $response = $this->makeRequest($command);

        return [
            'id' => (string)$response['domainId'],
            'domain' => (string)$response['domain'],
            'statuses' => [$response['status']],
            'locked' => $response['locked'],
            'registrant' => isset($response['contactRegistrant'])
                ? $this->parseContact($response['contactRegistrant'])
                : null,
            'billing' => isset($response['contactBilling'])
                ? $this->parseContact($response['contactBilling'])
                : null,
            'tech' => isset($response['contactTech'])
                ? $this->parseContact($response['contactTech'])
                : null,
            'admin' => isset($response['contactAdmin'])
                ? $this->parseContact($response['contactAdmin'])
                : null,
            'ns' => NameserversResult::create($this->parseNameservers($response['nameServers'])),
            'created_at' => Utils::formatDate((string)$response['createdAt']),
            'updated_at' => null,
            'expires_at' => isset($response['expires']) ? Utils::formatDate($response['expires']) : null,
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(string $domainName, int $period): void
    {
        $command = "/v1/domains/{$domainName}/renew";

        $body = ['period' => $period];

        $this->makeRequest($command, null, $body, "POST");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainEppCode(string $domainName): string
    {
        $command = "/v1/domains/{$domainName}";
        $response = $this->makeRequest($command);

        return $response['authCode'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRenewalMode(string $domainName, bool $autoRenew): void
    {
        $command = "/v1/domains/{$domainName}";
        $body = ['renewAuto' => $autoRenew];

        $this->makeRequest($command, null, $body, "PATCH");
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): ContactData
    {
        $command = "/v1/domains/{$domainName}/contacts";

        $registrantParams = $this->setContactParams($contactParams, self::CONTACT_TYPE_REGISTRANT);

        $this->makeRequest($command, null, $registrantParams, "PATCH");

        return $this->getDomainInfo($domainName)['registrant'];
    }

    /**
     * @param string[] $nameservers
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $command = "/v1/domains/{$domainName}";

        $body = ['nameServers' => $nameservers];

        $this->makeRequest($command, null, $body, "PATCH");

        $response = $this->makeRequest($command);

        return $this->parseNameservers($response['nameServers']);
    }

    private function getNameParts(?string $name): array
    {
        $nameParts = explode(" ", $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(" ", $nameParts);

        return compact('firstName', 'lastName');
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    private function setContactParams(ContactParams $contactParams, string $type): array
    {
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        return [
            "contact{$type}" => [
                'addressMailing' => [
                    'address1' => $contactParams->address1,
                    'city' => $contactParams->city,
                    'country' => Utils::normalizeCountryCode($contactParams->country_code),
                    'postalCode' => $contactParams->postcode,
                    'state' => $contactParams->state ?: '',
                ],
                'organization' => $contactParams->organisation ?: '',
                'nameFirst' => $nameParts['firstName'],
                'nameLast' => $nameParts['lastName'] ?: $nameParts['firstName'],
                'email' => $contactParams->email,
                'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
            ]
        ];
    }

    private function parseContact(array $contact): ContactData
    {
        return ContactData::create([
            'organisation' => (string)$contact['organization'] ?: null,
            'name' => $contact['nameFirst'] . " " . $contact['nameLast'],
            'address1' => (string)$contact['addressMailing']['address1'],
            'city' => (string)$contact['addressMailing']['city'],
            'state' => (string)$contact['addressMailing']['state'] ?: null,
            'postcode' => (string)$contact['addressMailing']['postalCode'],
            'country_code' => Utils::normalizeCountryCode((string)$contact['addressMailing']['country']),
            'email' => (string)$contact['email'],
            'phone' => (string)$contact['phone'],
        ]);
    }

    private function parseNameservers(array $nameservers): array
    {
        $result = [];
        $i = 1;

        foreach ($nameservers as $ns) {
            $result['ns' . $i] = ['host' => (string)$ns];
            $i++;
        }

        return $result;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(string $command, ?array $params = null, ?array $body = null, ?string $method = 'GET'): ?array
    {
        $requestParams = [];

        if ($params) {
            $requestParams['query'] = $params;
        }

        if ($body) {
            $requestParams['body'] = json_encode($body);
        }

        $response = $this->client->request($method, $command, $requestParams);
        $result = $response->getBody()->getContents();

        $response->getBody()->close();

        if ($result === "") {
            return null;
        }

        return $this->parseResponseData($result);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getRegistrarLockStatus(string $domainName): bool
    {
        $command = "/v1/domains/{$domainName}";
        $response = $this->makeRequest($command);

        return (bool)$response['locked'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $command = "/v1/domains/{$domainName}";
        $body = ['locked' => $lock];

        $this->makeRequest($command, null, $body, "PATCH");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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
        $statusCode = $responseData['code'] ?? 'unknown';
        if (($statusCode === "NOT_FOUND") && isset($responseData['message'])) {
            $errorMessage = $responseData['message'];
        }

        return $errorMessage ?? null;
    }
}
