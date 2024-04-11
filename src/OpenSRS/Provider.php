<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenSRS;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Data\OpenSrsConfiguration;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Helper\OpenSrsApi;

/**
 * ⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⣀⣤⣴⣾⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣶⣤⣀⠄⠄⠄
 * ⡄⠄⠄⠄⠄⠄⠄⠄⣠⣾⣿⣿⣿⡿⠛⠋⠉⠛⠻⢿⣿⣿⣿⣿⣿⣿⣿⣧⠄⠄
 * ⡇⠄⠄⠄⠄⠄⣴⣾⣿⣿⣿⣿⣿⣾⣿⣿⣷⣤⣄⣈⣻⣿⣿⣿⣿⣿⣿⣿⣧⠄
 * ⠄⠄⠄⠄⠄⢰⣿⣿⣿⣿⣿⣿⡟⠉⣠⣤⢀⠄⠙⢿⣿⣿⣿⣿⣿⡟⠉⠉⠙⠂
 * ⠄⠄⠄⠄⠄⣈⣿⣿⣿⣿⣿⣿⣇⠸⠿⠁⠄⠧⠄⠾⣿⣿⣿⣿⡿⠷⠤⢤⣄⠄
 * ⠄⠄⠄⠄⠄⣿⣿⣿⣿⣿⣿⣿⣿⣷⣦⣤⣤⣤⣶⡆⣿⣿⣿⡗⠂⣤⠄⠄⠈⠆
 * ⠄⠄⠄⠄⠄⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⡿⢃⢿⣿⣿⣷⠸⠁⠄⠄⠄⠄
 * ⠄⠄⠄⠄⢀⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⢰⡾⠁⠈⠉⠝⢀⡀⠄⡀⡰⠃
 * ⠄⠄⠄⠄⢸⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⠦⠄⠄⠐⠄⢀⣼⣿⣿⣿⣿⠁
 * ⠄⠄⠄⢠⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⠟⠋⠄⠄⠄⠄⠄⠸⣿⣿⣿⣿⣿⠁
 * ⠄⠄⢀⣾⣿⣿⣿⣿⣿⣿⣿⣿⣿⡿⠋⠁⢀⣀⣤⣤⣤⣄⠄⠄⣿⣿⣿⣿⡿⠄
 * ⠄⠄⢼⣿⣿⣿⣿⣿⣿⣿⣿⣿⣟⠄⢀⣼⣿⣟⠛⠛⠛⢿⣷⠄⢸⣿⣿⣿⠃⠄
 * ⠄⠄⠄⠄⠈⠻⠿⠿⠿⠿⠟⠋⠻⣿⣿⣿⣿⣿⣿⣷⣦⡌⣿⡀⢸⣿⣿⣿⠄⠄
 * ⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⣿⣿⣇⠄⠄⠄⢻⣿⣿⣧⣿⣿⣿⡇⠄⠄
 * ⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠈⠙⢻⣿⣆⠄⠸⣿⣿⣿⣿⠉⠛⠄⠄⠄
 *
 * Class Provider
 * @package Upmind\ProvisionProviders\DomainNames\OpenSRS
 */
