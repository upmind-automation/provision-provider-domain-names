<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\Helper;

use CNIC\ClientFactory;
use CNIC\HEXONET\SessionClient as HexonetApiClient;
use CNIC\HEXONET\Response as HexonetResponse;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
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
 * @link https://github.com/centralnicgroup-public/hexonet-api-documentation/blob/master/API/DOMAIN
 */
class HexonetApi
{
    /**
     * @var \CNIC\HEXONET\SessionClient
     */
    protected $client;

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function __construct(Configuration $configuration, LoggerInterface $logger)
    {
        $this->client = $this->establishConnection($configuration, $logger);
    }

    /**
     * Authenticate and establish a connection with the Domain Provider API and login.
     *
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function establishConnection(Configuration $configuration, LoggerInterface $logger): HexonetApiClient
    {
        $client = ClientFactory::getClient(
            [
                'registrar' => 'HEXONET',
                'username' => $configuration->username,
                'password' => $configuration->password,
                'sandbox' => $configuration->sandbox,
                'logging' => true,
            ],
            new HexonetLogger($logger)
        );

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
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
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
        $this->client->logout();
    }

    /**
     * @link https://github.com/centralnicgroup-public/hexonet-api-documentation/blob/master/API/DOMAIN/TRANSFERDOMAIN.md
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function initiateTransfer(
        string $domain,
        int $period,
        ?string $eppCode,
        ?ContactParams $ownerContact = null,
        ?ContactParams $adminContact = null,
        ?ContactParams $techContact = null,
        ?ContactParams $billingContact = null,
        ?bool $userTransfer = null
    ): array {
        if ($userTransfer === null) {
            $userTransfer = (bool) Arr::get(
                $this->checkTransfer($domain, $eppCode),
                'PROPERTY.USERTRANSFERREQUIRED.0'
            );
        }

        $params = [
            'action' => $userTransfer ? 'USERTRANSFER' : 'REQUEST',
            'domain' => $domain,
            'auth' => $eppCode,
            'period' => $period,
        ];

        if ($ownerContact) {
            $params['ownercontact0'] = $this->transformContactParams($ownerContact);
        }

        if ($adminContact) {
            $params['admincontact0'] = $this->transformContactParams($adminContact);
        }

        if ($techContact) {
            $params['techcontact0'] = $this->transformContactParams($techContact);
        }

        if ($billingContact) {
            $params['billingcontact0'] = $this->transformContactParams($billingContact);
        }

        return $this->runCommand('TransferDomain', $params)->getHash();
    }

    /**
     * Note, this function is way slower than the EPP equivalent for some reason!
     *
     * https://github.com/centralnicgroup-public/hexonet-api-documentation/blob/master/API/DOMAIN/TRANSFER/CHECKDOMAINTRANSFER.md
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function checkTransfer(string $domain, ?string $eppCode = null): array
    {
        $response = $this->runCommand('CheckDomainTransfer', [
            'domain' => $domain,
            'auth' => $eppCode,
        ]);

        $status = $response->getCode();
        $check = $response->getHash();

        if (Arr::get($check, 'PROPERTY.TRANSFERLOCK.0')) {
            throw ProvisionFunctionError::create('Domain is locked')->withData([
                'response' => $check,
            ]);
        }

        if (Arr::get($check, 'PROPERTY.AUTHISVALID.0') === 'NO') {
            throw ProvisionFunctionError::create('Invalid EPP Code')->withData([
                'response' => $check,
            ]);
        }

        if (empty($eppCode) && Arr::get($check, 'PROPERTY.AUTHREQUIRED.0')) {
            throw ProvisionFunctionError::create('EPP Code is required to initiate transfer')->withData([
                'response' => $check,
            ]);
        }

        if ($status != 218) {
            $errorMessage = 'Domain not transferrable';

            if (preg_match('/^(?:[\w ]+); ([\w ]+)$/', $response->getDescription(), $matches)) {
                $errorMessage = ucfirst(strtolower($matches[1]));
            }

            throw ProvisionFunctionError::create($errorMessage)->withData([
                'response' => $check,
            ]);
        }

        return $check;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function markDomainRenewalAsPaid(string $domain): array
    {
        return $this->runCommand('PayDomainRenewal', [
            'domain' => $domain,
        ])->getHash();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrant(
        string $domain,
        ContactParams $contact
    ): ContactResult {
        $params = [
            'domain' => $domain,
            'ownercontact0' => $this->transformContactParams($contact),
        ];

        $this->runCommand('ModifyDomain', $params); // nothing useful in the result

        return ContactResult::create($contact);
    }

    /**
     * Returns an associative array with contacts
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
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
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
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
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRenewalMode(string $domain, bool $autoRenew): array
    {
        // Get Raw Response
        $additionalParams = [
            'domain' => $domain,
            'renewalMode' => ($autoRenew) ? 'AUTORENEW' : 'AUTOEXPIRE'
        ];

        $this->runCommand('SetDomainRenewalMode', $additionalParams);

        return $additionalParams;
    }

    /**
     * Returns domain list from the account
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
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

    /**
     * Transform the given contact params to a params array in the correct format
     * for the Hexonet API.
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function transformContactParams(ContactParams $contact): array
    {
        $name = $contact->name ?: $contact->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);

        return [
            'name' => $name,
            'firstname' => $firstName,
            'lastname' => !empty($lastName) ? $lastName : $firstName,
            'organization' => $contact->organisation,
            'email' => $contact->email,
            'phone' => Utils::internationalPhoneToEpp($contact->phone),
            'street' => $contact->address1,
            'city' => $contact->city,
            'state' => $contact->state,
            'zip' => $contact->postcode,
            'country' => Utils::normalizeCountryCode($contact->country_code),
        ];
    }
}
