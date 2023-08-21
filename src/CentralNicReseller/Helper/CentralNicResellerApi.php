<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use Upmind\ProvisionBase\Helper;
use Metaregistrar\EPP\rrpproxyEppRenewalmodeRequest;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterContactParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Data\Configuration;
use CNIC\ClientFactory as CF;
use CNIC\CNR\SessionClient as CentralNicResellerClient;
use CNIC\HEXONET\Response as HexonetResponse;

/**
 * Class CentralNic Reseller API
 *
 * @package Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper
 */
class CentralNicResellerApi
{
    protected Configuration $configuration;

    protected CentralNicResellerClient $client;

    public function __construct(Configuration $configuration, LoggerInterface $logger)
    {
        $this->client = self::establishConnection($configuration, $logger);
    }

    public function __destruct()
    {
        if (isset($this->client)) {
            $this->client->logout();
        }
    }

    /**
     * Authenticate and establish a connection with the Domain Provider API and login.
     *
     * @throws ProvisionFunctionError
     */
    protected function establishConnection(Configuration $configuration, LoggerInterface $logger): CentralNicResellerClient
    {
        $client = CF::getClient([
            "registrar" => "CNR"
        ]);

        $client->setCredentials($configuration->username, $configuration->password);

        // Set Environment
        if ($configuration->sandbox) {
            $client
                ->useOTESystem()
                ->setURL('https://api-ote.rrpproxy.net/api/call.cgi');
        }

        // Set Logging and Logger Handler
        if ($configuration->debug) {
            $client->enableDebugMode(true);
            $client->setCustomLogger(new CentralNicResellerLogger($logger));
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
    private function runCommand(string $command, array $parameters = []): HexonetResponse
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

    public function initiateTransfer(
        string                 $domain,
        int                    $period,
        ?string                $eppCode,
        ?RegisterContactParams $ownerContact = null,
        ?RegisterContactParams $adminContact = null,
        ?RegisterContactParams $techContact = null,
        ?RegisterContactParams $billingContact = null
    ): string {
        if (!Utils::tldSupportsTransferContacts(Utils::getTld($domain))) {
            $ownerContact = null;
            $adminContact = null;
            $techContact = null;
            $billingContact = null;
        }

        $params = [
            'action' => 'REQUEST',
            'domain' => $domain,
            'auth' => $eppCode
        ];

        if ($period) {
            $params = array_merge($params, ['period' => $period]);
        }

        if ($ownerContact) {
            if ($ownerContact->register) {
                $params['ownercontact0'] = $this->transformContactParams($ownerContact->register);
            } elseif ($ownerContact->id) {
                $params['ownercontact0'] = $ownerContact->id;
            }
        }

        if ($adminContact) {
            if ($adminContact->register) {
                $params['admincontact0'] = $this->transformContactParams($adminContact->register);
            } elseif ($adminContact->id) {
                $params['admincontact0'] = $adminContact->id;
            }
        }

        if ($techContact) {
            if ($techContact->register) {
                $params['techcontact0'] = $this->transformContactParams($techContact->register);
            } elseif ($techContact->id) {
                $params['techcontact0'] = $techContact->id;
            }
        }

        if ($billingContact) {
            if ($billingContact->register) {
                $params['billingcontact0'] = $this->transformContactParams($billingContact->register);
            } elseif ($billingContact->id) {
                $params['billingcontact0'] = $billingContact->id;
            }
        }

        $response = $this->runCommand('TransferDomain', $params)->getHash();

        return (string)$response['PROPERTY']['JOBID'][0];
    }

    private function transformContactParams(ContactParams $contact): array
    {
        $name = $contact->name ?: $contact->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);

        return [
            'firstname' => $firstName,
            'lastname' => $lastName ?? $firstName,
            'organization' => $contact->organisation,
            'email' => $contact->email,
            'phone' => Utils::internationalPhoneToEpp($contact->phone),
            'street0' => $contact->address1,
            'city' => $contact->city,
            'state' => $contact->state ?? "",
            'zip' => $contact->postcode,
            'country' => Utils::normalizeCountryCode($contact->country_code),
        ];
    }

    /**
     * @return string Contact ID
     */
    public function createContact(ContactParams $contact): string
    {
        $result = $this->runCommand('AddContact', $this->transformContactParams($contact))->getHash();

        return $result['PROPERTY']['CONTACT'][0];
    }

    public function register(
        string                $domain,
        int                   $period,
        array                 $nameServers,
        RegisterContactParams $ownerContact,
        RegisterContactParams $adminContact,
        RegisterContactParams $techContact,
        RegisterContactParams $billingContact
    ): array {
        $params = [
            'domain' => $domain,
            'period' => $period
        ];

        if ($ownerContact->register) {
            $params['ownercontact0'] = $this->transformContactParams($ownerContact->register);
        } else {
            $params['ownercontact0'] = $ownerContact->id;
        }

        if ($adminContact->register) {
            $params['admincontact0'] = $this->transformContactParams($adminContact->register);
        } else {
            $params['admincontact0'] = $adminContact->id;
        }

        if ($techContact->register) {
            $params['techcontact0'] = $this->transformContactParams($techContact->register);
        } else {
            $params['techcontact0'] = $techContact->id;
        }

        if ($billingContact->register) {
            $params['billingcontact0'] = $this->transformContactParams($billingContact->register);
        } else {
            $params['billingcontact0'] = $billingContact->id;
        }

        $params = array_merge($params, $nameServers);

        return $this->runCommand('AddDomain', $params)->getHash();
    }

    /**
     * @param string[] $nameservers
     */
    public function updateNameservers(string $domain, array $nameservers): array
    {
        $params = ['domain' => $domain];

        foreach (array_values($nameservers) as $i => $nameserver) {
            $params['nameserver' . $i] = $nameserver;
        }

        return $this->runCommand('ModifyDomain', $params)->getHash();
    }
}