class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var OpenSrsConfiguration
     */
    protected $configuration;

    /**
     * @var OpenSrsApi
     */
    protected $apiClient;

    /**
     * Max count of name servers that we can expect in a request
     */
    private const MAX_CUSTOM_NAMESERVERS = 5;

    public function __construct(OpenSrsConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return AboutData
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('OpenSRS')
            ->setDescription('Register, transfer, renew and manage OpenSRS domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/opensrs-logo@2x.png');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        throw $this->errorResult('Operation not supported');

        // Get Domains
        $domains = [];

        $max = 30;
        $start = 0;

        foreach (Arr::get($params, 'domains') as $domain) {
            $domains[] = Utils::getDomain($domain['sld'], $domain['tld']);

            $start++;

            // Allow up to 30 domains in one check
            if ($start == $max) {
                break;
            }
        }

        try {
            $domainsCheck = [];

            foreach ($domains as $domain) {
                $lookupRaw = $this->api()->makeRequest([
                    'action' => 'LOOKUP',
                    'object' => 'DOMAIN',
                    'protocol' => 'XCP',
                    'attributes' => [
                        'domain' => $domain,
                    ]
                ]);

                $domainsCheck[] = [
                    'domain' => $domain,
                    'available' => $lookupRaw['attributes']['status'] == 'taken' ? false : true,
                    'reason' => ''
                ];
            }

            return $this->okResult("Domain Check Results", $domainsCheck);
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @param RegisterDomainParams $params
     * @return DomainResult
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        // Get Params
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');
        $domain = Utils::getDomain($sld, $tld);

        try {
            if (!Arr::has($params, 'registrant.register')) {
                return $this->errorResult('Registrant contact data is required!');
            }

            if (!Arr::has($params, 'tech.register')) {
                return $this->errorResult('Tech contact data is required!');
            }

            if (!Arr::has($params, 'admin.register')) {
                return $this->errorResult('Admin contact data is required!');
            }

            if (!Arr::has($params, 'billing.register')) {
                return $this->errorResult('Billing contact data is required!');
            }

            // Register the domain with the registrant contact data
            $nameServers = [];

            for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
                if (Arr::has($params, 'nameservers.ns' . $i)) {
                    $nameServers[] = [
                        'name' => Arr::get($params, 'nameservers.ns' . $i)->host,
                        'sortorder' => $i
                    ];
                }
            }

            $contactData = [
                OpenSrsApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
                OpenSrsApi::CONTACT_TYPE_TECH => $params->tech->register,
                OpenSrsApi::CONTACT_TYPE_ADMIN => $params->admin->register,
                OpenSrsApi::CONTACT_TYPE_BILLING => $params->billing->register
            ];

            $contacts = [];

            foreach ($contactData as $type => $contactParams) {
                $nameParts = OpenSrsApi::getNameParts($contactParams->name ?? $contactParams->organisation);

                $contacts[$type] = array_filter([
                    'country' => Utils::normalizeCountryCode($contactParams->country_code),
                    'org_name' => $contactParams->organisation ?: $contactParams->name,
                    'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
                    'postal_code' => $contactParams->postcode,
                    'city' => $contactParams->city,
                    'email' => $contactParams->email,
                    'address1' => $contactParams->address1,
                    'first_name' => $nameParts['firstName'],
                    'last_name' => $nameParts['lastName'],
                    'state' => Utils::stateNameToCode($contactParams->country_code, $contactParams->state),
                ]);
            }

            $result = $this->api()->makeRequest([
                'action' => 'SW_REGISTER',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    //'f_whois_privacy' => '' // TODO - privacy
                    'domain' => $domain,
                    'reg_username' => bin2hex(random_bytes(6)),
                    'reg_password' => bin2hex(random_bytes(6)),
                    'handle' => 'process',
                    'period' => Arr::get($params, 'renew_years', 1),
                    'reg_type' => 'new',
                    'custom_nameservers' => 1,
                    'contact_set' => $contacts,
                    'custom_tech_contact' => 0,
                    'nameserver_list' => $nameServers
                ]
            ]);

            if (!empty($result['attributes']['forced_pending'])) {
                // domain could not be registered at this time
                return $this->errorResult('Domain registration pending approval', $result);
            }

            // Return newly fetched data for the domain
            return $this->_getInfo($sld, $tld, sprintf('Domain %s was registered successfully!', $domain));
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param TransferParams $params
     * @return DomainResult
     */
    public function transfer(TransferParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);

        $checkPendingResult = $this->api()->makeRequest([
            'action' => 'get_transfers_in',
            'object' => 'DOMAIN',
            'protocol' => 'XCP',
            'attributes' => [
                'domain' => $domain,
            ],
        ]);

        foreach ($checkPendingResult['attributes']['transfers'] ?? [] as $transfer) {
            if (!empty($transfer['completed_date'])) {
                continue; // if transfer is completed, great
            }

            $initiated = Carbon::createFromTimestamp($transfer['order_date_epoch'])
                ->diffForHumans([
                    'parts' => 1,
                    'options' => CarbonInterface::ROUND,
                ]); // X days ago

            switch ($transfer['status']) {
                case 'completed':
                case 'cancelled':
                    continue 2;
                case 'pending_owner':
                    return $this->errorResult(
                        sprintf('Transfer initiated %s is pending domain owner approval', $initiated),
                        ['transfer' => $transfer]
                    );
                case 'pending_registry':
                    return $this->errorResult(
                        sprintf('Transfer initiated %s is pending registry approval', $initiated),
                        ['transfer' => $transfer]
                    );
                default:
                    return $this->errorResult(
                        sprintf('Transfer initiated %s is in progress', $initiated),
                        ['transfer' => $transfer]
                    );
            }
        }

        try {
            return $this->_getInfo($sld, $tld, 'Domain active in registrar account!');
        } catch (\Throwable $e) {
            // ignore error and attempt to initiate transfer
        }

        $period = Arr::get($params, 'renew_years', 1);
        $eppCode = Arr::get($params, 'epp_code', "");

        $contacts = [];

        if (!Arr::has($params, 'registrant.register')) {
            return $this->errorResult('Registrant contact data is required!');
        }

        $contactData = [
            OpenSrsApi::CONTACT_TYPE_REGISTRANT => Arr::get($params, 'registrant.register'),
            OpenSrsApi::CONTACT_TYPE_TECH => Arr::get($params, 'tech.register') ?? Arr::get($params, 'registrant.register'),
            OpenSrsApi::CONTACT_TYPE_ADMIN => Arr::get($params, 'admin.register') ?? Arr::get($params, 'registrant.register'),
            OpenSrsApi::CONTACT_TYPE_BILLING => Arr::get($params, 'billing.register') ?? Arr::get($params, 'registrant.register'),
        ];

        foreach ($contactData as $type => $contactParams) {
            /** @var ContactParams $contactParams */
            $nameParts = OpenSrsApi::getNameParts($contactParams->name ?: $contactParams->organisation);

            $contacts[$type] = [
                'country' => Utils::normalizeCountryCode($contactParams->country_code),
                'state' => Utils::stateNameToCode($contactParams->country_code, $contactParams->state),
                'org_name' => $contactParams->organisation ?: $contactParams->name,
                'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
                'postal_code' => $contactParams->postcode,
                'city' => $contactParams->city,
                'email' => $contactParams->email,
                'address1' => $contactParams->address1,
                'first_name' => $nameParts['firstName'],
                'last_name' => $nameParts['lastName'],
            ];
        }

        try {
            $username = substr(str_replace(['.', '-'], '', $sld), 0, 16)
                . substr(str_replace(['.', '-'], '', $tld), 0, 4);

            $this->api()->makeRequest([
                'action' => 'SW_REGISTER',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'reg_username' => $username,
                    'reg_password' => bin2hex(random_bytes(6)),
                    'auth_info' => $eppCode,
                    'change_contact' => 0,
                    'handle' => 'process',
                    'period' => $period,
                    'reg_type' => 'transfer',
                    'custom_tech_contact' => 0,
                    'custom_nameservers' => 0,
                    'link_domains' => 0,
                    'contact_set' => $contacts
                ]
            ]);

            return $this->errorResult('Domain transfer initiated');

            /*return DomainResult::create([
                'id' => $domain,
                'domain' => $domain,
                'statuses' => [], // nothing relevant here right now
                'registrant' => DomainContactInfo::create($contactData[OpenSrsApi::CONTACT_TYPE_REGISTRANT]),
                'ns' => [],
                'created_at' => Carbon::today()->toDateString(),
                'updated_at' => Carbon::today()->toDateString(),
                'expires_at' => Carbon::today()->toDateString()
            ])->setMessage('Domain transfer has been initiated!');*/
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param RenewParams $params
     * @return DomainResult
     */
    public function renew(RenewParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);
        $period = Arr::get($params, 'renew_years', 1);

        try {
            // We need to know the current expiration year
            $domainRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'type' => 'all_info',
                    'clean_ca_subset' => 1,
                    //'active_contacts_only' => 1
                ]
            ]);

            $expiryDate = Carbon::parse($domainRaw['attributes']['expiredate']);

            // Set renewal data
            $domainRaw = $this->api()->makeRequest([
                'action' => 'RENEW',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'handle' => 'process',
                    'period' => $period,
                    'currentexpirationyear' => $expiryDate->year
                    //'premium_price_to_verify' => 'PREMIUM-DOMAIN-PRICE'
                ]
            ]);

            // Get Domain Info (again)
            return $this->_getInfo(
                $sld,
                $tld,
                sprintf('Renewal for %s domain was successful!', $domain)
            );
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param DomainInfoParams $params
     * @return DomainResult
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        try {
            return $this->_getInfo(Arr::get($params, 'sld'), Arr::get($params, 'tld'), 'Domain data obtained');
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param string $message
     * @return DomainResult
     */
    private function _getInfo(string $sld, string $tld, string $message): DomainResult
    {
        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'type' => 'all_info',
                    'clean_ca_subset' => 1,
                    // 'active_contacts_only' => 1
                ]
            ]);

            $statusRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'type' => 'status',
                    // 'clean_ca_subset' => 1,
                    // 'active_contacts_only' => 1
                ]
            ]);
        } catch (ProvisionFunctionError $e) {
            if (
                Str::contains($e->getMessage(), 'Authentication Error')
                && !Str::contains($e->getMessage(), 'Registrar API Authentication Error')
            ) {
                // this actually means domain not found
                $this->errorResult('Domain name not found', $e->getData(), $e->getDebug(), $e);
            }

            throw $e;
        }

        $domainInfo = [
            'id' => (string) Utils::getDomain($sld, $tld),
            'domain' => (string) Utils::getDomain($sld, $tld),
            'statuses' => array_map(function ($status) {
                return $status === '' ? 'n/a' : (string)$status;
            }, $statusRaw['attributes']),
            'registrant' => OpenSrsApi::parseContact($domainRaw['attributes']['contact_set'], OpenSrsApi::CONTACT_TYPE_REGISTRANT),
            'ns' => OpenSrsApi::parseNameServers($domainRaw['attributes']['nameserver_list']),
            'created_at' => $domainRaw['attributes']['registry_createdate'],
            'updated_at' => $domainRaw['attributes']['registry_updatedate'] ?? $domainRaw['attributes']['registry_createdate'],
            'expires_at' => $domainRaw['attributes']['expiredate'],
            'locked' => boolval($statusRaw['attributes']['lock_state']),
        ];

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    /**
     * @param UpdateNameserversParams $params
     * @return NameserversResult
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        // Get Domain Name and NameServers
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $nameServers = [];
        $currentNameServers = [];
        $nameServersForResponse = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameServer = Arr::get($params, 'ns' . $i);
                $nameServers[] = $nameServer->toArray()['host'];
                $nameServersForResponse['ns' . $i] = ['host' => $nameServer->toArray()['host'], 'ip' => null];
            }
        }

        try {
            // Get current nameservers, which will be removed
            $currentNameServersRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'type' => 'nameservers'
                ]
            ]);

            foreach ($currentNameServersRaw['attributes']['nameserver_list'] as $ns) {
                $currentNameServers[] = $ns['name'];
            }

            // Make sure the new naneservers exist in the registry
            foreach ($nameServers as $ns) {
                $existsData = $this->api()->makeRequest([
                    'action' => 'REGISTRY_CHECK_NAMESERVER',
                    'object' => 'NAMESERVER',
                    'protocol' => 'XCP',
                    'attributes' => [
                        'tld' => Arr::get($params, 'tld'),
                        'fqdn' => $ns
                    ]
                ]);

                if ((int) $existsData['response_code'] == 212) {
                    // NameServer doesn't exists in the registry so we need to add it.
                    $this->api()->makeRequest([
                       'action' => 'REGISTRY_ADD_NS',
                       'object' => 'NAMESERVER',
                       'protocol' => 'XCP',
                       'attributes' => [
                           'tld' => Arr::get($params, 'tld'),
                           'fqdn' => $ns,
                           'all' => 0
                       ]
                    ]);
                }
            }

            // Prepare params
            $requestParams = [
                'action' => 'ADVANCED_UPDATE_NAMESERVERS',
                'object' => 'NAMESERVER',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'op_type' => 'add_remove',
                    'add_ns' => $nameServers
                ]
            ];

            // Remove old
            $toRemove = array_values(array_diff($currentNameServers, $nameServers));

            if (count($toRemove) > 0) {
                $requestParams['attributes']['remove_ns'] = $toRemove;
            }

            // Update nameservers
            $nameServersRaw = $this->api()->makeRequest($requestParams);

            return NameserversResult::create($nameServersForResponse)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domain));
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * Emails EPP code to the registrant's email address.
     *
     * @param EppParams $params
     * @return EppCodeResult
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'type' => 'domain_auth_info'
                ]
            ]);

            return EppCodeResult::create([
                'epp_code' => $domainRaw['attributes']['domain_auth_info']
            ])->setMessage('EPP/Auth code obtained');
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param UpdateDomainContactParams $params
     * @return ContactResult
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        return $this->updateContact($params->sld, $params->tld, $params->contact, OpenSrsApi::CONTACT_TYPE_REGISTRANT);
    }

    /**
     * @param LockParams $params
     * @return ResultData
     */
    public function setLock(LockParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');
        $lock = (bool) Arr::get($params, 'lock', false);

        $domain = Utils::getDomain($sld, $tld);

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'MODIFY',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'data' => 'status',
                    'lock_state' => (int) $lock
                ]
            ]);

            return $this->_getInfo($sld, $tld, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param AutoRenewParams $params
     * @return ResultData
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);
        $autoRenew = (bool) $params->auto_renew;

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'MODIFY',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'data' => 'expire_action',
                    'auto_renew' => (int) $autoRenew,
                    'let_expire' => 0
                ]
            ]);

            return $this->_getInfo($sld, $tld, 'Domain auto-renew mode updated');
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param IpsTagParams $params
     * @return ResultData
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);
        $ipsTag = Arr::get($params, 'ips_tag');

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'MODIFY',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'affect_domains' => 0,
                    'change_tag_all' => 0,
                    'domain' => Utils::getDomain($sld, $tld),
                    'data' => 'change_ips_tag',
                    'gaining_registrar_tag' => $ipsTag
                ]
            ]);

            return $this->okResult(sprintf("IPS tag for domain %s has been changed!", $domain));
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param ContactParams $params
     * @param string $type
     * @return ContactResult
     */
    private function updateContact(string $sld, string $tld, ContactParams $params, string $type): ContactResult
    {
        try {
            $nameParts = OpenSrsApi::getNameParts($params->name ?? $params->organisation);

            $updateContactRaw = $this->api()->makeRequest([
                'action' => 'UPDATE_CONTACTS',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'types' => [$type],
                    'contact_set' => [
                        $type => [
                            'country' => Utils::normalizeCountryCode($params->country_code),
                            'state' => Utils::stateNameToCode($params->country_code, $params->state),
                            'org_name' => $params->organisation,
                            'phone' => Utils::internationalPhoneToEpp($params->phone),
                            'postal_code' => $params->postcode,
                            'city' => $params->city,
                            'email' => $params->email,
                            'address1' => $params->address1,
                            'first_name' => $nameParts['firstName'],
                            'last_name' => $nameParts['lastName'],
                        ]
                    ]
                ]
            ]);

            return ContactResult::create([
                'contact_id' => strtolower($type),
                'name' => $params->name,
                'email' => $params->email,
                'phone' => $params->phone,
                'organisation' => $params->organisation,
                'address1' => $params->address1,
                'city' => $params->city,
                'postcode' => $params->postcode,
                'country_code' => $params->country_code,
                'state' => Utils::stateNameToCode($params->country_code, $params->state),
            ])->setMessage('Contact details updated');
        } catch (\Throwable $e) {
            return $this->handleError($e, $params);
        }
    }

    /**
     * @param Throwable $e Encountered error
     * @param DataSet|mixed[] $params
     *
     * @throws ProvisionFunctionError
     *
     * @return no-return
     */
    protected function handleError(Throwable $e, $params): void
    {
        if ($e instanceof ProvisionFunctionError) {
            throw $e;
        }

        throw $e; // i dont want to just blindly copy any unknown error message into a the result
    }

    protected function api(): OpenSrsApi
    {
        if (isset($this->apiClient)) {
            return $this->apiClient;
        }

        $client = new Client([
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
        ]);

        return $this->apiClient = new OpenSrsApi($client, $this->configuration);
    }
}
