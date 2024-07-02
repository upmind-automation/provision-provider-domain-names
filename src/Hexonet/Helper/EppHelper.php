<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\Helper;

use Illuminate\Support\Collection;
use LogicException;
use Metaregistrar\EPP\eppCheckDomainRequest;
use Metaregistrar\EPP\eppCheckRequest;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\EppConnection as eppConnection;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests\EppTransferRequest as eppTransferRequest;
use Metaregistrar\EPP\eppContact;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppContactPostalInfo;
use Metaregistrar\EPP\eppCreateContactRequest;
use Metaregistrar\EPP\eppCreateDomainRequest;
use Metaregistrar\EPP\eppCreateHostRequest;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppHost;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppRenewRequest;
use Metaregistrar\EPP\eppTransferResponse;
use Metaregistrar\EPP\eppUpdateContactRequest;
use Metaregistrar\EPP\eppUpdateDomainRequest;
use Metaregistrar\EPP\eppUpdateResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Hexonet\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests\EppCheckTransferRequest;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests\EppQueryTransferListRequest;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses\EppCheckTransferResponse;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses\EppQueryTransferListResponse;

/**
 * Helper class to utilize the most frequently used method to query EPP endpoints.
 *
 * For Hexonet and Nominet - there are slight differences, mainly in the fields required (and format), but it can virtually cover both of them.
 *
 *
 *        ,-.       _,---._ __  / \
 *       /  )    .-'       `./ /   \
 *       (  (   ,'            `/    /|
 *       \  `-"             \'\   /  |
 *       `.              ,  \ \ /    |
 *       /`.          ,'-`----Y      |
 *       (            ;        |    '
 *       |  ,-.    ,-'         |   /
 *       |  | (   |            |  /
 *       )  |  \  `.___________|/
 *       `--'   `--'
 *
 * Class EppHelper
 * @package Upmind\ProvisionProviders\DomainNames\Helper
 */
class EppHelper
{
    /**
     * Depending on the mode used (live/sandbox), we will use a different baseUrl for our requests
     *
     * Sandbox User: test.user
     * Sandbox Pass: test.passw0rd
     *
     * You can also create a test account here https://account-ote.hexonet.net
     * A great place to test some of the EPP Requests to Hexonet: http://www.rootsystems.net/epp.php
     */
    public const EPP_CONNECTION = [
        'live' => [
            'hostname' => 'ssl://epp.ispapi.net',
            'port' => 700,
        ],
        'sandbox' => [
            'hostname' => 'ssl://epp-ote.ispapi.net',
            'port' => 700
        ]
    ];

    /**
     * Authenticate and establish a connection with the Domain Provider API and login.
     *
     * @throws \RuntimeException
     */
    public static function establishConnection(Configuration $configuration, LoggerInterface $logger): eppConnection
    {
        $connection = new eppConnection();
        $connection->setPsrLogger($logger);

        $eppConnectionData = self::EPP_CONNECTION;
        $eppConnectionData = self::validateParseEppConnectionData((bool) $configuration['sandbox'], $eppConnectionData);

        // Set connection data
        $connection->setHostname($eppConnectionData['hostname']);
        $connection->setPort($eppConnectionData['port']);
        $connection->setUsername($configuration['username']);
        $connection->setPassword($configuration['password']);

        $connection->login();

        return $connection;
    }

    /**
     * After we finished with API calls, we need to close the connection.
     *
     * @throws \Metaregistrar\EPP\eppException
     */
    public static function terminateConnection(?eppConnection $connection): void
    {
        if (isset($connection)) {
            $connection->logout();
        }
    }

    /**
     * Checks Domain Availability
     *
     * @return array The following format is do be expected: ['domain' => 'domainName', 'available' => bool, 'reason' => ?string]
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     */
    public static function checkDomains(eppConnection $connection, array $domains): array
    {
        // Attempt to check domain availability
        $contactsRequest = new eppCheckDomainRequest($domains);

        // Process the response
        /** @var \Metaregistrar\EPP\eppCheckDomainResponse $response */
        $response = $connection->request($contactsRequest);
        $result = [];

        // Process checks and push to result array
        $checks = $response->getCheckedDomains();

        foreach ($checks as $check) {
            // Divide the domain to sld and tld
            $parts = Utils::getSldTld($check['domainname']);

            $result[] = [
                'sld' => $parts['sld'],
                'tld' => $parts['tld'],
                'domain' => $check['domainname'],
                'available' => (bool) $check['available'],
                'reason' => $check['reason']
            ];
        }

        return $result;
    }

