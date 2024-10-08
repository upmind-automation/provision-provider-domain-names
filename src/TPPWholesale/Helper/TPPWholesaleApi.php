<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TPPWholesale\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

    /**
     * 1 Australian Company Number (ACN)
     */
    public const AU_REGISTRANT_ID_TYPE_ACN = 1;
    /**
     * 2 Australian Business Number (ABN)
     */
    public const AU_REGISTRANT_ID_TYPE_ABN = 2;
    /**
     * 3 Other - Used to record an Incorporated Association number
     */
    public const AU_REGISTRANT_ID_TYPE_OTHER = 3;

    /**
     * 1 Australian Company Number (ACN)
     */
    public const AU_ELIGIBILITY_ID_TYPE_ACN = 1;
    /**
     * 12 Australian Business Number (ABN).
     */
    public const AU_ELIGIBILITY_ID_TYPE_ABN = 12;
    /**
     * 11 Other - Used to record an Incorporated Association number.
     */
    public const AU_ELIGIBILITY_ID_TYPE_OTHER = 11;

    protected Client $client;

    protected Configuration $configuration;
    protected string $sessionID;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
        $this->getSessionID();
    }

    private function getSessionID(bool $keepAlive = false): void
    {
        $params = [
            'AccountNo' => $this->configuration->account_no,
            'UserId' => $this->configuration->api_login,
            'Password' => $this->configuration->api_password,
        ];

        $response = $this->makeRequest("/auth.pl", $params);

        $this->sessionID = $response->parseAuthResponse();

        if ($keepAlive) {
            $params = [
                'Type' => 'Domains',
                'Action' => 'KeepAlive',
            ];

            $this->makeRequest("/query.pl", $params);
        }
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
            ->then(function (Response $response) {
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
     * @throws \InvalidArgumentException if neither domain nor orderId are provided
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainOrderInfo(?string $domain, ?int $orderId): array
    {
        $params = [
            'Type' => 'Domains',
            'Object' => 'Order',
            'Action' => 'OrderStatus',
        ];

        if (!empty($orderId)) {
            $params['OrderID'] = $orderId;

            $response = $this->makeRequest("/query.pl", $params);
            return $response->parseDomainOrderResponse();
        }

        if (empty($domain)) {
            throw new InvalidArgumentException('Either domain or order ID must be provided');
        }

        $params['Domain'] = $domain;
        $response = $this->makeRequest("/query.pl", $params);
        return $response->parseDomainOrderResponse();
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
                $result['ns' . ($i + 1)] = ['host' => $ns];
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
            'AccountOption' => $this->configuration->account_option ?: 'DEFAULT',
            'AccountID' => $this->configuration->account_id ?: str_replace('-API', '', $this->configuration->api_login),
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
            if (Str::endsWith($domainName, '.au')) {
                $additionalFields = $this->getAuAdditionalFields($additionalFields);
            }

            $query .= '&' . http_build_query($additionalFields);
        }

        $response = $this->makeRequest("/order.pl", $query);
        return $response->parseCreateDomainResponse();
    }

    /**
     * Attempt to fill RegistrantID and RegistrantIDType fields from EligibilityID and EligibilityIDType fields.
     */
    private function getAuAdditionalFields(array $additionalFields): array
    {
        if (empty($additionalFields['RegistrantID']) && !empty($additionalFields['EligibilityID'])) {
            $additionalFields['RegistrantID'] = $additionalFields['EligibilityID'];
        }

        if (empty($additionalFields['RegistrantIDType']) && !empty($additionalFields['EligibilityIDType'])) {
            $registrantIdType = $this->auEligibilityIdTypeToRegistrantIdType($additionalFields['EligibilityIDType']);
            $additionalFields['RegistrantIDType'] = $registrantIdType;
        }

        return $additionalFields;
    }

    /**
     * Get the corresponding RegistrantIDType for the given EligibilityIDType.
     */
    private function auEligibilityIdTypeToRegistrantIdType(int $eligibilityIdType): int
    {
        switch ($eligibilityIdType) {
            case self::AU_ELIGIBILITY_ID_TYPE_ACN:
                return self::AU_REGISTRANT_ID_TYPE_ACN;
            case self::AU_ELIGIBILITY_ID_TYPE_ABN:
                return self::AU_REGISTRANT_ID_TYPE_ABN;
            case self::AU_ELIGIBILITY_ID_TYPE_OTHER: // fall-through
            default:
                return self::AU_REGISTRANT_ID_TYPE_OTHER;
        }
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
            throw ProvisionFunctionError::create('Domain renewal for this domain has been disabled')
                ->withData(['check_response' => $response]);
        }

        if ($period > $maximum) {
            throw ProvisionFunctionError::create('Requested renewal period is too long')
                ->withData(['check_response' => $response]);
        }

        if ($period < $minimum) {
            throw ProvisionFunctionError::create('Requested renewal period is too short')
                ->withData(['check_response' => $response]);
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
            'AccountOption' => $this->configuration->account_option ?: 'DEFAULT',
            'AccountID' => $this->configuration->account_id ?: str_replace('-API', '', $this->configuration->api_login),
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

        // Now ensure only one result per domain.
        // Sort by error code descending, so that 601 (order already exists)
        // gets overwritten by lower error codes, or available=true results
        // when we key by domain name then reset the keys.
        $res = (new Collection($res))
            ->sort(function ($a, $b) {
                return ($b['ErrorCode'] ?? 0) <=> ($a['ErrorCode'] ?? 0);
            })
            ->keyBy('Domain')
            ->values()
            ->all();

        foreach ($res as $result) {
            $available = true;
            $transferrable = false;
            $description = $result["ErrorDescription"] ?? null;

            if ($result["Status"] === "ERR") {
                if ((int)$result['ErrorCode'] === 304) {
                    // already registered
                    $available = false;
                    $transferrable = true;
                }

                if ((int)$result["ErrorCode"] === 309) {
                    // TLD not supported
                    $available = false;
                    $transferrable = false;
                }

                if ((int)$result['ErrorCode'] === 601) {
                    // an order already exists, but the fastest horse wins... still available!
                    $available = true;
                    $transferrable = false;
                }
            }

            $description ??= sprintf(
                'Domain is %s to register',
                $available ? 'available' : 'not available'
            );

            $dacDomains[] = DacDomain::create([
                'domain' => $result['Domain'],
                'description' => ucfirst(trim($description)),
                'tld' => Utils::getTld($result['Domain']),
                'can_register' => $available,
                'can_transfer' => $transferrable,
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
