<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\Helper;

use HEXONET\APIClient as HexonetApiClient;
use HEXONET\Response as HexonetResponse;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Hexonet\Data\Configuration;

/**
 * A helper to utilize the official Hexonet PHP SDK
 *
 *  _._     _,-'""`-._
 * (,-.`._,'(       |\`-/|
 *      `-.-' \ )-`( , o o)
 *          `-    \`_`"'-
 *
 * Class HexonetHelper
 * @package Upmind\ProvisionProviders\DomainNames\Hexonet\Helper
 */
class HexonetApi
{
    /**
     * @var HexonetApiClient
     */
    protected $client;

    public function __construct(Configuration $configuration, LoggerInterface $logger)
    {
        $this->client = self::establishConnection($configuration, $logger);
    }

    /**
     * Authenticate and establish a connection with the Domain Provider API and login.
     *
     * @throws ProvisionFunctionError
     */
    protected function establishConnection(Configuration $configuration, LoggerInterface $logger): HexonetApiClient
    {
        // Init Client
        $client = new HexonetApiClient();
        $client->setCredentials($configuration->username, $configuration->password);

        // Set Environment
        if ($configuration->sandbox) {
            $client->useOTESystem();
            $client->setURL('https://api-ote.ispapi.net/api/call.cgi');
        }

        // Set Logging and Logger Handler
        if ($configuration->debug) {
            $client->enableDebugMode(true);
            $client->setCustomLogger(new HexonetLogger($logger));
        }

        // Login
        $loginRequest = $client->login();

        // Process response
        if (!$loginRequest->isSuccess()) {
            throw ProvisionFunctionError::create('Unable to authenticate connection with provider API')
                ->withData([
                    'description' => $loginRequest->getDescription(),
                    'response' => $loginRequest->getHash(),
                ]);
        }

        return $client;
    }

    /**
     * Run a command against the API
     *
     * @param string $command
     * @param array $parameters
     * @return HexonetResponse
     */
    public function runCommand(string $command, array $parameters = []): HexonetResponse
    {
        // Build the Payload
        $requestData = [
            'COMMAND' => $command
        ];

        // Add additional parameters to the request
        if (count($parameters) > 0) {
            foreach ($parameters as $k => $v) {
                $requestData[$k] = $v;
            }
        }

        // Send Payload
        $response = $this->client->request($requestData);

        // Process response
        if (!$response->isSuccess()) {
            throw ProvisionFunctionError::create(sprintf('Provider API Error: %s', $response->getDescription()))
                ->withData([
                    'response' => $response->getHash(),
                ]);
        }

        return $response;
    }

    /**
     * After we finished with API calls, we need to close the connection.
     */
    public function terminateConnection(): void
    {
        $logoutRequest = $this->client->logout();

        // if (!$logoutRequest->isSuccess()) {
        //     throw new RuntimeException('There was a problem while terminating the Hexonet HTTPS API Session!');
        // }
    }

    public function markDomainRenewalAsPaid(string $domain): array
    {
        return $this->runCommand('PayDomainRenewal', [
            'domain' => $domain,
        ])->getHash();
    }

    public function statusDomain(string $domain): array
    {
        $result = $this->runCommand('StatusDomain', [
            'domain' => $domain,
        ])->getHash();

        return $result['PROPERTY'];
    }

    /**
     * @param string $domain
     * @param Nameserver[] $nameservers
     *
     * @return Nameserver[] New nameservers
     */
    public function setNameservers(string $domain, array $nameservers): array
    {
        $nameservers = array_values($nameservers);

        $params = [
            'domain' => $domain,
        ];

        foreach ($nameservers as $i => $nameserver) {
            $params['nameserver' . $i] = $nameserver->host;
        }

        $result = $this->runCommand('ModifyDomain', $params); // doesnt actually confirm the new NS

        return $nameservers;
    }

