<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\InternetX\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\InternetX\Data\Configuration;

/**
 * InternetX Domains API client.
 */
class InternetXApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'ownerc';
    public const CONTACT_TYPE_TECH = 'techc';
    public const CONTACT_TYPE_ADMIN = 'adminc';

    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function asyncRequest(
        string $command,
        ?array $query = null,
        ?array $body = null,
        string $method = 'POST'
    ): Promise {
        $requestParams = [];
        if ($query) {
            $requestParams = ['query' => $query];
        }

        if ($body) {
            $requestParams['json'] = $body;
        }

        /** @var \GuzzleHttp\Promise\Promise $promise */
        $promise = $this->client->requestAsync($method, "/v1{$command}", $requestParams)
            ->then(function (Response $response) use ($command) {
                $result = $response->getBody()->getContents();
                $response->getBody()->close();

                if ($result === '') {
                    return null;
                }

                if ($command == '/poll') {
                    return $this->parseResponseData($result);
                }

                return $this->parseResponseData($result)["data"][0];
            });

        return $promise;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(
        string $command,
        ?array $query = null,
        ?array $body = null,
        string $method = 'GET'
    ): ?array {
        return $this->asyncRequest($command, $query, $body, $method)->wait();
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
        $status = $responseData['status']['type'] ?: null;

        if ($status == 'NOTIFY') {
            return null;
        }

        if ($status != "SUCCESS") {
            if (isset($responseData['messages'])) {
                if (isset($responseData['messages'][0]['text'])) {
                    $errorMessage = $responseData['messages'][0]['text'];
                }
            }

            $errorMessage = sprintf('Provider API Error: %s', $errorMessage ?? 'Unknown Error');
        }

        return $errorMessage ?? null;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainInfo(string $domainName): array
    {
        $response = $this->makeRequest("/domain/{$domainName}");

        return [
            'id' => 'N/A',
            'domain' => (string)$response['name'],
            'statuses' => [$response['registrarStatus']],
            'locked' => $response['registryStatus'] == 'LOCK',
            'registrant' => isset($response['ownerc']["id"])
                ? $this->getContactInfo($response['ownerc']["id"])
                : null,
            'billing' => null,
            'tech' => isset($response['techc']["id"])
                ? $this->getContactInfo($response['techc']["id"])
                : null,
            'admin' => isset($response['adminc']["id"])
                ? $this->getContactInfo($response['adminc']["id"])
                : null,
            'ns' => NameserversResult::create($this->parseNameservers($response['nameServers'] ?? [])),
            'created_at' => isset($response['created'])
                ? Utils::formatDate((string)$response['created'])
                : null,
            'updated_at' => isset($response['updated'])
                ? Utils::formatDate((string)$response['updated'])
                : null,
            'expires_at' => isset($response['payable'])
                ? Utils::formatDate($response['payable'])
                : null,
        ];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getContactInfo(int $id): ContactData
    {
        $contact = $this->makeRequest("/contact/{$id}");
        return ContactData::create([
            'id' => $id,
            'organisation' => isset($contact['organization']) ? (string)$contact['organization'] : null,
            'name' => $contact['fname'] . ' ' . $contact['lname'],
            'address1' => $contact['address'][0],
            'city' => $contact['city'],
            'state' => isset($contact['state']) ? (string)$contact['state'] : null,
            'postcode' => $contact['pcode'],
            'country_code' => Utils::normalizeCountryCode($contact['country']),
            'email' => isset($contact['email']) ? (string)$contact['email'] : null,
            'phone' => isset($contact['phone']) ? (string)$contact['phone'] : null,
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function createContact(ContactParams $contactParams): ?int
    {
        $body = $this->setContactParams($contactParams);

        $response = $this->makeRequest("/contact", null, $body, "POST");

        return $response['id'] ?? null;
    }

    private function parseNameservers(array $nameservers): array
    {
        $result = [];

        if (count($nameservers) > 0) {
            foreach ($nameservers as $i => $ns) {
                $result['ns' . ($i + 1)] = ['host' => (string)$ns['name']];
            }
        }

        return $result;
    }

    /**
     * @param string[] $nameServers
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(string $domainName, int $period, array $contacts, array $nameServers): void
    {
        $ns = [];
        foreach ($nameServers as $n) {
            $ns[] = ["name" => $n];
        }

        $payable = Carbon::now()->add($period, 'year');
        $body = [
            'name' => $domainName,
            'nameServers' => $ns,
            'payable' => $payable->format("Y-m-d\TH:i:s.vO"),
            'period' => [
                "unit" => "YEAR",
                "period" => $period,
            ]
        ];

        foreach ($contacts as $type => $contactId) {
            if ($contactId != null) {
                $body[$type]['id'] = $contactId;
            }
        }

        $this->makeRequest("/domain", null, $body, "POST");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    private function setContactParams(ContactParams $contactParams): array
    {
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        return [
            'address' => [
                $contactParams->address1,
            ],
            'city' => $contactParams->city,
            'country' => Utils::normalizeCountryCode($contactParams->country_code),
            'pcode' => $contactParams->postcode,
            'state' => $contactParams->state ?: '',
            'organization' => $contactParams->organisation ?: '',
            'fname' => $nameParts['firstName'],
            'lname' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'email' => $contactParams->email,
            'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
            "type" => $contactParams->type ?: "PERSON",
        ];
    }

    private function getNameParts(?string $name): array
    {
        $nameParts = explode(" ", $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(" ", $nameParts);

        return compact('firstName', 'lastName');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainEppCode(string $domainName): ?string
    {
        $response = $this->makeRequest("/domain/{$domainName}");
        return $response['authinfo'] ?? null;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getRegistrarLockStatus(string $domainName): bool
    {
        $response = $this->makeRequest("/domain/{$domainName}");
        return $response['registryStatus'] == 'LOCK';
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $body = [
            'registryStatus' => $lock ? 'LOCK' : 'ACTIVE',
        ];

        $this->makeRequest("/domain/{$domainName}/_statusUpdate", null, $body, "PUT");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(string $domainName, array $nameServers): void
    {
        $ns = [];
        foreach ($nameServers as $n) {
            $ns[] = ["name" => $n];
        }

        $body = [
            'nameServers' => $ns,
        ];

        $this->makeRequest("/domain/{$domainName}", null, $body, "PUT");
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): void
    {
        $contactData = $this->setContactParams($contactParams);
        $body = [
            self::CONTACT_TYPE_REGISTRANT => $contactData,
            "confirmOwnerConsent" => true,
        ];

        $this->makeRequest("/domain/{$domainName}", null, $body, "PUT");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(string $domainName, int $period): void
    {
        $response = $this->makeRequest("/domain/{$domainName}");
        $payable = $response['payable'] ?? null;

        $body = [
            'payable' => $payable,
            'period' => [
                "unit" => "YEAR",
                "period" => $period
            ]
        ];

        $this->makeRequest("/domain/{$domainName}/_renew", null, $body, "PUT");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function initiateTransfer(string $domainName, string $eppCode, array $contacts, int $period): string
    {
        $payable = Carbon::now()->add($period, 'year');

        $body = [
            'name' => $domainName,
            'authinfo' => $eppCode,
            'payable' => $payable->format("Y-m-d\TH:i:s.vO"),
            'period' => [
                "unit" => "YEAR",
                "period" => $period,
            ]
        ];

        foreach ($contacts as $type => $contactId) {
            if ($contactId != null) {
                $body[$type]['id'] = $contactId;
            }
        }

        $response = $this->makeRequest('/domain/_transfer', null, $body, 'POST');

        return (string)$response['id'];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(int $limit, ?Carbon $since): array
    {
        $notifications = [];
        $countRemaining = 0;

        /**
         * Start a timer because there may be 1000s of irrelevant messages and we should try and avoid a timeout.
         */
        $timeLimit = 60; // 60 seconds
        $startTime = time();

        while (count($notifications) < $limit && (time() - $startTime) < $timeLimit) {
            $pollResponse = $this->makeRequest("/poll");

            if($pollResponse['messages'][0]['text'] == "Polling is not activated.") {
                throw ProvisionFunctionError::create("Polling is not activated.")
                    ->withData([
                        'response' => $pollResponse,
                    ]);
            }

            $countRemaining = $pollResponse['object']['summary'] ?? 0;

            if ($countRemaining == 0) {
                break;
            }

            $data = $pollResponse['data'][0];

            $messageId = $data['id'];
            $message = $pollResponse['messages'][0]['text'] ?: 'Domain Notification';
            $domain = $data['name'];
            $messageDateTime = isset($pollResponse['job']['created'])
                ? Carbon::parse(Utils::formatDate((string)$pollResponse['job']['created']))
                : null;

            $this->makeRequest("/poll{$messageId}", null, null, 'PUT');

            $type = "";
            switch ($data['action']) {
                case 'TRANSFER':
                case 'CREATE':
                    $type = DomainNotification::TYPE_TRANSFER_IN;
                    break;
                case 'DELETE':
                    $type = DomainNotification::TYPE_DELETED;
                    break;
                case 'RENEW':
                    $type = DomainNotification::TYPE_RENEWED;
                    break;
            }

            if ($type == "") {
                continue;
            }

            if (isset($since) && $messageDateTime !== null && $messageDateTime->lessThan($since)) {
                // this message is too old
                continue;
            }

            $notifications[] = DomainNotification::create()
                ->setId($messageId)
                ->setType($type)
                ->setMessage($message)
                ->setDomains([$domain])
                ->setCreatedAt($messageDateTime)
                ->setExtra(['response' => json_encode($data)]);
        }

        return [
            'count_remaining' => $countRemaining,
            'notifications' => $notifications,
        ];
    }
}
