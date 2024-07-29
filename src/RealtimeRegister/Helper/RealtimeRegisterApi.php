<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Response;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Data\Configuration;

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
     * @param string[]  $domainList
     *
     * @return DacDomain[]
     * @throws \Throwable
     */
    public function checkMultipleDomains(array $domainList): array
    {
        $checkPromises = array_map(function ($domainName): Promise {
            /** @var \GuzzleHttp\Promise\Promise $promise */
            $promise = $this->asyncRequest("/v2/domains/{$domainName}/check")
                ->then(function (array $data) use ($domainName): DacDomain {
                    $available = (bool)$data['available'];

                    return DacDomain::create([
                        'domain' => $domainName,
                        'description' => $data['reason'] ?? sprintf(
                            'Domain is %s to register',
                            $available ? 'available' : 'not available'
                        ),
                        'tld' => Utils::getTld($domainName),
                        'can_register' => $available,
                        'can_transfer' => !$available,
                        'is_premium' => $data['premium'] ?? false,
                    ]);
                })
                ->otherwise(function (Throwable $e) use ($domainName): DacDomain {
                    if (!$e instanceof ClientException) {
                        throw $e;
                    }

                    $responseBody = trim($e->getResponse()->getBody()->__toString());
                    $data = json_decode($responseBody, true);

                    return DacDomain::create([
                        'domain' => $domainName,
                        'description' => $data['message'] ?? 'Unknown error',
                        'tld' => Utils::getTld($domainName),
                        'can_register' => false,
                        'can_transfer' => false,
                        'is_premium' => false,
                    ]);
                });

            return $promise;
        }, $domainList);

        return PromiseUtils::all($checkPromises)->wait();
    }

    /**
     * @param array<string,int>|string[] $contacts
     * @param string[] $nameservers
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(string $domainName, array $contacts, array $nameservers): void
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

        $body = [
            'customer' => $this->configuration->customer,
            'period' => 12,
            'registrant' => $contacts[self::CONTACT_TYPE_REGISTRANT],
            'contacts' => $queryContacts,
            'ns' => $nameservers,
        ];

        $this->makeRequest($command, null, $body, "POST");
    }

    /**
     * Method returns a Promise with response of either null or array of response data
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function asyncRequest(
        string $command,
        ?array $params = null,
        ?array $body = null,
        string $method = 'GET'
    ): Promise {
        $requestParams = [];

        if ($params) {
            $requestParams['query'] = $params;
        }

        if ($body) {
            $requestParams['json'] = $body;
        }

        /** @var \GuzzleHttp\Promise\Promise $promise */
        $promise = $this->client->requestAsync($method, $command, $requestParams)
            ->then(function (Response $response): ?array {
                $responseBody = trim($response->getBody()->__toString());

                if ($responseBody === '') {
                    return null;
                }

                return $this->parseResponseData($responseBody);
            });

        return $promise;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(
        string $command,
        ?array $params = null,
        ?array $body = null,
        string $method = 'GET'
    ): ?array {
        return $this->asyncRequest($command, $params, $body, $method)->wait();
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

        return $parsedResult;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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
            'organisation' => $contact['organization'] ?? null,
            'name' => $contact['name'],
            'address1' => $contact['addressLine'][0],
            'city' => $contact['city'],
            'state' => $contact['state'] ?? null,
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainEppCode(string $domainName): ?string
    {
        $command = "/v2/domains/{$domainName}";
        $response = $this->makeRequest($command);

        if (isset($response['authcode'])) {
            return $response['authcode'];
        }

        return null;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAuthCode(string $domainName): ?string
    {
        $command = "/v2/domains/{$domainName}/update";
        $body = ['authcode' => ''];

        $this->makeRequest($command, null, $body, "POST");

        return $this->getDomainEppCode($domainName);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRenewalMode(string $domainName, bool $autoRenew)
    {
        $command = "/v2/domains/{$domainName}/update";
        $body = ['autoRenew' => $autoRenew];

        $this->makeRequest($command, null, $body, "POST");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function pushTransfer(string $domainName, string $registrar): void
    {
        $this->makeRequest(
            sprintf('/v2/domains/%s/transfer/push', $domainName),
            null,
            ['recipient' => $registrar],
            'POST'
        );
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

        $body = ['status' => array_values($statuses)];

        $this->makeRequest($command, null, $body, "POST");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(string $domainName, int $period): void
    {
        $command = "/v2/domains/{$domainName}/renew";

        $body = ['period' => $period * 12];

        $this->makeRequest($command, null, $body, "POST");
    }

    /**
     * @param string[] $nameservers
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $command = "/v2/domains/{$domainName}/update";

        $body = ['ns' => $nameservers];

        $this->makeRequest($command, null, $body, "POST");

        $command = "/v2/domains/{$domainName}";
        $response = $this->makeRequest($command);

        return $this->parseNameservers($response['ns']);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

            $body = ['addresses' => [['address' => $ip,
                'ipVersion' => $version
            ]]];

            $this->makeRequest($command, null, $body, "POST");
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getHost(string $hostName): ?array
    {
        $command = "/v2/hosts/{$hostName}";

        try {
            return $this->makeRequest($command);
        /** @phpstan-ignore catch.neverThrown */
        } catch (RequestException) {
            return null;
        }
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): ContactData
    {
        $command = "/v2/domains/{$domainName}/update";

        $body = [
            'registrant' => $this->createContact($contactParams)
        ];

        $this->makeRequest($command, null, $body, "POST");

        return $this->getDomainInfo($domainName)['registrant'];
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function createContact(ContactParams $params): string
    {
        $handle = uniqid();
        $command = "v2/customers/{$this->configuration->customer}/contacts/{$handle}";

        $body = $this->setContactParams($params);
        $this->makeRequest($command, null, $body, "POST");

        return $handle;
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

        if (!$this->domainAllowsTransferPeriod($domainName)) {
            unset($body['period']);
        }

        $response = $this->makeRequest($command, null, $body, "POST");

        return (string)$response['processId'];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    private function domainAllowsTransferPeriod(string $domain): bool
    {
        return !in_array(Utils::getRootTld($domain), [
            'nl',
            'nu',
        ]);
    }
}
