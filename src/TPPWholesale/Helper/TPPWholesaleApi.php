<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TPPWholesale\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\TPPWholesale\Data\Configuration;

/**
 * TPPWholesale Domains API client.
 */
class TPPWholesaleApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'owner';
    public const CONTACT_TYPE_ADMIN = 'admin';
    public const CONTACT_TYPE_TECH = 'tech';
    public const CONTACT_TYPE_BILLING = 'billing';

    protected Client $client;

    protected Configuration $configuration;
    protected string $sessionID;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
        $this->getSessionID();
    }

    private function getSessionID(): void
    {
        $params = [
            'AccountNo' => $this->configuration->accountNo,
            'UserId' => $this->configuration->userId,
            'Password' => $this->configuration->password,
        ];

        $response = $this->makeRequest("/auth.pl", $params);

        $this->sessionID = $response->parseAuthResponse();

        $params = [
            'Type' => 'Domains',
            'Action' => 'KeepAlive',
        ];

        $this->makeRequest("/query.pl", $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function asyncRequest(
        string $command,
        array|string|null $query = null,
        ?array $body = null,
        string $method = 'POST'
    ): Promise {
        $requestParams = [];

        if ($command !== '/auth.pl' && gettype($query) !== 'string') {
            $query["SessionID"] = $this->sessionID;
        }

        if (gettype($query) === 'string') {
            $query .= "&SessionID=" . $this->sessionID;
        }

        if ($query) {
            $requestParams = ['query' => $query];
        }

        if ($body) {
            $requestParams['json'] = $body;
        }

        /** @var \GuzzleHttp\Promise\Promise $promise */
        $promise = $this->client->requestAsync($method, "/api{$command}", $requestParams)
            ->then(function (Response $response) use ($command) {
                $result = $response->getBody()->getContents();
                $response->getBody()->close();

                if ($result === '') {
                    return null;
                }

                return new TPPWholesaleResponse((string)$result);
            });

        return $promise;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(
        string $command,
        array|string|null $query = null,
        ?array $body = null,
        string $method = 'POST'
    ): ?TPPWholesaleResponse {
        return $this->asyncRequest($command, $query, $body, $method)->wait();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainInfo(string $domainName): array
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'Details',
            'Domain' => $domainName,
        ];

        $response = $this->makeRequest("/query.pl", $params);
        $parsedResponse = $response->parseInfoResponse();

        return [
            'id' => 'N/A',
            'domain' => $domainName,
            'statuses' => [$parsedResponse['DomainStatus']],
            'locked' => $parsedResponse['LockStatus'] == 2,
            'registrant' => isset($parsedResponse['Owner'])
                ? $this->parseContact($parsedResponse['Owner'])
                : null,
            'billing' => isset($parsedResponse['Billing'])
                ? $this->parseContact($parsedResponse['Billing'])
                : null,
            'tech' => isset($parsedResponse['Technical'])
                ? $this->parseContact($parsedResponse['Technical'])
                : null,
            'admin' => isset($parsedResponse['Administration'])
                ? $this->parseContact($parsedResponse['Administration'])
                : null,
            'ns' => NameserversResult::create($this->parseNameservers($parsedResponse['Nameserver'] ?? [])),
            'created_at' => null,
            'updated_at' => null,
            'expires_at' => isset($parsedResponse['ExpiryDate'])
                ? Utils::formatDate($parsedResponse['ExpiryDate'])
                : null,
        ];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function createContact(ContactParams $contactParams): ?string
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Contact',
            'Action' => 'Create',
        ];

        $params = array_merge($params, $this->setContactParams($contactParams));

        $response = $this->makeRequest("/order.pl", $params);
        return $response->parseCreateContactResponse();
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
    public function register(
        string $domainName,
        int $period,
        array $contacts,
        array $nameServers,
        ?array $additionalFields
    ): string {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'Create',
            'Domain' => $domainName,
            'Period' => $period,
            'OwnerContactID' => $contacts[self::CONTACT_TYPE_REGISTRANT],
            'AdministrationContactID' => $contacts[self::CONTACT_TYPE_ADMIN],
            'TechnicalContactID' => $contacts[self::CONTACT_TYPE_TECH],
            'BillingContactID' => $contacts[self::CONTACT_TYPE_BILLING],
        ];

        $query = http_build_query($params);

        foreach ($nameServers as $n) {
            $query .= '&' . http_build_query(['Host' => $n]);
        }

        if ($additionalFields) {
            $query .= '&' . http_build_query($additionalFields);
        }

        $response = $this->makeRequest("/order.pl", $query);
        return $response->parseCreateDomainResponse();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    private function setContactParams(ContactParams $contactParams): array
    {
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        return [
            'OrganisationName' => $contactParams->organisation ?: '',
            'FirstName' => $nameParts['firstName'],
            'LastName' => $nameParts['lastName'] ?: $nameParts['firstName'],
            'Address1' => $contactParams->address1,
            'City' => $contactParams->city,
            'Region' => $contactParams->state ?: $contactParams->city,
            'PostalCode' => $contactParams->postcode,
            'CountryCode' => Utils::normalizeCountryCode($contactParams->country_code),
            'PhoneCountryCode' => '',
            'PhoneAreaCode' => '',
            'PhoneNumber' => Utils::internationalPhoneToEpp($contactParams->phone),
            'Email' => $contactParams->email,
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
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'Details',
            'Domain' => $domainName,
        ];

        $response = $this->makeRequest("/query.pl", $params);
        return $response->parseEppResponse();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getRegistrarLockStatus(string $domainName): bool
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'Details',
            'Domain' => $domainName,
        ];

        $response = $this->makeRequest("/query.pl", $params);
        $parsedResponse = $response->parseLockStatusResponse();

        return $parsedResponse == 'Lock';
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'UpdateDomainLock',
            'Domain' => $domainName,
            'DomainLock' => $lock ? 'Lock' : 'Unlock'
        ];

        $response = $this->makeRequest("/order.pl", $params);
        $response->parseLockResponse();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(string $domainName, array $nameServers): string
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'UpdateHosts',
            'Domain' => $domainName,
            'RemoveHost' => 'ALL',
        ];

        $query = "";
        foreach ($params as $key => $value) {
            $query .= "&" . $key . "=" . $value;
        }

        foreach ($nameServers as $n) {
            $query .= "&AddHost=" . $n;
        }

        $response = $this->makeRequest("/order.pl", $query);
        return $response->parseUpdateHostResponse();
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): void
    {
        $contactId = $this->createContact($contactParams);

        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'UpdateContacts',
            'Domain' => $domainName,
            'OwnerContactID' => $contactId,
        ];

        $response = $this->makeRequest("/order.pl", $params);
        $response->parseUpdateContactResponse();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(string $domainName, int $period): void
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'Renewal',
            'Domain' => $domainName,
        ];

        $response = $this->makeRequest("/query.pl", $params);
        $parsedResponse = $response->parseRenewalResponse();

        $minimum = $parsedResponse["Minimum"];
        $maximum = $parsedResponse["Maximum"];

        if ($minimum == -1 || $maximum == -1) {
            throw ProvisionFunctionError::create('Domain renewal for the TLD are disabled for the reseller account.');
        }

        if ($period > $maximum) {
            $period = $maximum;
        }

        if ($period < $minimum) {
            $period = $minimum;
        }

        $params["Period"] = $period;

        $this->makeRequest("/order.pl", $params);
        $response->parseRenewalOrderResponse();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function initiateTransfer(string $domainName, string $eppCode, array $contacts, int $period): string
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'TransferRequest',
            'Domain' => $domainName,
            'DomainPassword' => $eppCode,
            'OwnerContactID' => $contacts[self::CONTACT_TYPE_REGISTRANT],
            'AdministrationContactID' => $contacts[self::CONTACT_TYPE_ADMIN],
            'TechnicalContactID' => $contacts[self::CONTACT_TYPE_TECH],
            'BillingContactID' => $contacts[self::CONTACT_TYPE_BILLING],
        ];

        $response = $this->makeRequest("/order.pl", $params);
        return $response->parseTransferResponse();
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
        $dacDomains = [];

        $params = [
            'Type' => 'Domains',
            'Object' => 'Domain',
            'Action' => 'Availability',
        ];

        $query = "";
        foreach ($params as $key => $value) {
            $query .= "&" . $key . "=" . $value;
        }

        foreach ($domainList as $domain) {
            $query .= "&Domain={$domain}";
        }

        $response = $this->makeRequest("/query.pl", $query);
        $res = $response->parseDACResponse();

        foreach ($res as $result) {
            $available = true;

            if ($result["Status"] == "ERR") {
                $available = false;
            }

            $transferred = !$available;

            $description = sprintf(
                'Domain is %s to register',
                $available ? 'available' : 'not available'
            );

            if ($result["Status"] == "ERR" && $result["ErrorCode"] == 309) {
                $description = $result["ErrorDescription"];
                $transferred = false;
            }

            $dacDomains[] = DacDomain::create([
                'domain' => $result['Domain'],
                'description' => $description,
                'tld' => Utils::getTld($result['Domain']),
                'can_register' => $available,
                'can_transfer' => $transferred,
                'is_premium' => false,
            ]);
        }

        return $dacDomains;
    }

    private function parseContact(array $contact): ContactData
    {
        return ContactData::create([
            'organisation' => $contact['OrganisationName'] ?: null,
            'name' => $contact['FirstName'] . " " . $contact['LastName'],
            'address1' => $contact['Address1'],
            'city' => $contact['City'],
            'state' => $contact['Region'] ?: null,
            'postcode' => $contact['PostalCode'],
            'country_code' => Utils::normalizeCountryCode($contact['CountryCode']),
            'email' => $contact['Email'],
            'phone' => $contact['PhoneNumber'],
        ]);
    }
}
