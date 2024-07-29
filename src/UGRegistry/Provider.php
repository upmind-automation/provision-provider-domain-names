<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\UGRegistry;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\UGRegistry\Data\UGRegistryConfiguration;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var UGRegistryConfiguration
     */
    protected $configuration;

    /**
     * Array of contact ids keyed by contact data hash.
     *
     * @var string[]
     */
    protected $contactIds = [];

    /**
     * @var Client|null
     */
    protected $client;

    /**
     * Max positions for nameservers
     */
    private const MAX_CUSTOM_NAMESERVERS = 5;
    /**
     * @var string
     */
    private $baseUrl = 'https://new.registry.co.ug/api/v2';

    public function __construct(UGRegistryConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('UG Registry')
            ->setDescription('Register, transfer, renew and manage .ug domains with the Ugandan domain registry')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/ugregistry-logo.webp');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $domains = [];
        $dacDomains = [];

        foreach ($params->tlds as $tld) {
            if (Str::endsWith($tld, ['ug'])) {
                $domains[] = Utils::getDomain($params->sld, $tld);
            } else {
                $dacDomains[] = new DacDomain([
                    'domain' => Utils::getDomain($params->sld, $tld),
                    'tld' => Str::start(Utils::normalizeTld($tld), '.'),
                    'can_register' => false,
                    'can_transfer' => false,
                    'is_premium' => false,
                    'description' => 'TLD not supported',
                ]);
            }
        }

        $checkedDomains = $this->_callApi([
            'domains' => array_map(function ($domain) {
                return ['name' => $domain];
            }, $domains)
        ], '/domains/check-availability', 'GET');

        foreach ($checkedDomains['data'] as $check) {
            $domainParts = explode('.', $check['domain'], 2);
            $tld = $domainParts[1];

            $dacDomains[] = new DacDomain([
                'domain' => $check['domain'],
                'tld' => Str::start(Utils::normalizeTld($tld), '.'),
                'can_register' => !!$check['available'],
                'can_transfer' => !$check['available'],
                'is_premium' => false,
                'description' => sprintf(
                    'Domain is %s to register',
                    $check['available'] ? 'available' : 'not available'
                ),
            ]);
        }

        return new DacResult([
            'domains' => $dacDomains,
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $checkedDomains = $this->_callApi([
            'domains' => [['name' => $domain]]
        ], '/domains/check-availability', 'GET');

        if (empty($checkedDomains['data'][0]['available'])) {
            $this->errorResult('This domain is not available to register');
        }

        $data = [
            'domain_name' => $domain,
            'period' => intval($params->renew_years),
        ];

        $this->_callApi($data, '/domains/register', 'POST');

        $ns = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $ns['ns' . $i] = [
                    'name' => Arr::get($params, 'nameservers.ns' . $i . '.host')
                ];
            }
        }

        $contacts = [
            'contacts' => [
                'registrant' => $this->_prepareContact($params->registrant->register, 'registrant'),
                'admin' => $this->_prepareContact($params->admin->register, 'admin'),
                'billing' => $this->_prepareContact($params->billing->register, 'billing'),
                'tech' => $this->_prepareContact($params->tech->register, 'tech'),
            ]
        ];

        $this->_updateDomain($domain, $contacts, $ns);

        return $this->_getDomain($domain)
            ->setMessage('Domain registered');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getDomain($domain, true, true)
                ->setMessage('Domain active in registrar account');
        } catch (Throwable $e) {
            $this->_callApi(
                [
                    'domain_name' => $domain,
                ],
                '/domains/request-transfer',
                'POST'
            );

            $this->errorResult('Domain transfer requested');
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function release(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $this->_renewDomain($domain, intval($params->renew_years));

        return $this->_getDomain($domain)
            ->setMessage('Domain renewed');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        return $this->_getDomain($domain, true);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $this->_updateRegisteredNameServer($params);

        $domain = $this->_getDomain(Utils::getDomain($params->sld, $params->tld));

        return NameserversResult::create($domain->ns)
            ->setMessage('Nameservers updated');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $domainData = $this->_callApi(
            [
                'domain_name' => $domainName,
            ],
            '/domains/whois'
        );

        $ns = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (!empty($domainData['data']['domain']['contacts']['nameservers']['ns' . $i])) {
                $ns['ns' . $i] = [
                    'name' => $domainData['data']['domain']['contacts']['nameservers']['ns' . $i]
                ];
            }
        }

        $contacts = [
            'contacts' => [
                'registrant' => $this->_prepareContact($params->contact, 'registrant'),
                'admin' => $domainData['data']['domain']['contacts']['admin'],
                'billing' => $domainData['data']['domain']['contacts']['billing'],
                'tech' => $domainData['data']['domain']['contacts']['tech']
            ]
        ];

        $this->_updateDomain($domainName, $contacts, $ns);

        $domainData = $this->_callApi(
            [
                'domain_name' => $domainName,
            ],
            '/domains/whois'
        );

        return $this->_parseContactInfo($domainData['data']['domain']['contacts']['registrant']);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);
        $message = sprintf('Domain %s', $params->lock ? 'locked' : 'unlocked');

        try {
            $this->_toggleLock($domain, !!$params->lock);
        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), ['is already locked'])) {
                $message = 'Domain already locked';
            } elseif (Str::contains($e->getMessage(), ['is already unlocked'])) {
                $message = 'Domain already unlocked';
            } else {
                throw $e;
            }
        }

        return $this->_getDomain($domain)
            ->setLocked(!!$params->lock)
            ->setMessage($message);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $domainData = $this->_getDomain(Utils::getDomain($params->sld, $params->tld));
        if ($domainData['renew'] == $params->auto_renew) {
            $this->errorResult(sprintf('Domain already is set to %s', $domainData['renew'] ? 'auto-renew' : 'not auto-renew'), $params);
        }
        if ($params->auto_renew == true) {
            $path = 'addAutoRenewal';
        } else {
            $path = 'removeAutoRenewal';
        }

        $this->_callApi(
            [
                'domain' => $domainName,
            ],
            $path
        );

        return $this->_getDomain($domainName)
            ->setMessage('Domain auto-renew mode updated');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _callApi(array $params, string $path, string $method = 'GET'): array
    {
        $url = $this->baseUrl;
        $url .= $path ;
        $paramKey = 'json';

        $client = new Client(['handler' => $this->getGuzzleHandlerStack()]);

        $headers = ['Authorization' => 'Bearer ' . $this->configuration->api_key];

        $response = $client->request(
            $method,
            $url,
            [
                $paramKey => $params,
                'http_errors' => false,
                'headers' => $headers
            ]
        );

        $responseData = json_decode($response->getBody()->__toString(), true);

        if ($response->getStatusCode() >= 300 || empty($responseData)) {
            $this->_handleApiErrorResponse($response, $responseData);
        }

        return $responseData;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _getDomain(
        string $domainName,
        bool $verifyOwnership = false,
        bool $assertActive = false
    ): DomainResult {
        $domainData = $this->_callApi(
            [
                'domain_name' => $domainName,
            ],
            '/domains/whois'
        );

        if ($verifyOwnership) {
            $this->_verifyOwnership($domainName, $domainData);
        }

        if ($assertActive && $domainData['data']['domain']['status'] != 'ACTIVE') {
            $this->errorResult('Domain is not active', ['response_data' => $domainData]);
        }

        $ns = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (!empty($domainData['data']['domain']['contacts']['nameservers']['ns' . $i])) {
                $ns['ns' . $i] = [
                    'host' => $domainData['data']['domain']['contacts']['nameservers']['ns' . $i]
                ];
            }
        }

        $info = DomainResult::create([
            'id' => 'N/A',
            'domain' => $domainName,
            'statuses' => [ucfirst(strtolower($domainData['data']['domain']['status']))],
            'registrant' => $this->_parseContactInfo($domainData['data']['domain']['contacts']['registrant']),
            'billing' => $this->_parseContactInfo($domainData['data']['domain']['contacts']['billing']),
            'admin' => $this->_parseContactInfo($domainData['data']['domain']['contacts']['admin']),
            'tech' => $this->_parseContactInfo($domainData['data']['domain']['contacts']['tech']),
            'ns' => $ns,
            'created_at' => Utils::formatDate($domainData['data']['domain']['registration_date']),
            'updated_at' => null,
            'expires_at' => Utils::formatDate($domainData['data']['domain']['expiry_date']),
        ])->setMessage('Domain info retrieved');

        return $info;
    }

    /**
     * @throws \Throwable
     */
    private function _parseContactInfo(array $contact): ContactResult
    {
        return ContactResult::create([
            'name' => trim($contact['firstname'] . ' ' . ($contact['lastname'] ?? '')),
            'email' => $contact['email'],
            'phone' => Utils::localPhoneToInternational($contact['phone'], $contact['country'], false),
            'organisation' => !empty($contact['organization']) ? $contact['organization'] : '',
            'address1' => $contact['street_address'],
            'city' => $contact['city'],
            'state' => $contact['state_province'] ?? null,
            'postcode' => $contact['postal_code'],
            'country_code' => Utils::countryToCode($contact['country']),
        ]);
    }

    /**
     * Renew domain
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _renewDomain(string $domainName, int $renew_years): void
    {
        $this->_callApi(
            [
                'domain_name' => $domainName,
                'period' => $renew_years,
            ],
            '/domains/renew',
            'POST'
        );
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _updateRegisteredNameServer(UpdateNameserversParams $params): void
    {
        $domain = Utils::getDomain($params->sld, $params->tld);
        $domainData = $this->_callApi(
            [
                'domain_name' => $domain,
            ],
            '/domains/whois'
        );

        $ns = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $ns['ns' . $i] = [
                    'name' => Arr::get($params, 'ns' . $i . '.host')
                ];
            }
        }

        $contacts = [
            'contacts' => [
                'registrant' => $domainData['data']['domain']['contacts']['registrant'],
                'admin' => $domainData['data']['domain']['contacts']['admin'],
                'billing' => $domainData['data']['domain']['contacts']['billing'],
                'tech' => $domainData['data']['domain']['contacts']['tech']
            ]
        ];

        $this->_updateDomain($domain, $contacts, $ns);
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _handleApiErrorResponse(Response $response, ?array $responseData): void
    {
        $errorMessage = $responseData['error'] ?? $responseData['message'] ?? null;

        if (!$errorMessage && isset($responseData['data']['domain_name'])) {
            $errorMessage = sprintf('Domain name %s', implode(',', $responseData['data']['domain_name']));
        }

        $message = sprintf('Provider Error: %s', trim($errorMessage ?? 'Unknown error', '.'));

        $this->errorResult($message, [
            'http_code' => $response->getStatusCode(),
            'response_data' => $responseData,
        ]);
    }

    private function _prepareContact(?ContactParams $register, string $type): array
    {
        // Set default data values.
        $data = [
            'firstname' => '',
            'email' => '',
            'organization' => '',
            'country' => '',
            'city' => '',
            'street_address' => '',
            'phone' => '',
            'postal_code' => '',
            'fax' => '',
        ];

        // Set empty value for non registrant type.
        if ($type !== 'registrant') {
            $data['lastname'] = '';
        }

        // If register is null, return empty data.
        if ($register === null) {
            return $data;
        }

        // Now set the data if we have a register.
        $name = $register->name ?? null;

        if ($name === null) {
            $name = $register->organisation ?? null;
        }

        $data['firstname'] = $name;
        if ($type !== 'registrant') {
            $nameParts = explode(' ', $name ?? '', 2);
            $data['firstname'] = $nameParts[0];
            $data['lastname'] = $nameParts[1] ?? '';
        }
        $data['email'] = $register->email ?? null;
        $data['organization'] = $register->organisation ?? null;
        $data['country'] = Utils::codeToCountry($register->country_code ?? null);
        $data['city'] = $register->city ?? null;
        $data['street_address'] = $register->address1 ?? null;
        $data['phone'] = $register->phone ?? null;
        $data['postal_code'] = $register->postcode ?? null;
        $data['fax'] = "";

        // Transform null values to empty strings.
        return array_map(function ($value) {
            return $value ?? '';
        }, $data);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _updateDomain(string $domain, array $contacts, array $nameservers)
    {
        $params = [];
        $params['domain_name'] = $domain;
        $params += $contacts;
        $params['nameservers'] = $nameservers;

        $this->_callApi($params, '/domains/modify', 'POST');
    }

    /**
     * Verify the given domain is owned by the current account the only way we know how:
     * attempt to update the domain without any changes.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _verifyOwnership(string $domain, ?array $domainData = null): void
    {
        $domainData = $domainData ?? $this->_callApi(
            [
                'domain_name' => $domain,
            ],
            '/domains/whois'
        );

        $ns = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (!empty($domainData['data']['domain']['contacts']['nameservers']['ns' . $i])) {
                $ns['ns' . $i] = [
                    'name' => $domainData['data']['domain']['contacts']['nameservers']['ns' . $i]
                ];
            }
        }

        $contacts = [
            'contacts' => [
                'registrant' => $domainData['data']['domain']['contacts']['registrant'],
                'admin' => $domainData['data']['domain']['contacts']['admin'],
                'billing' => $domainData['data']['domain']['contacts']['billing'],
                'tech' => $domainData['data']['domain']['contacts']['tech']
            ]
        ];

        try {
            $this->_updateDomain($domain, $contacts, []);
        } catch (ProvisionFunctionError $e) {
            if (Str::contains($e->getMessage(), 'domain is locked')) {
                return;
            }

            if (Str::contains($e->getMessage(), 'not authorized')) {
                $this->errorResult('Domain is not owned by this account', $e->getData(), $e->getDebug(), $e);
            }

            throw $e;
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _toggleLock(string $domain, bool $locked): void
    {
        $path = $locked ? '/domains/lock' : '/domains/unlock';

        $this->_callApi([
            'domain_name' => $domain,
        ], $path, 'POST');
    }
}