    public function updateRegistrant(
        string $domain,
        ContactParams $contact
    ): ContactResult {
        $nameParts = explode(' ', $contact->name ?: $contact->organisation);

        $params = [
            'domain' => $domain,
            'ownercontact0' => [
                'firstname' => array_shift($nameParts),
                'lastname' => implode(' ', $nameParts),
                'organization' => $contact->organisation,
                'street' => $contact->address1,
                'city' => $contact->city,
                'state' => $contact->has('state') ? $contact->state : null,
                'zip' => $contact->postcode,
                'country' => Utils::normalizeCountryCode($contact->country_code),
                'phone' => $contact->phone,
                // 'fax' => ,
                'email' => $contact->email,
            ],
        ];

        $result = $this->runCommand('ModifyDomain', $params); // nothing useful in the result

        return ContactResult::create($contact);

        throw ProvisionFunctionError::create('update registrant')->withData(['result' => $result->getHash()]);
    }

    /**
     * Returns an associative array with contacts
     *
     * @param array $filters
     * @return array
     */
    public function getContacts(array $filters = []): array
    {
        // TODO: Apply filters (by X criteria, limit (pagination) etc)

        // Get Raw Response
        $rawContacts = $this->runCommand('QueryContactList', [
            'wide' => 1
        ])->getListHash();

        // Return the processed contacts data
        $processed = [];

        if (isset($rawContacts['LIST']) && is_array($rawContacts['LIST'])) {
            foreach ($rawContacts['LIST'] as $rawContact) {
                $processed[] = [
                    'contact_id' => $rawContact['CONTACT'],
                    'name' => $rawContact['CONTACTFIRSTNAME'] . ' ' . $rawContact['CONTACTLASTNAME'],
                    'email' => $rawContact['CONTACTEMAIL'],
                    'phone' => $rawContact['CONTACTPHONE'],
                    'company' => $rawContact['CONTACTORGANIZATION'],
                    'address1' => $rawContact['CONTACTSTREET'],
                    'city' => $rawContact['CONTACTCITY'],
                    'postcode' => $rawContact['CONTACTZIP'],
                    'country_code' => $rawContact['CONTACTCOUNTRY'],
                ];
            }
        }

        return $processed;
    }

    /**
     * Unlocks/Locks a domain for transfer
     *
     * @param string $domain
     * @param bool  $lock
     * @return array
     */
    public function setTransferLock(string $domain, bool $lock): array
    {
        // Get Raw Response
        $additionalParams = [
            'domain' => $domain,
            'transferlock' => (int) $lock
        ];

        $toggle = $this->runCommand('ModifyDomain', $additionalParams);

        return $additionalParams;
    }

    /**
     * Sets renewal mode to autoexpire or autorenew
     *
     * @param string $domain
     * @param bool $autoRenew [true - autorenew; false - autoexpire]
     * @return array
     */
    public function setRenewalMode(string $domain, bool $autoRenew): array
    {
        // Get Raw Response
        $additionalParams = [
            'domain' => $domain,
            'renewalMode' => ($autoRenew) ? 'AUTORENEW' : 'AUTOEXPIRE'
        ];

        $setRenewal = $this->runCommand('SetDomainRenewalMode', $additionalParams);

        return $additionalParams;
    }

    /**
     * Returns domain list from the account
     *
     * @param array|null    $filters
     * @return array
     */
    public function listDomains(?array $filters = []): array
    {
        // TODO: Apply filters (by X criteria, limit (pagination) etc)

        // Get Raw Response
        $rawDomains = $this->runCommand('QueryDomainList', [
            'wide' => 1,
        ])->getListHash();

        // Process the response, adding the minimum-required info for a domain
        $processed = [];

        if (isset($rawDomains['LIST']) && is_array($rawDomains['LIST'])) {
            foreach ($rawDomains['LIST'] as $rawDomain) {
                // Get TLD and SLD
                $parts = Utils::getSldTld($rawDomain['DOMAIN']);

                $processed[] = [
                    'sld' => $parts['sld'],
                    'tld' => $parts['tld'],
                    'domain' => $rawDomain['DOMAIN'],
                    'created_at' => $rawDomain['DOMAINCREATEDDATE'],
                    'expires_at' => Utils::formatDate($rawDomain['DOMAINEXPIRATIONDATE']),
                ];
            }
        }

        return $processed;
    }
}
