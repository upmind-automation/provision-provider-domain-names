<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenProvider;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
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
use Upmind\ProvisionProviders\DomainNames\OpenProvider\Data\OpenProviderConfiguration;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var OpenProviderConfiguration
     */
    protected $configuration;

    private $baseUrl;

    /**
     * Contacts keyed by URI, to prevent repeated lookups for the same information.
     *
     * @var array[]
     */
    protected $contacts = [];

    /**
     * @var null
     */
    private $token = null;

    /**
     * Max positions for nameservers
     */
    private const MAX_CUSTOM_NAMESERVERS = 4;

    /**
     * @var string[]
     */
    private const DEFAULT_NAMESERVERS = [
        'ns-eu-central-1.openprovider.eu',
        'ns-eu-west-1.openprovider.eu',
        'ns-eu-west-2.openprovider.eu',
    ];

    public function __construct(OpenProviderConfiguration $configuration)
    {
        $this->configuration = $configuration;
        $this->baseUrl = $configuration->test_mode
            ? 'https://api.cte.openprovider.eu/v1beta/'
            : 'https://api.openprovider.eu/v1beta/';
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('OpenProvider')
            ->setDescription('Register, transfer, renew and manage domains');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $domains = array_map(fn ($tld) => ['name' => $sld, 'extension' => Utils::normalizeTld($tld)], $params->tlds);
        $response = $this->_callApi([
            'domains' => $domains,
            // 'with_price' => true,
        ], 'domains/check', 'POST');

        $dacDomains = [];

        foreach ($response['data']['results'] as $domainResult) {
            $dacDomains[] = DacDomain::create()
                ->setDomain($domainResult['domain'])
                ->setTld(Utils::getTld($domainResult['domain']))
                ->setCanRegister($domainResult['status'] === 'free')
                ->setCanTransfer($domainResult['status'] === 'active')
                ->setIsPremium(isset($domainResult['premium']))
                ->setDescription($domainResult['reason'] ?? sprintf('Domain is %s', $domainResult['status'] ?? 'n/a'));
        }

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $data = [];
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');
        $domainName = Utils::getDomain($sld, $tld);
        $data['name_servers'] = [];

        $data['domain'] = [
            'extension' => Utils::normalizeTld($tld),
            'name' => Utils::normalizeSld($sld),
        ];

        $checkDomain = $this->_checkDomains(
            [
                'domains' => [
                    [
                        'tld' => Utils::normalizeTld($tld),
                        'sld' => Utils::normalizeSld($sld),
                    ]
                ]
            ]
        );

        if (!$checkDomain[0]['available']) {
            throw $this->errorResult('Domain is not available to register', ['check' => $checkDomain[0]]);
        }

        $data['owner_handle'] = $this->_handleCustomer(Arr::get($params, 'registrant'), 'registrant');
        $data['billing_handle'] = $this->_handleCustomer(Arr::get($params, 'billing'), 'billing');
        $data['admin_handle'] = $this->_handleCustomer(Arr::get($params, 'admin'), 'admin');
        $data['tech_handle'] = $this->_handleCustomer(Arr::get($params, 'tech'), 'tech');

        $data['period'] = Arr::get($params, 'renew_years', 1);
        $data['unit'] = 'y';
        $data['autorenew'] = 'off';
        $data['is_private_whois_enabled'] = Utils::tldSupportsWhoisPrivacy($tld);

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $data['name_servers'][] = [
                    'name' => Arr::get($params, 'nameservers.ns' . $i . '.host'),
                    'ip' => Arr::get($params, 'nameservers.ns' . $i . '.ip')
                    ];
            }
        }

        $this->_callApi($data, 'domains', 'POST');

        return $this->_getDomain($domainName, sprintf('Domain %s registered.', $domainName));
    }

    public function transfer(TransferParams $params): DomainResult
    {
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domainName = Utils::getDomain($sld, $tld);

        try {
            $info = $this->_getDomain($domainName, 'Domain active in registrar account', false);

            /**
             * See OpenProvider domain statuses.
             *
             * @link https://support.openprovider.eu/hc/en-us/articles/216649208-What-is-the-status-of-my-domain-request-
             */

            if (in_array('ACT', $info->statuses)) {
                return $info;
            }

            if (array_intersect(['REQ', 'SCH', 'PEN'], $info->statuses)) {
                throw $this->errorResult(sprintf('Domain transfer in progress since %s', $info->created_at), $info);
            }

            // transfer failed - proceed to initiate new transfer
        } catch (ProvisionFunctionError $e) {
            if (Str::startsWith($e->getMessage(), 'Domain transfer in progress')) {
                throw $e;
            }

            // domain does not exist - proceed to initiate transfer
        }

        $eppCode = Arr::get($params, 'epp_code', "0000");
        $customerId = Arr::get($params, 'admin.id');

        if (!$customerId) {
            $customerId = $this->_handleCustomer(Arr::get($params, 'admin'), 'admin');
        }

        $initiate = $this->initiateTransfer($customerId, $tld, $sld, $eppCode);

        throw $this->errorResult('Domain transfer initiated', [], ['transfer_order' => $initiate]);
    }

    public function renew(RenewParams $params): DomainResult
    {
        $tld = Arr::get($params, 'tld');
        $sld = Arr::get($params, 'sld');

        $this->_renewDomain($sld, $tld, Arr::get($params, 'renew_years'));

        $domainName = Utils::getDomain($sld, $tld);
        return $this->_getDomain($domainName, sprintf('Domain %s has renewed', $domainName));
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        return $this->_getDomain($domainName, sprintf('Domain info for %s', $domainName));
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainData = $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));
        $paramsApi = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $paramsApi['name_servers'][] = [
                    'name' => Arr::get($params, 'ns' . $i)->host,
                    'ip' => Arr::get($params, 'ns' . $i)->ip,
                    'seq_nr' => $i - 1,
                ];
            }
        }

        $this->_callApi(
            $paramsApi,
            'domains/' . $domainData['id'],
            'PUT'
        );

        $domainData = $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));

        $returnNameservers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (isset($domainData['domain_servers'][$i])) {
                $returnNameservers['ns' . $i] = [
                    'host' => $domainData['domain_servers'][$i]['name'],
                    'ip' => $domainData['domain_servers'][$i]['ip']
                ];
            }
        }

        return NameserversResult::create($returnNameservers)
            ->setMessage('Nameservers are changed');
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainData = $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));
        $paramsApi = [];

        $result = $this->_callApi(
            $paramsApi,
            'domains/' . $domainData['id'] . '/authcode'
        );

        $epp = [
            'epp_code' => $result['data']['auth_code']
        ];

        return EppCodeResult::create($epp);
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        return $this->errorResult('Operation not supported!');
    }

    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        // now we always create a new contact

        // $params = $params->toArray();
        // $contact =  Arr::get($params, 'contact');

        // $contactHandle = (string)Arr::get($contact, 'id');
        // if (!$contactHandle) {
        //     $contactHandle = $this->_callDomain( Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')))['owner_handle'];
        // }

        // return $this->_updateCustomer(
        //     $contactHandle,
        //     Arr::get($contact, 'email'),
        //     Arr::get($contact, 'phone'),
        //     Arr::get($contact, 'name'),
        //     Arr::get($contact, 'organisation')?? Arr::get($contact, 'name'),
        //     Arr::get($contact, 'address1'),
        //     Arr::get($contact, 'postcode'),
        //     Arr::get($contact, 'city'),
        //     Arr::get($contact, 'country_code'),
        //     Arr::get($contact, 'state')
        // );

        $domain = Utils::getDomain($params->sld, $params->tld);
        $domainId = $this->_getDomain($domain, '', false)->id;

        $contactHandle = $this->_createCustomer(
            $params->contact->email,
            $params->contact->phone,
            $params->contact->name ?? $params->contact->organisation,
            $params->contact->organisation,
            $params->contact->address1,
            $params->contact->postcode,
            $params->contact->city,
            $params->contact->country_code,
            $params->contact->state
        );

        $this->_callApi([
            'owner_handle' => $contactHandle,
        ], 'domains/' . $domainId, 'PUT');

        $contact = $this->_getDomain($domain, '', false)->registrant;

        return ContactResult::create($contact)->setMessage('Registrant contat updated');
    }

    public function setLock(LockParams $params): DomainResult
    {
        $callDomain = $this->_callDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));

        if ($callDomain['is_locked'] == $params->lock) {
            return $this->errorResult(sprintf('Domain already %s', $callDomain['is_locked'] ? 'locked' : 'unlocked'));
        }

        if (!$callDomain['is_lockable']) {
            return $this->errorResult('This domain cannot be locked');
        }

        $lock = Arr::get($params, 'lock');
        $this->_callApi(
            [
                'is_locked' => $lock,
            ],
            'domains/' . $callDomain['id'],
            'PUT'
        );

        return $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')), 'Domain lock changed!');
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        throw $this->errorResult('The requested operation not supported', $params);
    }

    /**
     * @return array
     */
    protected function _callDomain(string $domainName)
    {
        $result = $this->_callApi(
            [
                'full_name' => $domainName,
            ],
            'domains'
        );

        if (!isset($result['data']['results'][0])) {
            throw new Exception(sprintf('Domain %s has not found!', $domainName), 404);
        }

        return $result['data']['results'][0];
    }

    protected function _getDomain(
        string $domainName,
        string $msg = 'Domain data.',
        bool $requireActive = true
    ): DomainResult {
        $statuses = [];
        $ns = [];
        $domainDataCall = $this->_callApi(
            [
                'full_name' => $domainName,
            ],
            'domains'
        );

        if (!isset($domainDataCall['data']['results'])) {
            throw $this->errorResult(
                'Domain name does not exist in registrar account',
                ['domain' => $domainName],
                ['result_data' => $domainDataCall]
            );
        }

        $domainData = $domainDataCall['data']['results'][0];

        $i = 1;
        foreach ($domainData['name_servers'] as $nameServer) {
            $ns["ns$i"] = [
                'host' => $nameServer['name'],
                'ip' => null
            ];
            $i++;
        }

        if (isset($domainData['status']) && $domainData['status']) {
            $statuses = [$domainData['status']];
        }

        switch ($domainData['autorenew']) {
            case 'off':
                $renew = false;
                break;
            case 'on':
                $renew = true;
                break;
            default:
                $renew = null;
        }

        $result = DomainResult::create([
            'id' => (string)$domainData['id'],
            'domain' => Utils::getDomain(Arr::get($domainData, 'domain.name'), Arr::get($domainData, 'domain.extension')),
            'statuses' => $statuses,
            'locked' => $domainData['is_locked'],
            'renew' => $renew,
            'registrant' => $this->_parseContactInfo($domainData['owner_handle'], 'customers'),
            'billing' => $this->_parseContactInfo($domainData['billing_handle'], 'customers'),
            'admin' => $this->_parseContactInfo($domainData['admin_handle'], 'customers'),
            'tech' => $this->_parseContactInfo($domainData['tech_handle'], 'customers'),
            'ns' => $ns,
            'created_at' => $domainData['creation_date'],
            'updated_at' => $domainData['last_changed'],
            'expires_at' => $domainData['expiration_date'],
        ])->setMessage($msg);

        /**
         * @link https://support.openprovider.eu/hc/en-us/articles/216649208-What-is-the-status-of-my-domain-request-
         */
        if ($requireActive && $domainData['status'] !== 'ACT') {
            throw $this->errorResult('Domain status is not active', $result->toArray(), ['domain_data' => $domainData]);
        }

        return $result;
    }

    protected function _updateCustomer(
        ?string $contactHandle,
        ?string $email,
        ?string $telephone,
        ?string $name,
        ?string $organization,
        ?string $address,
        ?string $postcode,
        ?string $city,
        ?string $country,
        ?string $state
    ): ContactResult {
        if ($country) {
            $country = Utils::normalizeCountryCode($country);
        }

        if ($telephone) {
            $phone = phone($telephone);
            $phoneCode = '+' . $phone->getPhoneNumberInstance()->getCountryCode();
            $phoneArea = substr($phone->getPhoneNumberInstance()->getNationalNumber(), 0, 3);
            $phone = substr($phone->getPhoneNumberInstance()->getNationalNumber(), 3);
        } else {
            $phoneCode = '';
            $phone = '';
            $phoneArea = '';
        }

        if ($postcode) {
            $postcode = $this->normalizePostCode($postcode, $country);
        }

        $params = [
            'email' => $email,
            'address' => [
                'city' => $city,
                'country' => Utils::normalizeCountryCode($country),
                'number' => '',
                'state' => $state ?? Utils::normalizeCountryCode($country),
                'street' => $address,
                'suffix' => '',
                'zipcode' => $postcode,
            ],
            'phone' => [
                'area_code' => $phoneArea,
                'country_code' => $phoneCode,
                'subscriber_number' => $phone,
            ],
        ];

        $this->_callApi($params, 'customers/' . $contactHandle, 'PUT');

        return ContactResult::create($this->_parseContactInfo($contactHandle, 'customers'));
    }

    protected function formatDate(?string $date): ?string
    {
        if (!isset($date)) {
            return $date;
        }
        return Carbon::parse($date)->toDateTimeString();
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param int $renewYears
     * @return void
     * @throws Exception
     */
    protected function _renewDomain(string $sld, string $tld, int $renewYears): void
    {
        $domainName = Utils::getDomain($sld, $tld);
        $domain = $this->_getDomain($domainName, 'The expire date is extended.');

        $this->_callApi(
            [
                'id' => $domain->id,
                'period' => $renewYears
            ],
            'domains/' . $domain->id . '/renew',
            'POST'
        );
    }

    /**
     * Normalize a given contact address post code to satisfy OpenProvider
     * requirements. If a GB postcode is given, this method will ensure a space
     * is inserted in the correct place.
     *
     * @param string $postCode Postal code e.g., SW152QT
     * @param string $countryCode 2-letter iso code e.g., GB
     *
     * @return string Post code e.g., SW15 2QT
     */
    protected function normalizePostCode(?string $postCode, ?string $countryCode = 'GB'): ?string
    {
        if (!isset($postCode) || !isset($countryCode) || $this->normalizeCountryCode($countryCode) !== 'GB') {
            return $postCode;
        }

        return preg_replace(
            '/^([a-z]{1,2}[0-9][a-z0-9]?) ?([0-9][a-z]{2})$/i',
            '${1} ${2}',
            $postCode
        );
    }

    protected function normalizeCountryCode(string $countryCode): string
    {
        return Utils::normalizeCountryCode($countryCode);
    }

    private function _exceptionHandler(\Throwable $exception, array $params): void
    {
        $debug = [];

        if ($exception instanceof ProvisionFunctionError) {
            throw $exception->withDebug(array_merge(['params' => $params], $exception->getDebug()));
        }

        switch ($exception->getCode()) {
            case 2001:
                $this->errorResult('Invalid data for making the operation!', $params, $debug, $exception);
                break;
            default:
                $this->errorResult($exception->getMessage(), $params, $debug, $exception);
        }
    }

    /**
     * @param array $data
     * @param string $path
     * @param string $method
     * @return mixed
     * @throws Exception
     */
    protected function _callApi(array $params, string $path, string $method = 'GET', bool $withToken = true)
    {
        $url = $this->baseUrl;
        $url .= $path ;
        $paramKey = 'json';

        if ($method == 'GET') {
            $paramKey = 'query';
        }

        $client = new Client(['handler' => $this->getGuzzleHandlerStack(!!$this->configuration->debug),
        ]);

        $headers = [];

        if ($withToken) {
            $headers = ['Authorization' => 'Bearer ' . $this->_getToken()];
        }

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

        if (!isset($responseData['code']) || $responseData['code'] != 0) {
            throw $this->_handleApiErrorResponse($response, $responseData);
        }

        return $responseData;
    }

    /**
     * @throws ProvisionFunctionError
     *
     * @return no-return
     */
    protected function _handleApiErrorResponse(Response $response, $responseData): void
    {
        $errorData = [
            'http_code' => $response->getStatusCode()
        ];

        if (!isset($responseData['code'])) {
            throw $this->errorResult('Unexpected provider response', $errorData, [
                'response_body' => $response->getBody()->__toString(),
            ]);
        }

        $message = 'Provider Error: ';

        /**
         * Specify a more specific/helpful error message based on the error code.
         *
         * @link https://support.openprovider.eu/hc/en-us/articles/216644928-API-Error-Codes
         */
        switch ($responseData['code']) {
            case 399:
                $message .= 'An unknown domain error has occurred';
                break;
            case 10005:
                $message .= 'This IP has not been whitelisted';
                break;
            default:
                $message .= ($responseData['desc'] ?? 'Unknown error');
        }

        throw $this->errorResult($message, $errorData, [
            'response_data' => $responseData,
        ]);
    }

    protected function _getToken(): string
    {
        if (isset($this->token)) {
            return $this->token;
        }

        $loginResult = $this->_callApi(
            [
                'username' => $this->configuration['username'],
                'password' => $this->configuration['password']
            ],
            'auth/login',
            'POST',
            false
        );

        return $this->token = $loginResult['data']['token'];
    }

    protected function _handleCustomer(DataSet $params, string $role = '')
    {
        if (Arr::has($params, 'id')) {
            $customerId = Arr::get($params, 'id');
        } else {
            $customerId = $this->_createCustomer(
                Arr::get($params, 'register.email'),
                Arr::get($params, 'register.phone'),
                Arr::get($params, 'register.name') ?? Arr::get($params, 'register.organisation'),
                Arr::get($params, 'register.organisation') ?? Arr::get($params, 'register.name'),
                Arr::get($params, 'register.address1'),
                Arr::get($params, 'register.postcode'),
                Arr::get($params, 'register.city'),
                Arr::get($params, 'register.country_code'),
                Arr::get($params, 'register.state')
            );
        }
        return $customerId;
    }

    protected function _createCustomer(
        string $email,
        string $telephone,
        string $name,
        string $organization = null,
        string $address,
        string $postcode,
        string $city,
        string $countryName,
        ?string $state
    ): string {
        // always create a new customer

        // $customer = $this->_getCustomer($email);

        // if ($customer != null) {
        //     return $customer['handle'];
        // }

        if ($telephone) {
            $telephone = Utils::internationalPhoneToEpp($telephone);
            $phone = phone($telephone);
            $phoneCode = '+' . (string)$phone->getPhoneNumberInstance()->getCountryCode();
            $phoneArea = substr($phone->getPhoneNumberInstance()->getNationalNumber(), 0, 3);
            $phone = substr($phone->getPhoneNumberInstance()->getNationalNumber(), 3);
        } else {
            $phoneCode = '';
            $phone = '';
            $phoneArea = '';
        }
        $nameParts = explode(' ', $name);
        $lastName = $name;
        if (count($nameParts) > 1) {
            $lastName = $nameParts[count($nameParts) - 1];
        }

        if (empty(trim((string)$organization))) {
            $organization = null; // convert empty string / whitespace to null
        }

        $data = [
            'name' => [
                'first_name' => $nameParts[0],
                'full_name' => $name,
                'initials' => '',
                'last_name' => $lastName,
                'prefix' => '',
                ],
            'phone' => [
                'area_code' => $phoneArea,
                'country_code' => $phoneCode,
                'subscriber_number' => $phone,
            ],
            'email' => $email,
            'company_name' => $organization,
            'address' => [
                'city' => $city,
                'country' => Utils::normalizeCountryCode($countryName),
                'number' => '',
                'state' => $state,
                'street' => $address,
                'suffix' => '',
                'zipcode' => $postcode,
            ],
        ];

        return $this->_callApi($data, 'customers', 'POST')['data']['handle'];
    }

    private function initiateTransfer(string $customerId, string $tld, string $sld, $eppCode): array
    {
        $params = [];
        $params['domain'] = [
            'extension' => Utils::normalizeTld($tld),
            'name' => Utils::normalizeSld($sld),
        ];
        $params['auth_code'] = $eppCode;
        $params['owner_handle'] = $customerId;
        $params['tech_handle'] = $customerId;
        $params['admin_handle'] = $customerId;
        $params['billing_handle'] = $customerId;
        $params['autorenew'] = 'off';
        $params['unit'] = 'y';
        $params['name_servers'] = array_map(function (string $hostname) {
            return [
                'name' => $hostname,
            ];
        }, Utils::lookupNameservers(Utils::getDomain($sld, $tld), false) ?? self::DEFAULT_NAMESERVERS);

        $transferOrder = $this->_callApi($params, 'domains/transfer', 'POST');

        if (!is_array($transferOrder)) {
            $transferOrder = json_decode($transferOrder, true);
        }

        return $transferOrder;
    }

    private function _parseContactInfo(string $handle, string $path)
    {
        if (!$handle) {
            return [];
        }

        $uri = $path . '/' . $handle;

        if (isset($this->contacts[$uri])) {
            return $this->contacts[$uri];
        }

        $contactApi = $this->_callApi(
            [],
            $uri,
            'GET'
        );

        if ($contactApi['code'] !== 0) {
            return [];
        }

        $contact = $contactApi['data'];

        $countryCode = ($contact['address']['country'] ?? '');
        return $this->contacts[$uri] = [
            'id' => $handle,
            'name' => ($contact['name']['full_name'] ?? ''),
            'email' => $contact['email'],
            'phone' => ($contact['phone']['country_code'] ?? '') . ($contact['phone']['area_code'] ?? '') . ($contact['phone']['subscriber_number'] ?? ''),
            'organisation' => ($contact['company_name'] ?? ''),
            'address1' => ($contact['address']['street'] ?? '') . ' ' . ($contact['address']['number'] ?? ''),
            'city' => ($contact['address']['city'] ?? ''),
            'postcode' => ($contact['address']['zipcode'] ?? ''),
            'country_code' => $countryCode,
            'status' => (!$contact['is_deleted']) ? 'Active' : 'Deleted',
            'state' => ($contact['address']['state'] ?? ''),
        ];
    }

    private function _getCustomer(string $email): ?array
    {
        $customerApi = $this->_callApi(
            ['email_pattern' => $email],
            'customers',
            'GET'
        );

        return $customerApi['data']['results'][0] ?? null;
    }

    /**
     * @param array $params
     * @return array
     * @throws Exception
     */
    private function _checkDomains(array $params): array
    {
        $domains = Arr::get($params, 'domains');
        $paramsApi = [];
        $result = [];

        try {
            foreach ($domains as $domain) {
                $paramsApi['domains'][] = [
                    'extension' => Utils::normalizeTld(Arr::get($domain, 'tld')),
                    'name' => Utils::normalizeSld(Arr::get($domain, 'sld')),
                ];
            }

            $response = $this->_callApi($paramsApi, 'domains/check', 'POST');
            foreach ($response['data']['results'] as $resp) {
                $result[] = [
                    'sld' => Arr::get($domain, 'sld'),
                    'tld' => Arr::get($domain, 'tld'),
                    'domain' => $domain,
                    'available' => ($resp['status'] == 'free') ? true : false,
                    'reason' => $resp['reason'] ?? null
                ];
            }
        } catch (\Exception $e) {
            $this->_exceptionHandler($e, $params);
        }

        return $result;
    }

    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Polling not yet supported');

        $notifications = [];
        $offset = 0;
        $limit = 10;
        $count = 1;

        /**
         * Start a timer because there may be 1000s of irrelevant messages and we should try and avoid a timeout.
         */
        $timeLimit = 60; // 60 seconds
        $startTime = time();

        $since = $params->after_date ? Carbon::parse($params->after_date) : null;
        while (true) {
            $response = $this->_callApi([
                'limit' => $limit,
                'offset' => $offset,
//                'with_api_history' => 'true',
//                'with_history' => 'true',
//                'with_additional_data' => 'true',
            ], 'domains', 'GET');

            $offset += $limit;


            if (!isset($response['data']['results']) || $response['data']['total'] == 0) {
                break;
            }

            for ($i = 0; $i < $response['data']['total']; $i++) {
                $domain = $response['data']['results'][$i]['domain']['name'] . '.' . $response['data']['results'][$i]['domain']['extension'];

                $creationDate = Carbon::createFromTimeString($response['data']['results'][$i]['creation_date']);

                if ($since != null && $since->gt(Carbon::parse($creationDate))) {
                    continue;
                }

                if ($count > $params->limit) {
                    break 2;
                }

                $count++;

                if ((time() - $startTime) >= $timeLimit) {
                    break 2;
                }

                $status = $this->mapType($response['data']['results'][$i]['status']);

                if ($status == null) {
                    continue;
                }
                $notifications[] = DomainNotification::create()
                    ->setId('N/A')
                    ->setType($status)
                    ->setMessage($response['data']['results'][$i]['status'])
                    ->setDomains([$domain])
                    ->setCreatedAt($creationDate)
                    ->setExtra([]);
            }
        }

        return new PollResult([
            'count_remaining' => (count($notifications) - $params->limit < 0) ? 0 : count($notifications) - $params->limit,
            'notifications' => $notifications,
        ]);
    }

    private function mapType(string $type): ?string
    {
        switch ($type) {
            case 'PRE':
                return DomainNotification::TYPE_TRANSFER_OUT;
            case 'DEL':
                return DomainNotification::TYPE_DELETED;
        }
        return null;
    }
}
