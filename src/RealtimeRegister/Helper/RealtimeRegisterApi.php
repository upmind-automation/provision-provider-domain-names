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
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
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

    public function register(string $domainName, array $contacts): void
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

        if ($result === '') {
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
            'organisation' => $contact['organization'] ?? '-',
            'name' => $contact['name'],
            'address1' => $contact['addressLine'][0],
            'city' => $contact['city'],
            'state' => $contact['state'] ?? '-',
            'postcode' => $contact['postalCode'],
            'country_code' => Utils::normalizeCountryCode($contact['country']),
            'email' => $contact['email'],
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

    public function getContact(string $handle): ?ContactData
    {
        $command = "v2/customers/{$this->configuration->customer}/contacts/{$handle}";

        try {
            $response = $this->makeRequest($command);
            return $this->parseContact($response);
        } catch (RequestException $e) {
            return null;
        }
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

            $this->createHost($ns['host'], $ns['ip']);
        }

        $body = array('ns' => $hosts);

        $this->makeRequest($command, null, $body, "POST");

        $command = "/v2/domains/{$domainName}";
        $response = $this->makeRequest($command);

        return $this->parseNameservers($response['ns']);
    }

    public function createHost(string $host, ?string $ip = null)
    {
        if (!$this->getHost($host)) {
            if (!$ip) {
                throw new ProvisionFunctionError(sprintf('IP address for %s host must not be null', $host), 0, null);
            }

            $command = "/v2/hosts/{$host}";

            $version = 'V4';
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $version = 'V6';
            }

            $body = array('addresses' => [['address' => $ip,
                'ipVersion' => $version
            ]]);

            $this->makeRequest($command, null, $body, "POST");
        }
    }

    public function getHost(string $hostName): ?array
    {
        $command = "/v2/hosts/{$hostName}";

        try {
            return $this->makeRequest($command);
        } catch (RequestException $e) {
            return null;
        }
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

    public function poll(int $limit, ?Carbon $since): ?array
    {
        $command = "/v2/processes";

        $notifications = [];

        $params = [
            'limit' => $limit,
            'type' => 'domain',
            'action:in' => 'incomingTransfer,incomingInternalTransfer,renew,delete',
        ];

        if ($since != null) {
            $params = array_merge($params, ['createdDate:gt' => $since->format('Y-m-d\TH:i:s\Z')]);
        }

        $response = $this->makeRequest($command, $params);

        $countRemaining = $response['pagination']['total'];

        if (isset($response['entities'])) {
            foreach ($response['entities'] as $entity) {
                $messageId = $entity['id'];

                $type = $this->mapNotificationType($entity['action']);

                if (is_null($type)) {
                    // this message is irrelevant
                    continue;
                }

                $message = 'Domain Process';
                $domain = $entity['identifier'] ?? '';
                $messageDateTime = Carbon::parse($entity['createdDate']);


                $notifications[] = DomainNotification::create()
                    ->setId($messageId)
                    ->setType(DomainNotification::TYPE_TRANSFER_IN)
                    ->setMessage($message)
                    ->setDomains([$domain])
                    ->setCreatedAt($messageDateTime)
                    ->setExtra(['response' => json_encode($entity)]);
            }
        }

        return [
            'count_remaining' => $countRemaining,
            'notifications' => $notifications,
        ];
    }

    private function mapNotificationType(string $action): ?string
    {
        switch ($action) {
            case 'incomingInternalTransfer':
            case 'incomingTransfer':
                $type = DomainNotification::TYPE_TRANSFER_IN;
                break;
            case 'renew':
                $type = DomainNotification::TYPE_RENEWED;
                break;
            case 'delete':
                $type = DomainNotification::TYPE_DELETED;
                break;
        }

        return $type ?? null;
    }
}
