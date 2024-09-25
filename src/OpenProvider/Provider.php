<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenProvider;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
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
     * @var string|null
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
            ? 'http://api.sandbox.openprovider.nl:8480/v1beta/'
            : 'https://api.openprovider.eu/v1beta/';
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('OpenProvider')
            ->setDescription('Register, transfer, renew and manage domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/open-provider-logo.png');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

        $checkDomain = $this->_checkDomain(
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
            $this->errorResult('Domain is not available to register', ['check' => $checkDomain[0]]);
        }

        $data['owner_handle'] = $this->_handleCustomer($tld, Arr::get($params, 'registrant'), 'registrant');
        $data['billing_handle'] = $this->_handleCustomer($tld, Arr::get($params, 'billing'), 'billing');
        $data['admin_handle'] = $this->_handleCustomer($tld, Arr::get($params, 'admin'), 'admin');
        $data['tech_handle'] = $this->_handleCustomer($tld, Arr::get($params, 'tech'), 'tech');

        $data['period'] = Arr::get($params, 'renew_years', 1);
        $data['unit'] = 'y';
        $data['autorenew'] = 'off';
        $data['is_private_whois_enabled'] = $params->whois_privacy ?? !$this->configuration->disable_whois_privacy;
        if (!Utils::tldSupportsWhoisPrivacy($tld)) {
            $data['is_private_whois_enabled'] = false;
        }

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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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
                $this->errorResult(sprintf('Domain transfer in progress since %s', $info->created_at), $info);
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
            $customerId = $this->_handleCustomer($tld, Arr::get($params, 'registrant'), 'registrant');
        }

        $initiate = $this->initiateTransfer($customerId, $tld, $sld, $eppCode, $params->whois_privacy);

        $this->errorResult('Domain transfer initiated', [], ['transfer_order' => $initiate]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $tld = Arr::get($params, 'tld');
        $sld = Arr::get($params, 'sld');

        $this->_renewDomain($sld, $tld, Arr::get($params, 'renew_years'));

        $domainName = Utils::getDomain($sld, $tld);
        return $this->_getDomain($domainName, sprintf('Domain %s has renewed', $domainName));
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        return $this->_getDomain($domainName, sprintf('Domain info for %s', $domainName));
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported!');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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
        //     $params->tld,
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
            $params->tld,
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $callDomain = $this->_callDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));

        if ($callDomain['is_locked'] == $params->lock) {
            $this->errorResult(sprintf('Domain already %s', $callDomain['is_locked'] ? 'locked' : 'unlocked'));
        }

        if (!$callDomain['is_lockable']) {
            $this->errorResult('This domain cannot be locked');
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('The requested operation not supported', $params);
    }

    /**
     * @return array
     *
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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
            $this->errorResult(
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
            // 'renew' => $renew,
            'whois_privacy' => $domainData['is_private_whois_enabled'],
            'registrant' => $this->_parseContactInfo($domainData['owner_handle'], 'customers'),
            'billing' => $this->_parseContactInfo($domainData['billing_handle'], 'customers'),
            'admin' => $this->_parseContactInfo($domainData['admin_handle'], 'customers'),
            'tech' => $this->_parseContactInfo($domainData['tech_handle'], 'customers'),
            'ns' => $ns,
            'created_at' => $domainData['creation_date'] ?? null,
            'updated_at' => $domainData['last_changed'] ?? null,
            'expires_at' => $domainData['expiration_date'] ?? null,
        ])->setMessage($msg);

        /**
         * @link https://support.openprovider.eu/hc/en-us/articles/216649208-What-is-the-status-of-my-domain-request-
         */
        if ($requireActive && $domainData['status'] !== 'ACT') {
            $this->errorResult('Domain status is not active', $result->toArray(), ['domain_data' => $domainData]);
        }

        return $result;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _updateCustomer(
        string $tld,
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
                'state' => Utils::normalizeState($tld, $state, $postcode) ?? Utils::normalizeCountryCode($country),
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
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
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
     * @param string|null $postCode Postal code e.g., SW152QT
     * @param string|null $countryCode 2-letter iso code e.g., GB
     *
     * @return string|null Post code e.g., SW15 2QT
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

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _exceptionHandler(Throwable $exception, array $params): void
    {
        $debug = [];

        if ($exception instanceof ProvisionFunctionError) {
            throw $exception->withDebug(array_merge(['params' => $params], $exception->getDebug()));
        }

        switch ($exception->getCode()) {
            case 2001:
                $this->errorResult('Invalid data for making the operation!', $params, $debug, $exception);
            default:
                $this->errorResult($exception->getMessage(), $params, $debug, $exception);
        }
    }

    /**
     * @return mixed
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _callApi(array $params, string $path, string $method = 'GET', bool $withToken = true)
    {
        $url = $this->baseUrl;
        $url .= $path ;
        $paramKey = 'json';

        if ($method === 'GET') {
            $paramKey = 'query';
        }

        $client = new Client(['handler' => $this->getGuzzleHandlerStack()]);

        $headers = [];

        if ($withToken) {
            $headers = ['Authorization' => 'Bearer ' . $this->_getToken()];
        }

        /** @var \GuzzleHttp\Psr7\Response $response */
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
            $this->_handleApiErrorResponse($response, $responseData);
        }

        return $responseData;
    }

    /**
     * @return no-return
     *
     * @throws Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _handleApiErrorResponse(Response $response, $responseData): void
    {
        $errorData = [
            'http_code' => $response->getStatusCode(),
            'response_data' => $responseData,
        ];

        if (!isset($responseData['code'])) {
            $errorData['response_body'] = $response->getBody()->__toString();
            $this->errorResult('Unexpected provider response', $errorData);
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

        $this->errorResult($message, $errorData);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _handleCustomer(string $tld, DataSet $params, string $role = '')
    {
        if (Arr::has($params, 'id')) {
            $customerId = Arr::get($params, 'id');
        } else {
            $customerId = $this->_createCustomer(
                $tld,
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _createCustomer(
        string $tld,
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
                'state' => Utils::normalizeState($tld, $state, $postcode) ?? Utils::normalizeCountryCode($countryName),
                'street' => $address,
                'suffix' => '',
                'zipcode' => $postcode,
            ],
        ];

        return $this->_callApi($data, 'customers', 'POST')['data']['handle'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function initiateTransfer(string $customerId, string $tld, string $sld, $eppCode, ?bool $privacy): array
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
        }, Utils::lookupNameservers(Utils::getDomain($sld, $tld), false) ?: self::DEFAULT_NAMESERVERS);
        $params['is_private_whois_enabled'] = $privacy ?? !$this->configuration->disable_whois_privacy;
        if (!Utils::tldSupportsWhoisPrivacy($tld)) {
            $params['is_private_whois_enabled'] = false;
        }

        $transferOrder = $this->_callApi($params, 'domains/transfer', 'POST');

        if (!is_array($transferOrder)) {
            $transferOrder = json_decode($transferOrder, true);
        }

        return $transferOrder;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     *
     * @phpstan-ignore method.unused
     */
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
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _checkDomain(array $params): array
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

            if (!isset($domain)) {
                $domain = [];
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
        } catch (Exception $e) {
            $this->_exceptionHandler($e, $params);
        }

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Polling not yet supported');
    }

    /**
     * @phpstan-ignore method.unused
     */
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
