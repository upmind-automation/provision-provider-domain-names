<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Data\Configuration;
use Upmind\ProvisionBase\Helper;

/**
 * Class RealtimeRegisterApi
 *
 * @package Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Helper
 */
class RealtimeRegisterApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'REGISTRANT';
    public const CONTACT_TYPE_TECH = 'TECH';
    public const CONTACT_TYPE_ADMIN = 'ADMIN';
    public const CONTACT_TYPE_BILLING = 'BILLING';

    protected array $lockedStatuses = [
        'CLIENT_TRANSFER_PROHIBITED',
        'CLIENT_UPDATE_PROHIBITED',
    ];

    protected Client $client;

    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @throws \Throwable
     */
    public function checkMultipleDomains(array $domainList): array
    {
        $dacDomains = [];

        foreach ($domainList as $domainName) {
            $command = "/v2/domains/{$domainName}/check";

            try {
                $response = $this->makeRequest($command);

                $available = (boolean)$response['available'];

                $dacDomains[] = DacDomain::create([
                    'domain' => $domainName,
                    'description' => $response['reason'] ?? sprintf(
                            'Domain is %s to register',
                            $available ? 'available' : 'not available'
                        ),
                    'tld' => Utils::getTld($domainName),
                    'can_register' => $available,
                    'can_transfer' => !$available,
                    'is_premium' => $response['premium'],
                ]);
            } catch (\Throwable $e) {
                $response = $e->getResponse();
                $body = trim($response->getBody()->__toString());
                $responseData = json_decode($body, true);
                if ($responseData['type'] == 'UnsupportedTld') {
                    $dacDomains[] = DacDomain::create([
                        'domain' => $domainName,
                        'description' => 'Domain is not available to register. Unsupported TLD',
                        'tld' => Utils::getTld($domainName),
                        'can_register' => false,
                        'can_transfer' => false,
                        'is_premium' => false,
                    ]);
                } else {
                    throw $e;
                }
            }
        }

        return $dacDomains;
    }

    public function register(string $domainName, array $contacts, array $nameServers): void
    {
        $command = "/v2/domains/{$domainName}";

        $queryContacts = [];
        foreach ($contacts as $type => $handle) {
            if ($type == self::CONTACT_TYPE_REGISTRANT) {
                continue;
            }

            $queryContacts[] = [
                'role' => $type,
                'handle' => $handle
            ];
        }

        $body = array(
            'customer' => $this->configuration->customer,
            'period' => 12,
            'registrant' => $contacts[self::CONTACT_TYPE_REGISTRANT],
            'contacts' => $queryContacts,
        );

        $this->makeRequest($command, null, $body, "POST");

        $this->updateNameservers(
            $domainName,
            $nameServers,
        );
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

        if (!$result) {
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

        return $parsedResult;
    }

    public function getDomainInfo(string $domainName): array
    {
        $command = "/v2/domains/{$domainName}";
        $response = $this->makeRequest($command);

        $lock = $this->parseLockStatus($response['status']);

        $admin = null;
        $billing = null;
        $tech = null;

        foreach ($response['contacts'] as $contact) {
            switch ($contact['role']) {
                case 'ADMIN':
                    $admin = $this->getContact($contact['handle']);
                    break;
                case 'BILLING':
                    $billing = $this->getContact($contact['handle']);
                    break;
                case 'TECH':
                    $tech = $this->getContact($contact['handle']);
                    break;
            }
        }
        $ns = null;
        if (isset($response['ns'])) {
            $ns = NameserversResult::create($this->parseNameservers($response['ns']));
        }

        return [
            'id' => 'N/A',
            'domain' => (string)$response['domainName'],
            'statuses' => $response['status'],
            'locked' => $lock,
            'registrant' => $this->getContact($response['registrant']) ?? null,
            'billing' => $billing,
            'tech' => $tech,
            'admin' => $admin,
            'ns' => $ns,
            'created_at' => isset($response['createdDate']) ? Utils::formatDate((string)$response['createdDate']) : null,
            'updated_at' => isset($response['updatedDate']) ? Utils::formatDate((string)$response['updatedDate']) : null,
            'expires_at' => isset($response['expiryDate']) ? Utils::formatDate($response['expiryDate']) : null,
        ];
    }

    private function parseNameServers(array $nameServers): array
    {
        $result = [];

        if (count($nameServers) > 0) {
            foreach ($nameServers as $i => $ns) {
                $host = $this->getHost($ns);

                $result['ns' . ($i + 1)] = [
                    'host' => $ns,
                    'ip' => $host['addresses'][0]['address'] ?? null,
                ];
            }
        }

        return $result;
    }

    private function parseContact(array $contact): ContactData
    {
        return ContactData::create([
            'organisation' => (string)$contact['organization'] ?: '-',
            'name' => $contact['name'],
            'address1' => (string)$contact['addressLine'][0],
            'city' => (string)$contact['city'],
            'state' => $contact['state'] ?? '-',
            'postcode' => (string)$contact['postalCode'],
            'country_code' => Utils::normalizeCountryCode((string)$contact['country']),
            'email' => (string)$contact['email'],
            'phone' => $contact['voice'] ?? null,
        ]);
    }

    private function parseLockStatus(array $statuses): bool
    {
        if (array_intersect($this->lockedStatuses, $statuses) == $this->lockedStatuses) {
            return true;
        }

        return false;
    }

    public function getContact(string $handle): ContactData
    {
        $command = "v2/customers/{$this->configuration->customer}/contacts/{$handle}";
        $response = $this->makeRequest($command);

        return $this->parseContact($response);
    }

    public function getDomainEppCode(string $domainName): ?string
    {
        $command = "/v2/domains/{$domainName}";
        $response = $this->makeRequest($command);

        if (isset($response['authcode'])) {
            return $response['authcode'];
        }

        return null;
    }

    public function setAuthCode(string $domainName): ?string
    {
        $command = "/v2/domains/{$domainName}/update";
        $body = array('authcode' => '');

        $this->makeRequest($command, null, $body, "POST");

        return $this->getDomainEppCode($domainName);
    }

    public function setRenewalMode(string $domainName, bool $autoRenew)
    {
        $command = "/v2/domains/{$domainName}/update";
        $body = array('autoRenew' => $autoRenew);

        $this->makeRequest($command, null, $body, "POST");
    }

    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $command = "/v2/domains/{$domainName}";
        $response = $this->makeRequest($command);
        $statuses = $response['status'];

        if ($lock) {
            $statuses = array_unique(array_merge($statuses, $this->lockedStatuses));
        } else {
            $statuses = array_diff($statuses, $this->lockedStatuses);
        }

        $command = "/v2/domains/{$domainName}/update";

        $body = array('status' => array_values($statuses));

        $this->makeRequest($command, null, $body, "POST");
    }

    public function renew(string $domainName, int $period): void
    {
        $command = "/v2/domains/{$domainName}/renew";

        $body = array('period' => $period * 12);

        $this->makeRequest($command, null, $body, "POST");
    }

    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $command = "/v2/domains/{$domainName}/update";

        $hosts = [];
        foreach ($nameservers as $ns) {
            $hosts[] = $ns['host'];
            try {
                $this->getHost($ns['host']);
            } catch (RequestException $e) {
                if (!$ns['ip']) {
                    throw new ProvisionFunctionError(sprintf('IP address for %s host must not be null', $ns['host']), 0, null);
                }

                $this->createHost($ns['host'], $ns['ip']);
            }
        }

        $body = array('ns' => $hosts);

        $this->makeRequest($command, null, $body, "POST");

        $command = "/v2/domains/{$domainName}";
        $response = $this->makeRequest($command);

        return $this->parseNameservers($response['ns']);
    }

    private function createHost(string $hostName, string $ip): void
    {
        $command = "/v2/hosts/{$hostName}";

        $version = 'V4';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $version = 'V6';
        }

        $body = array('addresses' => [['address' => $ip,
            'ipVersion' => $version
        ]]);

        $this->makeRequest($command, null, $body, "POST");
    }

    private function getHost(string $hostName): ?array
    {
        $command = "/v2/hosts/{$hostName}";

        return $this->makeRequest($command);
    }

    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): ContactData
    {
        $command = "/v2/domains/{$domainName}/update";

        $body = [
            'registrant' => $this->createContact($contactParams)
        ];

        $this->makeRequest($command, null, $body, "POST");

        return $this->getDomainInfo($domainName)['registrant'];
    }

    public function createContact(ContactParams $params): string
    {
        $handle = uniqid();
        $command = "v2/customers/{$this->configuration->customer}/contacts/{$handle}";

        $body = $this->setContactParams($params);
        $this->makeRequest($command, null, $body, "POST");

        return $handle;
    }

    private function setContactParams(ContactParams $contactParams): array
    {
        return [
            'addressLine' => [$contactParams->address1],
            'city' => $contactParams->city,
            'country' => Utils::normalizeCountryCode($contactParams->country_code),
            'postalCode' => $contactParams->postcode,
            'state' => $contactParams->state ?? null,
            'organization' => $contactParams->organisation ?? null,
            'name' => $contactParams->name ?? $contactParams->organisation,
            'email' => $contactParams->email,
            'voice' => Utils::internationalPhoneToEpp($contactParams->phone),
        ];
    }

    public function initiateTransfer(string $domainName, string $eppCode, array $contacts): string
    {
        $command = "/v2/domains/{$domainName}/transfer";

        $queryContacts = [];
        foreach ($contacts as $type => $handle) {
            if ($type == self::CONTACT_TYPE_REGISTRANT) {
                continue;
            }

            $queryContacts[] = [
                'role' => $type,
                'handle' => $handle
            ];
        }

        $body = [
            'customer' => $this->configuration->customer,
            'registrant' => $contacts[self::CONTACT_TYPE_REGISTRANT],
            'period' => 12,
            'contacts' => $queryContacts,
            'authcode' => $eppCode,
        ];

        $response = $this->makeRequest($command, null, $body, "POST");

        return (string)$response['processId'];
    }
}
