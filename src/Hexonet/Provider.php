<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet;

use GuzzleHttp\Client;
use HEXONET\APIClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\FinishTransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\InitiateTransferResult;
use Upmind\ProvisionProviders\DomainNames\Hexonet\Helper\EppHelper;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
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
use Upmind\ProvisionProviders\DomainNames\Hexonet\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\EppConnection;
use Upmind\ProvisionProviders\DomainNames\Hexonet\Helper\HexonetApi;

/**
 * "Curiosity killed the cat..."
 *
 *       /\_____/\
 *      /  o   o  \
 *     ( ==  ^  == )
 *      )         (
 *     (           )
 *     ( (  )   (  ) )
 *     (__(__)___(__)__)
 *
 * This provider class makes use of 2 libraries.
 * One is for EPP Communication
 * The second one is the official Hexonet PHP SDK, which is used here for only specific tasks like getting a full list of contacts in the reseller's account. Reason for using this one is because this one can not be accomplished with the EPP.
 *
 * Class Provider
 * @package Upmind\ProvisionProviders\DomainNames\Hexonet
 */
class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var EppConnection|null
     */
    protected $connection;

    /**
     * @var HexonetApi|null
     */
    protected $hexonetApi;

    // Name Servers for Hexonet
    private const NAMESERVERS = [
        [
            'host' => 'ns1.ispapi.net', // Alternatively: ns1.hexonet.net,
            'ip' => '194.50.187.134'
        ],
        [
            'host' => 'ns2.ispapi.net', // Alternatively: ns2.hexonet.net,
            'ip' => '194.0.182.1'
        ],
        [
            'host' => 'ns3.ispapi.net', // Alternatively: ns3.hexonet.net,
            'ip' => '193.227.117.124'
        ]
    ];

    /**
     * Max count of name servers that we can expect in a request
     */
    private const MAX_CUSTOM_NAMESERVERS = 5;

    // Contact types.
    public const CONTACT_LOC = 'loc';
    public const CONTACT_INT = 'int';
    public const CONTACT_AUTO = 'auto';

    public function __construct(Configuration $configuration)
    {
        // dont connect straight away - wait until function call for any connection errors to surface
        $this->configuration = $configuration;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Returns general info about the package
     *
     * @return AboutData
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Hexonet')
            ->setDescription('Register, transfer, renew and manage Hexonet domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/hexonet-logo@2x.png');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $dac = new Dac($this->configuration, new Client([
            'handler' => $this->getGuzzleHandlerStack(!!$this->configuration->sandbox),
        ]));

        return $dac->search($params->sld, $params->tlds);
    }

    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * Domain Registration
     *
     * @param RegisterDomainParams $params
     * @return ResultData
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        // Establish connection to Hexonet API via EPP protocol
        try {
            $connection = $this->connect();

            // Loop Over the Contact Types and create them one by one
            if (Arr::has($params, 'registrant.id')) {
                $registrantId = Arr::get($params, 'registrant.id');

                // Validate the contactId
                if (!EppHelper::isValidContactId($connection, $registrantId)) {
                    throw $this->errorResult("Invalid registrant ID provided!", $params);
                }
            } else {
                // Try to set contact type
                if (
                    Arr::has($params, 'registrant.register.type')
                        && in_array(Arr::get($params, 'registrant.register.type'), [
                            self::CONTACT_LOC,
                            self::CONTACT_INT,
                            self::CONTACT_AUTO
                        ])
                ) {
                    $contactType = Arr::get($params, 'registrant.register.type');
                } else {
                    $contactType = self::CONTACT_AUTO;
                }

                // Create contact instance and get the ID as a string
                $registrant = EppHelper::createContact(
                    $connection,
                    Arr::get($params, 'registrant.register.email'),
                    Arr::get($params, 'registrant.register.phone'),
                    Arr::get($params, 'registrant.register.name', Arr::get($params, 'registrant.register.organisation')),
                    Arr::get($params, 'registrant.register.organisation', Arr::get($params, 'registrant.register.name')),
                    Arr::get($params, 'registrant.register.address1'),
                    Arr::get($params, 'registrant.register.postcode'),
                    Arr::get($params, 'registrant.register.city'),
                    Arr::get($params, 'registrant.register.state'),
                    Arr::get($params, 'registrant.register.country_code'),
                    $contactType
                );

                $registrantId = $registrant->id;
            }

            if (!$adminId = $params->admin->id) {
                // Try to set contact type
                if (
                    Arr::has($params, 'admin.register.type')
                    && in_array(Arr::get($params, 'admin.register.type'), [
                        self::CONTACT_LOC,
                        self::CONTACT_INT,
                        self::CONTACT_AUTO
                    ])
                ) {
                    $contactType = Arr::get($params, 'admin.register.type');
                } else {
                    $contactType = self::CONTACT_AUTO;
                }

                // Create contact instance and get the ID as a string
                $admin = EppHelper::createContact(
                    $connection,
                    Arr::get($params, 'admin.register.email'),
                    Arr::get($params, 'admin.register.phone'),
                    Arr::get($params, 'admin.register.name', Arr::get($params, 'admin.register.organisation')),
                    Arr::get($params, 'admin.register.organisation', Arr::get($params, 'admin.register.name')),
                    Arr::get($params, 'admin.register.address1'),
                    Arr::get($params, 'admin.register.postcode'),
                    Arr::get($params, 'admin.register.city'),
                    Arr::get($params, 'admin.register.state'),
                    Arr::get($params, 'admin.register.country_code'),
                    $contactType
                );

                $adminId = $admin->id;
            }

            if (!$billingId = $params->billing->id) {
                // Try to set contact type
                if (
                    Arr::has($params, 'billing.register.type')
                    && in_array(Arr::get($params, 'billing.register.type'), [
                        self::CONTACT_LOC,
                        self::CONTACT_INT,
                        self::CONTACT_AUTO
                    ])
                ) {
                    $contactType = Arr::get($params, 'billing.register.type');
                } else {
                    $contactType = self::CONTACT_AUTO;
                }

                // Create contact instance and get the ID as a string
                $billing = EppHelper::createContact(
                    $connection,
                    Arr::get($params, 'billing.register.email'),
                    Arr::get($params, 'billing.register.phone'),
                    Arr::get($params, 'billing.register.name', Arr::get($params, 'billing.register.organisation')),
                    Arr::get($params, 'billing.register.organisation', Arr::get($params, 'billing.register.name')),
                    Arr::get($params, 'billing.register.address1'),
                    Arr::get($params, 'billing.register.postcode'),
                    Arr::get($params, 'billing.register.city'),
                    Arr::get($params, 'billing.register.state'),
                    Arr::get($params, 'billing.register.country_code'),
                    $contactType
                );

                $billingId = $billing->id;
            }

            if (!$techId = $params->tech->id) {
                // Try to set contact type
                if (
                    Arr::has($params, 'tech.register.type')
                    && in_array(Arr::get($params, 'tech.register.type'), [
                        self::CONTACT_LOC,
                        self::CONTACT_INT,
                        self::CONTACT_AUTO
                    ])
                ) {
                    $contactType = Arr::get($params, 'tech.register.type');
                } else {
                    $contactType = self::CONTACT_AUTO;
                }

                // Create contact instance and get the ID as a string
                $tech = EppHelper::createContact(
                    $connection,
                    Arr::get($params, 'tech.register.email'),
                    Arr::get($params, 'tech.register.phone'),
                    Arr::get($params, 'tech.register.name', Arr::get($params, 'tech.register.organisation')),
                    Arr::get($params, 'tech.register.organisation', Arr::get($params, 'tech.register.name')),
                    Arr::get($params, 'tech.register.address1'),
                    Arr::get($params, 'tech.register.postcode'),
                    Arr::get($params, 'tech.register.city'),
                    Arr::get($params, 'tech.register.state'),
                    Arr::get($params, 'tech.register.country_code'),
                    $contactType
                );

                $techId = $tech->id;
            }

            // Determine which name servers to use
            $ownNameServers = null;

            for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
                if (Arr::has($params, 'nameservers.ns' . $i)) {
                    $ownNameServers[] = Arr::get($params, 'nameservers.ns' . $i);
                }
            }

            // Use the default name servers in case we didn't provide our own
            if (!is_null($ownNameServers)) {
                $nameServers = $ownNameServers;
            } else {
                $nameServers = self::NAMESERVERS;
            }

            // Proceed to domain registration
            $domainRegistration = EppHelper::createDomain(
                $connection,
                $domain,
                intval(Arr::get($params, 'renew_years', 1)),
                $registrantId,
                $adminId,
                $billingId,
                $techId,
                $nameServers
            );

            return $this->_getInfo($domain, sprintf('Domain %s was registered successfully!', $domain));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    /**
     * Transfer a domain (submit transfer request)
     *
     * @param TransferParams $params
     * @return ResultData
     */
    public function transfer(TransferParams $params): DomainResult
    {
        // Get the domain name
        $domain = Utils::getDomain($params->sld, $params->tld);
        $eppCode = $params->epp_code;

        try {
            // Establish the connection
            $connection = $this->connect();

            try {
                // if domain is active, return success
                return $this->_getInfo($domain, 'Domain exists in registrar account');
            } catch (eppException $e) {
                // domain is not active - proceed with initiating a transfer below...
            }

            $transferQuery = EppHelper::queryTransferList($connection, $domain);
            if ($transferQuery->transferExists()) {
                throw $this->errorResult(
                    sprintf('Transfer already initiated %s', $transferQuery->transferDate()->diffForHumans()),
                    $transferQuery->getData()
                );
            }

            $checkData = EppHelper::checkTransfer($connection, $domain, $eppCode)->getData();
            $userTransfer = !empty($checkData['USERTRANSFERREQUIRED']);

            // if (isset($params->registrant->register)) {
            //     // Create contact instance and get the ID as a string
            //     $registrant = EppHelper::createContact(
            //         $connection,
            //         $params->registrant->register->email,
            //         $params->registrant->register->phone,
            //         $params->registrant->register->name ?: $params->registrant->register->organisation,
            //         $params->registrant->register->organisation ?: $params->registrant->register->name,
            //         $params->registrant->register->address1,
            //         $params->registrant->register->postcode,
            //         $params->registrant->register->city,
            //         $params->registrant->register->state,
            //         $params->registrant->register->country_code,
            //         self::CONTACT_AUTO
            //     );
            // }

            // if (isset($params->billing->register)) {
            //     // Create contact instance and get the ID as a string
            //     $billing = EppHelper::createContact(
            //         $connection,
            //         $params->billing->register->email,
            //         $params->billing->register->phone,
            //         $params->billing->register->name ?: $params->billing->register->organisation,
            //         $params->billing->register->organisation ?: $params->billing->register->name,
            //         $params->billing->register->address1,
            //         $params->billing->register->postcode,
            //         $params->billing->register->city,
            //         $params->billing->register->state,
            //         $params->billing->register->country_code,
            //         self::CONTACT_AUTO
            //     );
            // }

            // if (isset($params->tech->register)) {
            //     // Create contact instance and get the ID as a string
            //     $tech = EppHelper::createContact(
            //         $connection,
            //         $params->tech->register->email,
            //         $params->tech->register->phone,
            //         $params->tech->register->name ?: $params->tech->register->organisation,
            //         $params->tech->register->organisation ?: $params->tech->register->name,
            //         $params->tech->register->address1,
            //         $params->tech->register->postcode,
            //         $params->tech->register->city,
            //         $params->tech->register->state,
            //         $params->tech->register->country_code,
            //         self::CONTACT_AUTO
            //     );
            // }

            // if (isset($params->admin->register)) {
            //     // Create contact instance and get the ID as a string
            //     $admin = EppHelper::createContact(
            //         $connection,
            //         $params->admin->register->email,
            //         $params->admin->register->phone,
            //         $params->admin->register->name ?: $params->admin->register->organisation,
            //         $params->admin->register->organisation ?: $params->admin->register->name,
            //         $params->admin->register->address1,
            //         $params->admin->register->postcode,
            //         $params->admin->register->city,
            //         $params->admin->register->state,
            //         $params->admin->register->country_code,
            //         self::CONTACT_AUTO
            //     );
            // }

            $this->api()->initiateTransfer(
                $domain,
                intval($params->renew_years),
                $params->epp_code,
                $params->registrant->register ?? null,
                $params->admin->register ?? null,
                $params->tech->register ?? null,
                $params->billing->register ?? null,
                $userTransfer
            );

            // try {
            //     // Request Domain Transfer
            //     EppHelper::transferRequest(
            //         $connection,
            //         $domain,
            //         [],
            //         $registrant->contact_id ?? null,
            //         $admin->contact_id ?? null,
            //         $billing->contact_id ?? null,
            //         $tech->contact_id ?? null,
            //         $eppCode,
            //         intval($params->renew_years)
            //     );
            // } catch (eppException $e) {
            //     $errorMessage = $e->getReason() ?: $e->getMessage();

            //     if (Str::startsWith($errorMessage, '531 Authorization failed')) {
            //         $errorMessage = sprintf('Incorrect EPP/Auth Code for domain %s', $domain);
            //     }

            //     if (Str::startsWith($errorMessage, '504 Missing required attribute') && empty($eppCode)) {
            //         $errorMessage = sprintf('EPP/Auth Code required to initiate transfer of %s', $domain);
            //     }

            //     throw $this->errorResult($errorMessage, $params, [], $e);
            // }

            try {
                // if domain is active, return success
                return $this->_getInfo($domain, 'Domain exists in registrar account');
            } catch (eppException $e) {
                // domain transfer in progress
                throw $this->errorResult(sprintf('Transfer of %s initiated and now in progress', $domain), [
                    'domain' => $domain
                ]);
            }
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }


    public function initiateTransfer(TransferParams $params): InitiateTransferResult
    {
        throw $this->errorResult('Operation not supported');
    }

    public function finishTransfer(FinishTransferParams $params): DomainResult{
        throw $this->errorResult('Operation not supported');
    }
    /**
     * Renew a domain
     *
     * @param RenewParams $params
     * @return ResultData
     */
    public function renew(RenewParams $params): DomainResult
    {
        // Get the domain name
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain(Arr::get($params, 'sld'), $tld);
        $period = Arr::get($params, 'renew_years');

        try {
            // Establish the connection
            $connection = $this->connect();

            if (!Utils::tldSupportsExplicitRenewal($tld)) {
                // Call PayDomainRenewal to mark domain as paid - renewal will occur implicitly at end of term
                $this->api()->markDomainRenewalAsPaid($domain);

                return $this->_getInfo($domain, sprintf('Renewal for %s domain scheduled for end of period', $domain));
            }

            EppHelper::renewDomain($connection, $domain, $period);

            return $this->_getInfo($domain, sprintf('Renewal for %s domain was successful!', $domain));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    /**
     * Returns full domain information
     *
     * @param DomainInfoParams $params
     * @return ResultData
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        // Get the domain name
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        try {
            return $this->_getInfo($domain);
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    /**
     * @throws eppException If call to get domain info fails
     */
    public function _getInfo(string $domain, $msg = 'Domain data obtained'): DomainResult
    {
        // Get Domain Info
        $connection = $this->connect();
        $domainInfo = EppHelper::getDomainInfo($connection, $domain);

        $lockedStatuses = [
            'clientTransferProhibited',
            'clientUpdateProhibited',
            'clientDeleteProhibited',
        ];
        $domainInfo['locked'] = boolval(array_intersect($lockedStatuses, $domainInfo['statuses']));

        if (true || !Utils::tldSupportsExplicitRenewal(Utils::getTld($domain))) {
            // For non-explicitly renewable domains, return the "paid until" date, which is effectively the real expiry
            $domainInfo['expires_at'] = $this->api()->statusDomain($domain)['PAIDUNTILDATE'][0]
                ?? $domainInfo['expires_at'];
        }

        if (isset($domainInfo['ns'])) {
            foreach ($domainInfo['ns'] as $key => $ns) {
                if (isset($ns['ip']) && is_array($ns['ip'])) {
                    foreach ($ns['ip'] as $k => $v) {
                        $domainInfo['ns'][$key]['ip'] = $k;
                    }
                }
            }
        }

        return DomainResult::create($domainInfo, false)
            ->setMessage($msg);
    }

    /**
     * Update Name Servers for a given domain
     *
     * @param UpdateNameserversParams $params
     * @return ResultData
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        // Get Domain Name and NameServers
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $nameServers = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameServers[] = Arr::get($params, 'ns' . $i);
            }
        }

        try {
            $newNameservers = $this->api()->setNameservers($domain, $nameServers);

            $returnNameservers = [];
            foreach ($newNameservers as $i => $ns) {
                $returnNameservers['ns' . ($i + 1)] = $ns;
            }

            return NameserversResult::create($returnNameservers)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domain));
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    /**
     * Returns EPP Code for a given domain
     *
     * @param EppParams $params
     * @return ResultData
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        // Get the domain name
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        try {
            // Establish the connection
            $connection = $this->connect();

            // Get Domain Info
            $eppCode = EppHelper::getDomainEppCode($connection, $domain);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    /**
     * Update IPS Tag for a given domain
     *
     * @param IpsTagParams $params
     * @return ResultData
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported', $params);
    }

    /**
     * Update Registrant Contact for a given domain
     *
     * @param UpdateDomainContactParams $params
     * @return ResultData
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        // Dont use this approach for now - for registrant name/org changes it throws an error requiring a trade
        // return to it when we next have an example of a domain with 531 Authorization Error for registrant updates:
        // $api = HexonetHelper::establishConnection($this->configuration->toArray());
        // return HexonetHelper::updateRegistrant($api, Utils::getDomain($params->sld, $params->tld), $params->contact)
        //     ->setMessage('Registrant contact details updated');

        $domain = Utils::getDomain($params->sld, $params->tld);

        return $this->createContact($domain, eppContactHandle::CONTACT_TYPE_REGISTRANT, $params->contact)
            ->setMessage('Registrant contact details updated');
    }

    /**
     * A generic function to handle all contact create/update actions
     *
     * @deprecated Always create a new contact instead of updating existing handle
     *
     * @param UpdateDomainContactParams $params
     * @param string $contactType One of: reg, billing, admin, tech
     *
     * @throws ProvisionFunctionError
     *
     * @return ContactResult
     */
    protected function updateCreateContact(UpdateDomainContactParams $params, string $contactType): ContactResult
    {
        // Get the domain name
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        try {
            // Establish the connection
            $connection = $this->connect();

            $contactId = EppHelper::getDomainContactId($connection, $domain, $contactType);

            if (!$contactId || $contactId === 'USER') {
                // no contact currently set, or is set to immutable reseller contact; create a new one
                return $this->createContact($domain, $contactType, $params->contact);
            }

            return $this->updateContact($contactId, $params->contact);
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    /**
     * Unlocks/Locks a domain for update, delete + transfer.
     *
     * Toggles ClientProhibitedTransfer, clientUpdateProhibited + clientDeleteProhibited statuses of a domain.
     */
    public function setLock(LockParams $params): DomainResult
    {
        // Get the domain name
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        $lock = Arr::get($params, 'lock');

        try {
            $domainInfo = $this->_getInfo($domain);

            $lockedStatuses = [
                'clientTransferProhibited',
                'clientUpdateProhibited',
                'clientDeleteProhibited',
            ];

            $params = [
                'domain' => $domain,
            ];

            if ($lock) {
                // add statuses
                if (!$addStatuses = array_diff($lockedStatuses, $domainInfo->statuses)) {
                    return $domainInfo->setMessage('Domain already locked');
                }

                foreach (array_values($addStatuses) as $i => $status) {
                    $params['addstatus' . $i] = $status;
                }

                $newStatuses = array_merge($domainInfo->statuses, $addStatuses);
            } else {
                // remove statuses
                if (!$removeStatuses = array_intersect($lockedStatuses, $domainInfo->statuses)) {
                    return $domainInfo->setMessage('Domain already unlocked');
                }

                foreach (array_values($removeStatuses) as $i => $status) {
                    $params['delstatus' . $i] = $status;
                }

                $newStatuses = array_diff($domainInfo->statuses, $removeStatuses);
            }

            $setLock = $this->api()->runCommand('ModifyDomain', $params);

            $domainInfo = array_merge($domainInfo->all(), ['locked' => $lock, 'statuses' => $newStatuses]);
            return DomainResult::create($domainInfo)
                ->setMessage(sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'))
                ->setDebug($setLock->getHash());
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    /**
     * Changes the renewal mode to autorenew or autoexpire
     *
     * @param AutoRenewParams $params
     * @return ResultData
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        // Get the domain name
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        $autoRenew = !!$params->auto_renew;

        try {
            // Unlock domain for transfer
            $setRenewalMode = $this->api()->setRenewalMode($domain, $autoRenew);

            // Process Response
            if (isset($setRenewalMode['error'])) {
                throw $this->errorResult($setRenewalMode['error'], $params, $setRenewalMode);
            }

            return $this->_getInfo($domain, 'Auto-renew mode updated');
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    protected function createContact(string $domain, string $contactType, ContactParams $params): ContactResult
    {
        // Establish the connection
        $connection = $this->connect();

        // Create contact
        $contact = EppHelper::createContact(
            $connection,
            $params->email,
            $params->phone,
            $params->name,
            $params->organisation,
            $params->address1,
            $params->postcode,
            $params->city,
            $params->state,
            $params->country_code,
            $params->type,
            $params->password
        );

        // Set contact on domain
        EppHelper::setDomainContact($connection, $domain, $contactType, $contact->id);

        return ContactResult::create($contact);
    }

    /**
     * Update Contact Details
     *
     * @param string $contactId
     * @param ContactParams $params
     * @return ContactResult
     */
    protected function updateContact(string $contactId, ContactParams $params): ContactResult
    {
        try {
            // Establish the connection
            $connection = $this->connect();

            // Get Contact Data
            try {
                $contactInfo = EppHelper::getContactInfo($connection, $contactId);
            } catch (eppException $e) {
                throw $this->errorResult('Invalid registrant ID provided!', compact('contactId', 'params'), [], $e);
            }

            // Set Parameters for the update query
            $eppContactType = $params->type;

            if (!in_array($eppContactType, [self::CONTACT_LOC, self::CONTACT_INT, self::CONTACT_AUTO])) {
                $eppContactType = self::CONTACT_AUTO;
            }

            if ($params->has('name')) {
                if ($contactInfo->name == $contactInfo->organisation && !$params->has('organisation')) {
                    $name = $params->name;
                    $organisation = $params->name;
                } else {
                    $name = $params->name;
                    $organisation = $params->get('organisation', $contactInfo->organisation);
                }
            } elseif ($params->has('organisation')) {
                if ($contactInfo->name == $contactInfo->organisation) {
                    $name = $params->organisation;
                    $organisation = $params->organisation;
                } else {
                    $name = $params->get('name', $contactInfo->name);
                    $organisation = $params->organisation;
                }
            } else {
                $name = $contactInfo->name;
                $organisation = $contactInfo->organisation;
            }

            $contactUpdate = EppHelper::updateDomainContact(
                $connection,
                $contactId,
                $params->get('email', $contactInfo->email),
                $params->get('phone', $contactInfo->phone),
                $name,
                $organisation,
                $params->get('address1', $contactInfo->address1),
                $params->get('postcode', $contactInfo->postcode),
                $params->get('city', $contactInfo->city),
                $params->get('state', $contactInfo->state),
                $params->get('country_code', $contactInfo->country_code),
                $eppContactType
            );

            return ContactResult::create($contactUpdate);
        } catch (eppException $e) {
            return $this->_eppExceptionHandler($e, $params);
        }
    }

    protected function _eppExceptionHandler(eppException $exception, $data = [], $debug = []): void
    {
        if ($exception->getCode() == 2001) {
            // command syntax error - just rethrow this cause something is broken
            throw $exception;
        }

        $errorMessage = $exception->getReason() ?: $exception->getMessage();

        throw $this->errorResult($errorMessage, $data, $debug, $exception);
    }

    /**
     * Ensures the provider instance has a logged in EppConnection and returns it.
     *
     * @return \Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\EppConnection
     */
    protected function connect(): EppConnection
    {
        try {
            if (!isset($this->connection) || !$this->connection->isConnected() || !$this->connection->isLoggedin()) {
                $this->connection = EppHelper::establishConnection($this->configuration, $this->getLogger());
            }

            return $this->connection;
        } catch (eppException $e) {
            switch ($e->getCode()) {
                case 2001:
                    $errorMessage = 'Authentication error; check credentials';
                    break;
                case 2200:
                    $errorMessage = 'Authentication error; check credentials and whitelisted IPs';
                    break;
                default:
                    $errorMessage = 'Unexpected provider connection error';
            }

            throw $this->errorResult(sprintf('%s %s', $e->getCode(), $errorMessage), [], [], $e);
        }
    }

    /**
     * Logout/disconnect any current EPP connection.
     */
    protected function disconnect(): void
    {
        if (isset($this->connection) && $this->connection->isLoggedin()) {
            EppHelper::terminateConnection($this->connection);
        }
    }

    protected function api(): HexonetApi
    {
        return $this->hexonetApi ??= new HexonetApi($this->configuration, $this->getLogger());
    }
}
