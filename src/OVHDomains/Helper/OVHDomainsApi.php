<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OVHDomains\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use GuzzleHttp\Exception\RequestException;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterContactParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\OVHDomains\Data\Configuration;
use Ovh\Api as BaseOVHClient;

/**
 * OVH Domains API client.
 */
class OVHDomainsApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'OWNER_CONTACT';
    public const CONTACT_TYPE_TECH = 'TECH_ACCOUNT';
    public const CONTACT_TYPE_ADMIN = 'ADMIN_ACCOUNT';
    public const CONTACT_TYPE_BILLING = 'BILLING_ACCOUNT';

    protected BaseOVHClient $client;
    protected OVHClient $asyncClient;

    protected Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
        $this->client = self::establishConnection($configuration);
        $this->asyncClient = self::establishConnection($configuration, true);
    }

    private static function establishConnection(Configuration $configuration, ?bool $async = false)
    {
        if ($async) {
            return new OVHClient(
                $configuration->api_key,
                $configuration->api_secret,
                'ovh-eu',
                $configuration->consumer_key);
        }

        return new BaseOVHClient(
            $configuration->api_key,
            $configuration->api_secret,
            'ovh-eu',
            $configuration->consumer_key
        );
    }

    /**
     * @param string[] $domains
     * @return array
     * @throws Throwable
     */
    public function checkMultipleDomains(array $domains): array
    {
        $dacDomains = [];

        foreach ($domains as $domain) {
            try {
                $this->client->get("/domain/{$domain}");

                $dacDomains[] = DacDomain::create([
                    'domain' => $domain,
                    'description' => 'Domain is not available to register',
                    'tld' => Utils::getTld($domain),
                    'can_register' => false,
                    'can_transfer' => true,
                    'is_premium' => false,
                ]);

            } catch (\Throwable $e) {
                if ($e->getCode() == 404) {
                    $dacDomains[] = DacDomain::create([
                        'domain' => $domain,
                        'description' => 'Domain is available to register',
                        'tld' => Utils::getTld($domain),
                        'can_register' => true,
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


    /**
     * @param string[] $domainList
     *
     * @return DacDomain[]
     * @throws Throwable
     */
    public function checkMultipleDomainsAsync(array $domainList): array
    {
        $checkPromises = array_map(function ($domainName): Promise {
            return $this->asyncClient->getAsync("/domain/{$domainName}")
                ->then(function () use ($domainName): DacDomain {
                    return DacDomain::create([
                        'domain' => $domainName,
                        'description' => 'Domain is not available to register',
                        'tld' => Utils::getTld($domainName),
                        'can_register' => false,
                        'can_transfer' => true,
                        'is_premium' => false,
                    ]);
                })
                ->otherwise(function (Throwable $e) use ($domainName): DacDomain {
                    if (!$e instanceof ClientException) {
                        throw $e;
                    }

                    if ($e->getCode() == 404) {
                        return DacDomain::create([
                            'domain' => $domainName,
                            'description' => 'Domain is available to register',
                            'tld' => Utils::getTld($domainName),
                            'can_register' => true,
                            'can_transfer' => false,
                            'is_premium' => false,
                        ]);
                    }

                    throw $e;
                });
        }, $domainList);

        return PromiseUtils::all($checkPromises)->wait();
    }

    /**
     * @param string $domainName
     * @param int $years
     * @param RegisterContactParams[] $contacts
     * @return string
     * @throws Throwable
     */
    public function register(string $domainName, int $years, array $contacts): string
    {
        $cartId = $this->createCart();

        $itemId = $this->addDomainToCart($domainName, $years, $cartId);

        $this->setConfiguration($contacts, $cartId, $itemId);

        $this->client->get("/order/cart/{$cartId}/item/{$itemId}",);
        $this->client->get("/order/cart/{$cartId}/checkout",);

        $checkout = $this->client->post("/order/cart/{$cartId}/checkout",
            array(
                'waiveRetractationPeriod' => false,
                'autoPayWithPreferredPaymentMethod' => false,
            )
        );

        return $checkout['url'];
    }


    /**
     * @param RegisterContactParams[] $contacts
     * @param string $cartId
     * @param int $itemId
     * @param string|null $eppCode
     * @return void
     * @throws Throwable
     */
    private function setConfiguration(array $contacts, string $cartId, int $itemId, ?string $eppCode = null): void
    {
        $requiredConfigurations = $this->client->get("/order/cart/{$cartId}/item/{$itemId}/requiredConfiguration");

        foreach ($requiredConfigurations as $configuration) {
            if ($eppCode) {
                $this->client->post("/order/cart/{$cartId}/item/{$itemId}/configuration",
                    array(
                        'label' => 'AUTH_INFO',
                        'value' => $eppCode
                    )
                );

                return;
            }

            if ($configuration['label'] === 'OWNER_LEGAL_AGE') {
                $this->client->post("/order/cart/{$cartId}/item/{$itemId}/configuration",
                    array(
                        'label' => 'OWNER_LEGAL_AGE',
                        'value' => true
                    ));
            }

            if ($configuration['label'] === 'ACCEPT_CONDITIONS') {
                $this->client->post("/order/cart/{$cartId}/item/{$itemId}/configuration",
                    array(
                        'label' => 'ACCEPT_CONDITIONS',
                        'value' => true
                    ));
            }

            if (in_array($configuration['label'], [self::CONTACT_TYPE_REGISTRANT, self::CONTACT_TYPE_TECH, self::CONTACT_TYPE_BILLING, self::CONTACT_TYPE_ADMIN])) {
                $contactId = $this->getContactId($contacts[$configuration['label']], $configuration['label']);

                if (isset($contactId) && $contactId != null) {
                    if ($configuration['label'] == self::CONTACT_TYPE_REGISTRANT) {
                        $contactValue = '/me/contact/' . $contactId;
                    } else {
                        $contactValue = $contactId;
                    }

                    $this->client->post("/order/cart/{$cartId}/item/{$itemId}/configuration",
                        array(
                            'label' => $configuration['label'],
                            'value' => $contactValue
                        )
                    );
                }
            }
        }
    }

    /**
     * @throws Throwable
     */
    private function getContactId(RegisterContactParams $contact, string $type): ?string
    {
        if ($contact->id) {
            return $contact->id;
        } else {
            if ($type === self::CONTACT_TYPE_REGISTRANT) {
                return $this->createContact($this->setContactParams($contact->register));
            } else {
                return $this->createAccount($this->setAccountParams($contact->register));
            }
        }
    }

    /**
     * @param string $domainName
     * @param int $years
     * @param string $cartId
     * @param string $mode
     * @return int
     */
    public function addDomainToCart(string $domainName, int $years, string $cartId, string $mode = 'create-default'): int
    {
        $domainParams = array(
            'domain' => $domainName,
            'pricingMode' => $mode,
        );

        if ($years) {
            $domainParams = array_merge($domainParams, ['duration' => 'P' . $years . 'Y']);
        }

        $item = $this->client->post("/order/cart/{$cartId}/domain", $domainParams);

        return (int)$item['itemId'];
    }

    /**
     * @param string $tld
     * @return string
     */
    public function createCart(): string
    {
        $cart = $this->client->post('/order/cart', ['ovhSubsidiary' => 'FR']);

        $cartId = (string)$cart['cartId'];

        $this->client->post("/order/cart/{$cartId}/assign");

        return $cartId;
    }

    private function setLanguage(string $country): string
    {
        if (in_array($country, ['CZ', 'DE', 'ES', 'FI', 'FR', 'IT', 'NL', 'PL'])) {
            return strtolower($country) . '_' . $country;
        }

        return 'en_GB';
    }

    /**
     * @throws Throwable
     */
    private function createContact(array $params): ?string
    {
        try {
            $create = $this->client->post('/domain/contact', $params);
            return $create['id'] ?? null;
        } catch (RequestException $e) {
            if ($e->getCode() == 400) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $body = trim($response->getBody()->__toString());
                    $responseData = json_decode($body, true);

                    return (string)$responseData['details']['contact_id'];
                }
            }

            throw $e;
        }
    }

    /**
     * @throws Throwable
     */
    private function createAccount(array $params): ?string
    {
        $create = $this->client->post('/newAccount', $params);
        return $create['ovhIdentifier'] ?? null;
    }

    public function getDomainEppCode(string $domainName): string
    {
        return (string)$this->client->get("/domain/{$domainName}/authInfo");
    }

    public function setRenewalMode(string $domainName, bool $autoRenew): void
    {
        $params = [
            'renew' => [
                'automatic' => $autoRenew,
                'deleteAtExpiration' => false,
                'forced' => false
            ]
        ];

        $this->client->put("/domain/{$domainName}/serviceInfos", $params);
    }

    public function getRegistrarLockStatus(string $domainName): bool
    {
        $response = $this->client->get("/domain/{$domainName}");
        return $response['transferLockStatus'] === 'locked';
    }

    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $this->client->put("/domain/{$domainName}", [
            'transferLockStatus' => $lock ? 'locked' : 'unlocked'
        ]);
    }

    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $params = ['nameServers' => $nameservers];

        $this->client->post("/domain/{$domainName}/nameServers/update", $params);

        return $this->getNameservers($domainName);
    }

    public function getNameservers(string $domainName): array
    {
        $nameservers = $this->client->get("/domain/{$domainName}/nameServer");
        return $this->parseNameservers($nameservers, $domainName);
    }

    /**
     * @param string[] $nameservers
     * @param string $domainName
     * @return array
     */
    private function parseNameservers(array $nameservers, string $domainName): array
    {
        $result = [];
        $i = 1;

        foreach ($nameservers as $ns) {
            $nameserver = $this->client->get("/domain/{$domainName}/nameServer/{$ns}");

            $result['ns' . $i] = ['host' => (string)$nameserver['host']];
            $i++;
        }

        return $result;
    }

    public function getDomainInfo(string $domainName): array
    {
        $domain = $this->client->get("/domain/{$domainName}");
        $response = $this->client->get("/domain/{$domainName}/serviceInfos");

        return [
            'id' => (string)$response['serviceId'],
            'domain' => (string)$response['domain'],
            'statuses' => [$response['status']],
            'locked' => $this->getRegistrarLockStatus($domainName),
            'registrant' => isset($domain['whoisOwner'])
                ? $this->getContact($domain['whoisOwner'])
                : null,
            'billing' => isset($response['contactBilling'])
                ? $this->parseOVHAccount($response['contactBilling'])
                : null,
            'tech' => isset($response['contactTech'])
                ? $this->parseOVHAccount($response['contactTech'])
                : null,
            'admin' => isset($response['contactAdmin'])
                ? $this->parseOVHAccount($response['contactAdmin'])
                : null,
            'ns' => NameserversResult::create($this->getNameservers($domainName)),
            'created_at' => isset($response['creation'])
                ? Utils::formatDate((string)$response['creation'])
                : null,
            'updated_at' => isset($domain['lastUpdate'])
                ? Utils::formatDate($domain['lastUpdate'])
                : null,
            'expires_at' => isset($response['expiration'])
                ? Utils::formatDate($response['expiration'])
                : null,
        ];

    }

    public function updateRegistrantContact(string $domainName, ContactParams $contact): ContactResult
    {
        $domain = $this->client->get("/domain/{$domainName}");
        $id = $domain['whoisOwner'];

        $params = $this->setContactParams($contact);

        $readOnlyFields = $this->getReadOnlyContactFields($id);

        $params = $this->unsetReadOnlyContactFields($params, $readOnlyFields);

        $response = $this->client->put("/me/contact/{$id}", $params);

        $contact = $this->parseContact($response);

        return ContactResult::create($contact)->setMessage('Cannot update read-only fields: ' . implode(', ', $readOnlyFields));
    }

    private function setContactParams(ContactParams $contact): array
    {
        $name = $contact->name ?: $contact->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);
        $countryCode = Utils::normalizeCountryCode($contact->country_code);
        return array(
            'address' => [
                'city' => $contact->city,
                'country' => $countryCode,
                'line1' => $contact->address1,
                'zip' => $contact->postcode,
                'province' => $contact->state ?? '',
            ],
            'email' => $contact->email,
            'firstName' => $firstName,
            'lastName' => $lastName ?? $firstName,
            'organisationName' => $contact->organisation ?? '',
            'phone' => Utils::internationalPhoneToEpp($contact->phone),
            'language' => $this->setLanguage($countryCode),
            'legalForm' => 'other',
        );
    }

    private function setAccountParams(ContactParams $contact): array
    {
        $name = $contact->name ?: $contact->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);
        $countryCode = Utils::normalizeCountryCode($contact->country_code);

        return array(
            'name' => $name,
            'firstname' => $firstName,
            'country' => $countryCode,
            'city' => $contact->city,
            'zip' => $contact->postcode,
            'address' => $contact->address1,
            'area' => $contact->state ?: '',
            'phone' => Utils::eppPhoneToInternational($contact->phone),
            'email' => $contact->email,
            'organisation' => $contact->organisation ?? '',
            'ovhCompany' => 'ovh',
            'ovhSubsidiary' => 'FR',
            'language' => $this->setLanguage($countryCode),
            'legalform' => 'other',
        );
    }

    /**
     * @param array $contactParams
     * @param string[] $readOnlyFields
     * @return array
     */
    private function unsetReadOnlyContactFields(array $contactParams, array $readOnlyFields): array
    {
        foreach ($readOnlyFields as $field) {
            $keys = explode('.', $field);

            if (count($keys) > 1) {
                if ($keys[0] === 'address') {
                    unset($contactParams[$keys[0]][$keys[1]]);
                }
                continue;
            }

            unset($contactParams[$keys[0]]);
        }

        return $contactParams;
    }

    public function getContact(string $contact): ContactData
    {
        $response = $this->client->get("/me/contact/{$contact}");
        return $this->parseContact($response);
    }

    public function getReadOnlyContactFields(string $contact): array
    {
        $fields = $this->client->get("/me/contact/{$contact}/fields");
        $readOnlyFields = [];

        foreach ($fields as $field) {
            if ($field['readOnly'] == 1) {
                $readOnlyFields[] = $field['fieldName'];
            }
        }

        return $readOnlyFields;
    }

    public function parseOVHAccount(string $contact): ContactData
    {
        return ContactData::create([
            'id' => $contact,
        ]);
    }

    /**
     * @param array $contact
     * @return ContactData
     */
    private function parseContact(array $contact): ContactData
    {
        return ContactData::create([
            'organisation' => $contact['organisationName'] ?? null,
            'name' => $contact['firstName'] . ' ' . $contact['lastName'],
            'address1' => (string)$contact['address']['line1'],
            'city' => (string)$contact['address']['city'],
            'state' => $contact['address']['province'] ?? null,
            'postcode' => (string)$contact['address']['zip'],
            'country_code' => Utils::normalizeCountryCode((string)$contact['address']['country']),
            'email' => (string)$contact['email'],
            'phone' => Utils::internationalPhoneToEpp((string)$contact['phone']),
        ]);
    }

    /**
     * @param string $domainName
     * @param int $period
     * @return string
     */
    public function renew(string $domainName, int $period): string
    {
        $response = $this->client->get("/domain/{$domainName}/serviceInfos");

        $params = [
            'duration' => 'P' . $period . 'Y',
            'services' => [],
        ];

        $response = $this->client->post("/service/{$response['serviceId']}/renew", $params);

        return $response['url'];
    }


    /**
     * @param string $domainName
     * @param string $eppCode
     * @param array $contacts
     * @param int $years
     * @return string
     * @throws Throwable
     */
    public function initiateTransfer(string $domainName, string $eppCode, array $contacts, int $years): string
    {
        $cartId = $this->createCart();

        $itemId = $this->addDomainToCart($domainName, $years, $cartId, 'transfer-default');

        $this->setConfiguration($contacts, $cartId, $itemId, $eppCode);

        $resp = $this->client->get("/order/cart/{$cartId}/item/{$itemId}/configuration");
        foreach ($resp as $item) {
            $this->client->get("/order/cart/{$cartId}/item/{$itemId}/configuration/{$item}");
        }

        $this->client->get("/order/cart/{$cartId}/item/{$itemId}");
        $this->client->get("/order/cart/{$cartId}/checkout");

        $checkout = $this->client->post("/order/cart/{$cartId}/checkout",
            array(
                'waiveRetractationPeriod' => false,
                'autoPayWithPreferredPaymentMethod' => false,
            )
        );

        return $checkout['url'];
    }
}
