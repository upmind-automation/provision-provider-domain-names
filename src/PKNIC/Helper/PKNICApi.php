<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\PKNIC\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use GuzzleHttp\Exception\RequestException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\PKNIC\Data\Configuration;

/**
 * Class PKNIC
 *
 * @package Upmind\ProvisionProviders\DomainNames\PKNIC\Helper
 */
class PKNICApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'Registrant';
    public const CONTACT_TYPE_TECH = 'Tech';
    public const CONTACT_TYPE_BILLING = 'Billing';

    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

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
        $statusCode = $responseData['errorMessage']['message'] ?? null;
        if ($statusCode) {
            $errorMessage = $responseData['errorMessage']['detail'] ?? $statusCode;
        }

        return $errorMessage ?? null;
    }

    public function getDomainEppCode(string $domainName): string
    {
        $response = $this->makeRequest("/v0.3/domains/{$domainName}/getAuthCode");

        return $response["authCode"];
    }

    public function getDomainInfo(string $domainName): array
    {
        $response = $this->makeRequest("/v0.3/domains/{$domainName}")['domain'];

        return [
            'id' => 'N/A',
            'domain' => (string)$response['domainName'],
            'statuses' => isset($response["holdState"]) ? [$this->parseStatus($response["holdState"])] : ["active"],
            'locked' => isset($response["holdState"]),
            'registrant' => isset($response['contacts']['registrant'])
                ? $this->parseContact($response['contacts']['registrant'])
                : null,
            'billing' => isset($response['contacts']['billing'])
                ? $this->parseContact($response['contacts']['billing'])
                : null,
            'tech' => isset($response['contacts']['tech'])
                ? $this->parseContact($response['contacts']['tech'])
                : null,
            'admin' => isset($response['contacts']['admin'])
                ? ContactData::create([
                    'id' => $response['contacts']['admin']['user-id'] ?? null])
                : null,
            'ns' => NameserversResult::create($this->parseNameservers($response['nameserver'])),
            'created_at' => Utils::formatDate((string)$response['createDate']),
            'updated_at' => null,
            'expires_at' => Utils::formatDate((string)$response['expireDate']),
        ];
    }

    private function parseStatus($status): ?string
    {
        if ($status == "1") {
            return "de-activated";
        }

        if ($status == "W") {
            return "inactive";
        }

        return "active";
    }

    private function parseContact($contact): ContactData
    {
        return ContactData::create([
            'id' => $contact['id'] ?? null,
            'organisation' => (string)$contact['companyName'] ?: null,
            'name' => $contact['firstName'] . " " . $contact['lastName'],
            'address1' => (string)$contact['address1'],
            'city' => (string)$contact['city'],
            'state' => (string)$contact['state'] ?: null,
            'postcode' => (string)$contact['zip'],
            'country_code' => Utils::normalizeCountryCode((string)$contact['country']),
            'email' => (string)$contact['email'],
            'phone' => (string)$contact['phone'],
        ]);
    }

    private function parseNameservers(array $nameservers): array
    {
        $result = [];

        for ($i = 1; $i <= 4; $i++) {
            if (isset($nameservers["host{$i}"]) && $nameservers["host{$i}"] != "") {
                $result["ns{$i}"] = ['host' => (string)$nameservers["host{$i}"]];
            }
        }

        return $result;
    }

    public function getContact(string $contact): ?ContactData
    {
        try {
            $response = $this->makeRequest("/v0.3/contacts/{$contact}")['contact'];
            return $this->parseContact($response);
        } catch (RequestException $e) {
            return null;
        }
    }

    // Create a billing or tech contact
    public function createContact(ContactParams $params, string $contactType): int
    {
        $body = [
            $contactType => $this->setContactParams($params),
        ];

        $response = $this->makeRequest("/v0.3/contacts", null, $body, "POST");

        return (int)$response['createdContacts'][0]['id'];
    }

    public function createNameservers(array $ns): int
    {
        $body = [
            "nameservers" => $ns,
        ];
        $response = $this->makeRequest("/v0.3/nameservers", null, $body, "POST");

        return (int)$response['createdNameserverId'];
    }

    private function setContactParams(ContactParams $contactParams): array
    {
        $name = $contactParams->name ?: $contactParams->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'companyName' => $contactParams->organisation,
            'address1' => $contactParams->address1,
            'city' => $contactParams->city,
            'state' => $contactParams->state ?? null,
            'zip' => $contactParams->postcode,
            'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
            'email' => $contactParams->email,
            'country' => Utils::normalizeCountryCode($contactParams->country_code),
        ];
    }

    public function register(string $domainName, array $contacts, array $nameservers): void
    {
        $nsId = $this->createNameservers($nameservers);

        $registrant = $this->setContactParams($contacts[PKNICApi::CONTACT_TYPE_REGISTRANT]);

        $body = [
            "domain" => array_merge(["domainName" => $domainName], $registrant),
        ];

        $this->makeRequest("/v0.3/domains", null, $body, "POST");

        $this->setContact($domainName, $contacts[PKNICApi::CONTACT_TYPE_BILLING]);
        $this->setContact($domainName, $contacts[PKNICApi::CONTACT_TYPE_TECH]);
        $this->setNameservers($domainName, $nsId);
    }

    public function setContact(string $domainName, int $contactId): void
    {
        $body = [
            "id" => $contactId
        ];

        $this->makeRequest("/v0.3/domains/{$domainName}/setContact", null, $body, "PUT");
    }

    public function setNameservers(string $domainName, int $nsId): void
    {
        $body = [
            "id" => $nsId
        ];

        $this->makeRequest("/v0.3/domains/{$domainName}/setNameservers", null, $body, "PUT");
    }

    //Renews the domain for the default number of years (currently 2 years).
    public function renew(string $domainName): void
    {
        $this->makeRequest("/v0.3/billing/{$domainName}/renew", null, null, "POST");
    }

    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $nsId = $this->createNameservers($nameservers);
        $this->setNameservers($domainName, $nsId);

        $response = $this->makeRequest("/v0.3/nameservers/{$nsId}");

        return $this->parseNameservers($response['nameserver']);
    }
}