    /**
     * Renew a domain for a given period
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     */
    public static function renewDomain(eppConnection $connection, string $domain, int $period): array
    {
        $domainData = new eppDomain($domain);
        $info = new eppInfoDomainRequest($domainData);

        // Get Domain Info
        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $connection->request($info);
        // New Expiry Date
        $expiresAt = Utils::formatDate($response->getDomainExpirationDate(), 'Y-m-d');

        // Attempt to renew
        $domainData->setPeriod($period);
        $domainData->setPeriodUnit('y');

        $renewRequest = new eppRenewRequest($domainData, $expiresAt);
        /** @var \Metaregistrar\EPP\eppRenewResponse $renewResponse */
        $renewResponse = $connection->request($renewRequest);

        return [
            'domain' => $renewResponse->getDomainName(),
            'expires_at' => $renewResponse->getDomainExpirationDate(),
        ];
    }

    /**
     * Returns EPP Code for a given domain
     *
     * @return string Epp/Auth code
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     */
    public static function getDomainEppCode(eppConnection $connection, string $domainName): string
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $connection->request($info);

        return $response->getDomainAuthInfo();
    }

    /**
     * Returns domain info
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     */
    public static function getDomainInfo(eppConnection $connection, string $domainName): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $connection->request($info);
        $registrantId = $response->getDomainRegistrant();
        $updatedAt = $response->getDomainUpdateDate();

        return [
            'id' => $response->getDomainId(),
            'domain' => $response->getDomainName(),
            'statuses' => $response->getDomainStatuses() ?? [],
            'registrant' => $registrantId ? self::getContactInfo($connection, $registrantId) : null,
            // 'adminContactId' => $response->getDomainContact(eppContactHandle::CONTACT_TYPE_ADMIN),
            // 'billingContactId' => $response->getDomainContact(eppContactHandle::CONTACT_TYPE_BILLING),
            // 'techContactId' => $response->getDomainContact(eppContactHandle::CONTACT_TYPE_TECH),
            'ns' => self::parseNameServers($response->getDomainNameservers() ?? []),
            'created_at' => Utils::formatDate($response->getDomainCreateDate()),
            'updated_at' => Utils::formatDate($updatedAt ?: $response->getDomainCreateDate()),
            'expires_at' => Utils::formatDate($response->getDomainExpirationDate())
        ];
    }

    /**
     * Updating a contact
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException If phone number is invalid
     */
    public static function updateDomainContact(
        eppConnection $connection,
        string $contactId,
        string $email,
        ?string $telephone,
        string $name,
        ?string $address,
        ?string $postcode,
        ?string $city,
        ?string $state,
        ?string $countryCode,
        ?string $organization = null,
        ?string $contactType = null,
        ?string $password = null
    ): ContactData {
        if ($telephone) {
            $telephone = Utils::internationalPhoneToEpp($telephone);
        }

        if ($countryCode) {
            $countryCode = Utils::normalizeCountryCode($countryCode);
        }

        // Build the update query
        $postalInfo = new eppContactPostalInfo($name, $city, $countryCode, $organization, $address, $state, $postcode);
        $contactInfo = new eppContact($postalInfo, $email, $telephone);

        if (!is_null($contactType)) {
            $contactInfo->setType($contactType);
        }

        if (!is_null($password)) {
            $contactInfo->setPassword($password);
        }

        $updateQuery = new eppContact($postalInfo, $email, $telephone);
        $updateRequest = new eppUpdateContactRequest(new eppContactHandle($contactId), null, null, $updateQuery);

        /** @var \Metaregistrar\EPP\eppUpdateContactResponse $response */
        $response = $connection->request($updateRequest);

        return ContactData::create([
            'id' => $contactId,
            'name' => $name,
            'email' => $email,
            'phone' => $telephone,
            'organisation' => $organization,
            'address1' => $address,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
            'country_code' => $countryCode,
            'type' => $contactType
        ]);
    }

    /**
     * Returns domain contact id
     *
     * @param eppConnection $connection
     * @param string $domainName
     * @param string $contactType One of: reg, admin, billing, tech
     * @return string|null Contact id
     *
     * @throws \Metaregistrar\EPP\eppException
     */
    public static function getDomainContactId(
        eppConnection $connection,
        string $domainName,
        string $contactType = eppContactHandle::CONTACT_TYPE_REGISTRANT
    ): ?string {
        $validContactTypes = [
            eppContactHandle::CONTACT_TYPE_REGISTRANT,
            eppContactHandle::CONTACT_TYPE_ADMIN,
            eppContactHandle::CONTACT_TYPE_TECH,
            eppContactHandle::CONTACT_TYPE_BILLING,
        ];

        if (!in_array($contactType, $validContactTypes)) {
            throw new LogicException(sprintf('Invalid contact type %s', $contactType));
        }

        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $connection->request($info);

        return $contactType === eppContactHandle::CONTACT_TYPE_REGISTRANT
            ? $response->getDomainRegistrant()
            : $response->getDomainContact($contactType);
    }

    /**
     * Create Contact for a domain
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException If phone number is invalid
     */
    public static function createContact(
        eppConnection $connection,
        string $email,
        ?string $telephone,
        string $name,
        ?string $address,
        ?string $postcode,
        ?string $city,
        ?string $state,
        ?string $countryCode,
        ?string $organization = null,
        ?string $contactType = null,
        ?string $password = null
    ): ContactData {
        if ($telephone) {
            $telephone = Utils::internationalPhoneToEpp($telephone);
        }

        if ($countryCode) {
            $countryCode = Utils::normalizeCountryCode($countryCode);
        }

        $postalInfo = new eppContactPostalInfo($name, $city, $countryCode, $organization, $address, $state, $postcode);
        $contactInfo = new eppContact($postalInfo, $email, $telephone);

        if (!is_null($contactType)) {
            $contactInfo->setType($contactType);
        }

        if (!is_null($password)) {
            $contactInfo->setPassword($password);
        }

        $contact = new eppCreateContactRequest($contactInfo);

        // Include more details in the response
        /** @var \Metaregistrar\EPP\eppCreateContactResponse $response */
        $response = $connection->request($contact);

        return ContactData::create([
            'id' => $response->getContactId(),
            'name' => $name,
            'email' => $email,
            'phone' => $telephone,
            'organisation' => $organization,
            'address1' => $address,
            'city' => $city,
            'postcode' => $postcode,
            'country_code' => $countryCode,
            'type' => $contactType,
        ]);
    }

    /**
     * Set or update the given contact on the given domain name.
     *
     * @param string $contactType One of: reg, admin, billing, teche
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     */
    public static function setDomainContact(
        eppConnection $connection,
        string $domainName,
        string $contactType,
        string $contactId
    ): eppUpdateResponse {
        switch ($contactType) {
            case eppContactHandle::CONTACT_TYPE_REGISTRANT:
                $registrantId = $contactId;
                break;
            case eppContactHandle::CONTACT_TYPE_ADMIN:
                $adminId = $contactId;
                break;
            case eppContactHandle::CONTACT_TYPE_BILLING:
                $billingId = $contactId;
                break;
            case eppContactHandle::CONTACT_TYPE_TECH:
                $techId = $contactId;
                break;
        }

        return self::updateDomain(
            $connection,
            $domainName,
            null,
            $registrantId ?? null,
            $adminId ?? null,
            $billingId ?? null,
            $techId ?? null
        );
    }

    /**
     * Query list of existing transfer-IN requests.
     *
     * @throws \DOMException
     * @throws \Metaregistrar\EPP\eppException
     */
    public static function queryTransferList(eppConnection $connection, string $domain): EppQueryTransferListResponse
    {
        $transferQueryRequest = new EppQueryTransferListRequest($domain);
        /** @var \Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses\EppQueryTransferListResponse $transferQueryResponse */
        $transferQueryResponse = $connection->request($transferQueryRequest);

        return $transferQueryResponse;
    }

    /**
     * Requests and tries to approve domain transfer
     *
     * This method will handle the request transfer and then will try to automatically approve the request.
     *
     * @param int $renewYears How many years to renew the domain for upon successful transfer
     *
     * @throws \DOMException
     * @throws \Metaregistrar\EPP\eppException If command fails
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If domain is not transferrable
     */
    public static function transferRequest(
        eppConnection $connection,
        string $domain,
        array $nameServers,
        ?string $registrantId = null,
        ?string $adminContactId = null,
        ?string $billingContactId = null,
        ?string $techContactId = null,
        ?string $eppCode = null,
        int $renewYears = 1
    ): eppTransferResponse {
        $transferCheck = self::checkTransfer($connection, $domain, $eppCode);
        $checkData = $transferCheck->getData();

        if (!$transferCheck->isAvailable()) {
            self::errorResult($transferCheck->getResultReason(), $checkData);
        }

        if (!empty($checkData['TRANSFERLOCK'])) {
            self::errorResult('Domain is currently transfer-locked', $checkData);
        }

        // Get Domain Info
        $domainInfo = new eppDomain($domain);

        // Set EPP Code
        if (isset($eppCode)) {
            $domainInfo->setAuthorisationCode($eppCode);
        }

        $domainInfo->setPeriod($renewYears);
        $domainInfo->setPeriodUnit('y');

        // Using our custom transfer request here in order to support Hexonet's USERTRANSFER for internal transfers
        $transferRequest = new eppTransferRequest(eppTransferRequest::OPERATION_REQUEST, $domainInfo);
        if (!empty($checkData['USERTRANSFERREQUIRED'])) {
            $transferRequest->addUserTransferAction();
        }

        // Process Response
        /** @var \Metaregistrar\EPP\eppTransferResponse */
        return $connection->request($transferRequest);
    }

    /**
     * @throws \DOMException
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public static function checkTransfer(
        eppConnection $connection,
        string $domain,
        ?string $eppCode = null
    ): EppCheckTransferResponse {
        try {
            /** @var \Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses\EppCheckTransferResponse $response */
            $response = $connection->request(new EppCheckTransferRequest($domain, $eppCode));
        } catch (eppException $e) {
            if (!$response = $e->getResponse()) {
                throw $e; // unexpected error
            }

            /** @var \Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses\EppCheckTransferResponse $response */
            if ('2' !== substr((string)$response->getCode(), 0, 1)) {
                throw $e; // non 2xx errors are unrelated to the checked domain status
            }
        }

        $checkData = $response->getData();

        if (!empty($checkData['TRANSFERLOCK'])) {
            self::errorResult('Domain is currently transfer-locked', ['check_data' => $checkData]);
        }

        if ($checkData['AUTHISVALID'] === 'NO') {
            self::errorResult('EPP Code is invalid', ['check_data' => $checkData]);
        }

        if (empty($eppCode) && !empty($checkData['AUTHREQUIRED'])) {
            self::errorResult('EPP Code is required to initiate transfer', ['check_data' => $checkData]);
        }

        if (!$response->isAvailable()) {
            self::errorResult($response->getUnavailableReason(), ['check_data' => $checkData]);
        }

        return $response;
    }

    /**
     * @param int $period Registration period in years
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If NS cannot be added
     */
    public static function createDomain(
        eppConnection $connection,
        string $domainName,
        int $period,
        string $registrantId,
        string $adminContactId,
        string $billingContactId,
        string $techContactId,
        array $nameServers
    ): array {
        // Validate NameServers
        self::validateNameServers($nameServers);

        $domain = new eppDomain($domainName, $registrantId, [
            new eppContactHandle($adminContactId, 'admin'),
            new eppContactHandle($techContactId, 'tech'),
            new eppContactHandle($billingContactId, 'billing')
        ]);

        $domain->setRegistrant(new eppContactHandle($registrantId));
        $domain->setAuthorisationCode(self::generateValidAuthCode());

        // Add Name Servers
        $domain = self::addNameServers($connection, $domain, $nameServers);

        // Check if we have the name servers yet
        if (count($domain->getHosts()) < 1) {
            self::errorResult('We were unable to add name servers for the domain!', [
                'domain' => $domainName,
                'nameservers' => $nameServers
            ]);
        }

        // Set Domain Period
        $domain->setPeriod($period);
        $domain->setPeriodUnit('y');

        // Create the domain
        $create = new eppCreateDomainRequest($domain);

        /** @var \Metaregistrar\EPP\eppCreateDomainResponse $response */
        $response = $connection->request($create);

        return [
            'domain' => $response->getDomainName(),
            'created_at' => Utils::formatDate($response->getDomainCreateDate()),
            'expires_at' => Utils::formatDate($response->getDomainExpirationDate())
        ];
    }

    /**
     * Add and check name servers to a domain
     *
     * @throws \Metaregistrar\EPP\eppException
     */
    private static function addNameServers(eppConnection $connection, eppDomain $domain, array $nameServers): eppDomain
    {
        // Check our name servers
        /** @var \Illuminate\Support\Collection $nameServersCollection */
        $nameServersCollection = collect($nameServers);
        $availableHosts = self::checkHosts($connection, $nameServersCollection);

        foreach ($availableHosts as $nameServer => $available) {
            $nameServerData = $nameServersCollection->where('host', $nameServer)->first();

            // In case this host is not known and it's available to be created - attempt to create it. If it's unavailable for creation - just add it to the domain
            if ($available) {
                if (!self::createHost($connection, $nameServerData['host'], $nameServerData['ip'] ?? null)) {
                    // Problem while creating the host. Continue with the next
                    continue;
                }
            }

            // All ok, add it to the domain
            $domain->addHost(new eppHost($nameServerData['host'], $nameServerData['ip'] ?? null, null));
        }

        return $domain;
    }

    /**
     * Updates details about a domain given.
     * With this method we can update:
     *  - name servers
     *  - admin contact
     *  - billing contact
     *  - tech contact
     *  - registrant contact
     *
     * Contact IDs should be passed as identifier strings.
     *
     * @throws \Metaregistrar\EPP\eppException If command fails
     */
    public static function updateDomain(
        eppConnection $connection,
        string $domainName,
        ?array $nameServers = null,
        ?string $registrantContactId = null,
        ?string $adminContactId = null,
        ?string $billingContactId = null,
        ?string $techContactId = null
    ): eppUpdateResponse {
        // Attempt to get the domain
        $domain = new eppDomain($domainName);
        $domainInfo = new eppInfoDomainRequest($domain);

        // The data in the remove array should be deleted from the domain first and then added again
        $removeData = new eppDomain($domainName);
        $updateData = new eppDomain($domainName);
        $addData = new eppDomain($domainName);

        // Check the results and the details that we have to update
        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $connection->request($domainInfo);

        // Update Name Servers
        if (!is_null($nameServers)) {
            // Get the current name servers
            $currentNameServers = $response->getDomainNameservers();

            // Remove the current name servers
            if (is_array(($currentNameServers))) {
                foreach ($currentNameServers as $currentNameServer) {
                    $removeData->addHost(new eppHost($currentNameServer->getHostname()));
                }
            }

            // Set new name servers
            $addData = self::addNameServers($connection, $addData, $nameServers);
        }

        // Update Main Contact
        if (!is_null($registrantContactId)) {
            $updateData->setRegistrant(new eppContactHandle($registrantContactId));
        }

        // Update Admin Contact
        if (!is_null($adminContactId)) {
            // Get the admin contact ID
            $currentAdmin = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_ADMIN);

            // In case we're providing a brand new admin contact, remove the old and add the new one
            if ($currentAdmin != $adminContactId) {
                if (!empty($currentAdmin)) {
                    $removeData->addContact(new eppContactHandle($currentAdmin, eppContactHandle::CONTACT_TYPE_ADMIN));
                }

                $addData->addContact(new eppContactHandle($adminContactId, eppContactHandle::CONTACT_TYPE_ADMIN));
            }
        }

        // Update Billing Contact
        if (!is_null($billingContactId)) {
            // Get the billing contact ID
            $currentBilling = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_BILLING);

            // In case we're providing a brand new billing contact, remove the old and add the new one
            if ($currentBilling != $billingContactId) {
                if (!empty($currentBilling)) {
                    $removeData->addContact(new eppContactHandle($currentBilling, eppContactHandle::CONTACT_TYPE_BILLING));
                }

                $addData->addContact(new eppContactHandle($billingContactId, eppContactHandle::CONTACT_TYPE_BILLING));
            }
        }

        // Update Tech Contact
        if (!is_null($techContactId)) {
            // Get the billing contact ID
            $currentTech = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_TECH);

            // In case we're providing a brand new billing contact, remove the old and add the new one
            if ($currentTech != $techContactId) {
                if (!empty($currentTech)) {
                    $removeData->addContact(new eppContactHandle($currentTech, eppContactHandle::CONTACT_TYPE_TECH));
                }

                $addData->addContact(new eppContactHandle($techContactId, eppContactHandle::CONTACT_TYPE_TECH));
            }
        }

        // Save all the changes
        $update = new eppUpdateDomainRequest($domain, $addData, $removeData, $updateData);

        /** @var \Metaregistrar\EPP\eppUpdateResponse */
        return $connection->request($update);
    }

    /**
     * Checks for valid contact by ID
     */
    public static function isValidContactId(eppConnection $connection, string $contactId): bool
    {
        try {
            self::getContactInfo($connection, $contactId);
            return true;
        } catch (eppException $e) {
            return false;
        }
    }

    /**
     * Get Contact Info
     *
     * @throws \Metaregistrar\EPP\eppException If command fails E.g., if contact id is invalid
     */
    public static function getContactInfo(eppConnection $connection, string $contactId): ContactData
    {
        $request = new eppInfoContactRequest(new eppContactHandle($contactId), false);
        /** @var \Metaregistrar\EPP\eppInfoContactResponse $response */
        $response = $connection->request($request);

        return ContactData::create([
            'id' => $contactId,
            'name' => $response->getContactName(),
            'email' => $response->getContactEmail(),
            'phone' => $response->getContactVoice(),
            'organisation' => $response->getContactCompanyname(),
            'address1' => $response->getContactStreet(),
            'city' => $response->getContactCity(),
            'state' => $response->getContactProvince(),
            'postcode' => $response->getContactZipcode(),
            'country_code' => $response->getContactCountrycode(),
            'type' => $response->getContact()->getType(),
        ]);
    }

    /**
     * Return a normalized array with names servers ['host' => 'hostname', 'ip' => 'ipAddress']
     *
     * @param eppHost[] $nameServers Array with eppHost objects
     */
    private static function parseNameServers(array $nameServers): array
    {
        $result = [];

        if (count($nameServers) > 0) {
            foreach ($nameServers as $i => $ns) {
                $result['ns' . ($i + 1)] = [
                    'host' => $ns->getHostName(),
                    'ip' => $ns->getIpAddresses()
                ];
            }
        }

        return $result;
    }

    public static function checkHosts(eppConnection $connection, Collection $hosts): ?array
    {
        try {
            $checkHost = [];

            $hosts->each(function ($host) use (&$checkHost) {
                $checkHost[] = new eppHost($host['host'], $host['ip'] ?? null);
            });

            $check = new eppCheckRequest($checkHost);

            if ($response = $connection->request($check)) {
                /** @var \Metaregistrar\EPP\eppCheckResponse $response */
                return $response->getCheckedHosts();
            }

            return null;
        } catch (eppException $e) {
            return null;
        }
    }

    public static function createHost(eppConnection $connection, string $host, string $ip = null): bool
    {
        try {
            $create = new eppCreateHostRequest(new eppHost($host, $ip));

            if ($response = $connection->request($create)) {
                return true;
            }

            return false;
        } catch (eppException $e) {
            return false;
        }
    }

    /**
     * Make sure that we have the details in the right format.
     *
     * @throws \RuntimeException
     */
    public static function validateParseEppConnectionData(bool $sandbox, array $eppConnectionData): array
    {
        $environment = ($sandbox) ? 'sandbox' : 'live';

        if (!isset($eppConnectionData[$environment])) {
            throw new RuntimeException('We are unable to find details for ' . $environment . ' connection to the EPP environment!');
        }

        if (!isset($eppConnectionData[$environment]['hostname'])) {
            throw new RuntimeException('We are unable to find hostname for ' . $environment . ' connection to the EPP environment!');
        }

        if (!isset($eppConnectionData[$environment]['port'])) {
            throw new RuntimeException('We are unable to find port for ' . $environment . ' connection to the EPP environment!');
        }

        return $eppConnectionData[$environment];
    }

    /**
     * Validates the provider name servers. Just.In.Case
     *
     * @param array $nameServers
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public static function validateNameServers(array $nameServers): void
    {
        foreach ($nameServers as $nameServer) {
            if (!isset($nameServer['host'])) {
                throw (new ProvisionFunctionError('No valid host for name server found in name server configuration!'))
                    ->withData(['nameservers' => $nameServers]);
            }
        }
    }

    /**
     * Throws a ProvisionFunctionError to interrupt execution and generate an
     * error result.
     *
     * @param string $message Error result message
     * @param array $data Error data
     * @param array $debug Error debug
     * @param Throwable|null $previous Encountered exception
     *
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public static function errorResult($message, $data = [], $debug = [], ?Throwable $previous = null): void
    {
        throw (new ProvisionFunctionError($message, 0, $previous))
            ->withData($data)
            ->withDebug($debug);
    }

    /**
     * Generates a random auth code containing lowercase letters, uppercase letters, numbers and special characters.
     */
    private static function generateValidAuthCode(int $length = 12): string
    {
        return Helper::generateStrictPassword($length, true, true, true, '!@#$%^*_');
    }
}
