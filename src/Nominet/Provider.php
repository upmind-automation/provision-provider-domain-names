<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet;

use Carbon\Carbon;
use ErrorException;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Metaregistrar\EPP\eppCheckDomainRequest;
use Metaregistrar\EPP\eppCheckRequest;
use Metaregistrar\EPP\eppContact;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppContactPostalInfo;
use Metaregistrar\EPP\eppCreateDomainRequest;
use Metaregistrar\EPP\eppCreateHostRequest;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppHost;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppPollRequest;
use Metaregistrar\EPP\eppRenewRequest;
use Metaregistrar\EPP\eppResponse;
use Metaregistrar\EPP\eppUpdateContactRequest;
use Metaregistrar\EPP\eppUpdateDomainRequest;
use Throwable;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
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
use Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension\eppCreateContactRequest as NominetEppCreateContactRequest;
use Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension\eppInfoContactResponse as NominetEppInfoContactResponse;
use Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension\eppReleaseRequest;
use Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension\NominetConnection;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Nominet\Data\NominetConfiguration;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var NominetConfiguration
     */
    protected $configuration;

    /**
     * @var \Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension\NominetConnection|null
     */
    protected $connection;

    /**
     * Common nameservers for Nominet
     */
    private const DEFAULT_NAMESERVERS = [
        ['host' => 'ns1.nominet.org.uk'],
        ['host' => 'ns2.nominet.org.uk']
    ];

    /**
     * Max positions for nameservers
     */
    private const MAX_CUSTOM_NAMESERVERS = 5;

    public function __construct(NominetConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function __destruct()
    {
        try {
            if (isset($this->connection)) {
                $this->connection->disconnect();
            }
        } catch (Throwable $e) {
            // ignore - we're probably already disconnected
        }
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Nominet')
            ->setDescription('Register, transfer, renew and manage .uk domain')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/nominet-logo@2x.png');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $connection = $this->epp();

        $since = $params->after_date ? Carbon::parse($params->after_date) : null;
        $notifications = [];
        $countRemaining = 0;

        /**
         * Start a timer because there may be 1000s of irrelevant messages and we should try and avoid a timeout.
         */
        $timeLimit = 60; // 60 seconds
        $startTime = time();

        try {
            while (count($notifications) < $params->limit && (time() - $startTime) < $timeLimit) {
                // get oldest message from queue
                /** @var \Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension\eppPollResponse $pollResponse */
                $pollResponse = $connection->request(new eppPollRequest(eppPollRequest::POLL_REQ, 0));
                $countRemaining = $pollResponse->getMessageCount();

                if ($pollResponse->getResultCode() === eppResponse::RESULT_NO_MESSAGES) {
                    break;
                }

                $messageId = $pollResponse->getMessageId();
                $type = $pollResponse->getNotificationType();
                $message = $pollResponse->getMessage() ?: 'Domain Notification';
                $domains = $pollResponse->getDomains();
                $messageDateTime = Carbon::parse($pollResponse->getMessageDate());

                // send ack request to purge this message from the queue
                $connection->request(new eppPollRequest(eppPollRequest::POLL_ACK, $messageId));

                if (is_null($type)) {
                    // this message is irrelevant
                    continue;
                }

                if (isset($since) && $messageDateTime->lessThan($since)) {
                    // this message is too old
                    continue;
                }

                $notifications[] = DomainNotification::create()
                    ->setId($messageId)
                    ->setType($type)
                    ->setMessage($message)
                    ->setDomains($domains)
                    ->setCreatedAt($messageDateTime)
                    ->setExtra(['xml' => $pollResponse->saveXML()]);
            }
        } catch (Throwable $e) {
            $data = [];

            if (isset($pollResponse)) {
                $data['last_xml'] = $pollResponse->saveXML();
            }

            $this->errorResult('Error encountered while polling for domain notifications', $data, [], $e);
        }

        return new PollResult([
            'count_remaining' => $countRemaining,
            'notifications' => $notifications,
        ]);
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            if (Arr::has($params, 'registrant.id')) {
                $contactID = $params->registrant->id;
            } else {
                $contactID = $this->_createContact(
                    $params->registrant->register->email,
                    $params->registrant->register->phone,
                    $params->registrant->register->name ?: $params->registrant->register->organisation,
                    $params->registrant->register->organisation ?: $params->registrant->register->name,
                    $params->registrant->register->address1,
                    $params->registrant->register->postcode,
                    $params->registrant->register->city,
                    $params->registrant->register->country_code,
                    'IND' // hard-code all new registrations to IND for now until we support dynamic ccTLD fields
                );
            }

            // Determine which name servers to use
            $nameServers = [];

            for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
                if (Arr::has($params, 'nameservers.ns' . $i)) {
                    $nameServers[] = Arr::get($params, 'nameservers.ns' . $i)->toArray();
                }
            }

            // Use the default name servers in case we didn't provide our own
            $nameServers = $nameServers ?: self::DEFAULT_NAMESERVERS;

            $this->_createDomain($domain, Arr::get($params, 'renew_years', 1), $contactID, $nameServers);

            return $this->_getDomain($domain, 'Domain registered successfully');
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getDomain($domain, 'Domain active in registrar account');
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->_renewDomain($domain, intval($params->renew_years));
            return $this->_getDomain($domain, 'The expire date is extended.');
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getDomain($domain);
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $nameservers = array_values(array_filter([
            $params->ns1,
            $params->ns2,
            $params->ns3,
            $params->ns4,
            $params->ns5,
        ]));

        try {
            $this->_updateDomain($domain, null, $nameservers);

            $returnNameservers = [];
            foreach ($nameservers as $i => $ns) {
                $returnNameservers['ns' . ($i + 1)] = $ns;
            }

            return NameserversResult::create($returnNameservers)
                ->setMessage('Nameservers updated successfully');
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $this->errorResult('Operation not supported for this type of domain name');
    }

    /**
     * @throws \DOMException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $domain = new eppDomain($domainName);
            $transfer = new eppReleaseRequest($domain, $params->ips_tag);

            /** @var \Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension\eppReleaseResponse $result */
            $result = $this->epp()->request($transfer);
            return $this->okResult($result->getResultMessage());
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $domainInfo = $this->_getDomain($domainName);

            $this->_updateContact(
                $domainInfo->registrant->id,
                $email = $params->contact->email,
                $phone = $params->contact->phone,
                $name = $params->contact->name ?: $params->contact->organisation,
                $organisation = $params->contact->organisation ?: $params->contact->name,
                $address1 = $params->contact->address1,
                $postcode = $params->contact->postcode,
                $city = $params->contact->city,
                $countryCode = $params->contact->country_code,
            );

            return ContactResult::create([
                'name' => $name,
                'organisation' => $organisation,
                'email' => $email,
                'phone' => $phone,
                'address1' => $address1,
                'city' => $city,
                'postcode' => $postcode,
                'country_code' => $countryCode,
            ])->setMessage('Registrant details updated');
        } catch (eppException $e) {
            $this->_eppExceptionHandler($e, $params->toArray());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * Check availability for domain names
     *
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _checkDomains(array $domains): array
    {
        $result = [];
        $check = new eppCheckDomainRequest($domains);

        /** @var \Metaregistrar\EPP\eppCheckDomainResponse $response */
        $response = $this->epp()->request($check);
        $checks = $response->getCheckedDomains();

        foreach ($domains as $domain) {
            foreach ($checks as $checkK => $check) {
                if ($domain == $check['domainname'] || strtolower($domain) == $check['domainname']) {
                    $result[] = [
                        'domain' => $check['domainname'],
                        'available' => $check['available'],
                        'reason' => $check['reason'],
                    ];

                    unset($checks[$checkK]);
                    continue 2;
                }
            }
        }

        return $result;
    }

    /**
     * Domain creation
     *
     * @param string $domainName
     * @param int $period
     * @param string $registrantID
     * @param array|null $nameservers [['host' => 'ns1.ns.com', 'ip' => '1.2.3.4', 'status' => '2']]
     * @return void
     *
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _createDomain(
        string $domainName,
        int $period,
        string $registrantID,
        ?array $nameservers
    ): void {
        $domain = new eppDomain($domainName, $registrantID);

        $domain->setRegistrant(new eppContactHandle($registrantID));
        $domain->setAuthorisationCode('not_used');
        if (is_array($nameservers) && count($nameservers) >= 1) {
            $domain = $this->setNameservers($domain, $nameservers);
        }

        $domain->setPeriod($period);
        $domain->setPeriodUnit('y');

        $create = new eppCreateDomainRequest($domain);

        $this->epp()->request($create);
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function setNameservers(
        eppDomain $domain,
        array $nameservers
    ): eppDomain {
        $hosts = [];
        foreach ($nameservers as $nameserver) {
            $hosts[] = $nameserver['host'];
        }

        $uncreatedHosts = $this->checkUncreatedHosts($hosts);

        foreach ($nameservers as $nameserver) {
            if (!empty($uncreatedHosts[$nameserver['host']])) {
                $this->createHost($nameserver['host'], $nameserver['ip'] ?? null);
            }

            $domain->addHost(new eppHost($nameserver['host']));
        }

        return $domain;
    }

    /**
     * Creates contact and returns its ID
     *
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _createContact(
        string $email,
        string $telephone,
        string $name,
        string $organization,
        string $address,
        string $postcode,
        string $city,
        string $countryCode,
        string $nominetContactType = null,
        string $tradingName = null,
        string $companyNumber = null
    ): string {
        $telephone = Utils::internationalPhoneToEpp($telephone);
        $countryCode = $this->normalizeCountryCode($countryCode);
        $postcode = $this->normalizePostCode($postcode, $countryCode);

        $postalInfo = new eppContactPostalInfo(
            $name,
            $city,
            $countryCode,
            $organization,
            $address,
            null,
            $postcode
        );
        $contactInfo = new eppContact($postalInfo, $email, $telephone);

        $contact = new NominetEppCreateContactRequest($contactInfo);
        if ($nominetContactType) {
            $contact->setNominetContactType($nominetContactType, $tradingName, $companyNumber);
        }

        /** @var \Metaregistrar\EPP\eppCreateContactResponse $response */
        $response = $this->epp()->request($contact);
        return $response->getContactId();
    }

    /**
     * Implements all changes to domain - contacts and nameservers
     *
     * @param  string  $domainName
     * @param  string|null  $registrantId
     * @param  array|null  $nameservers
     * @return string
     *
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _updateDomain(
        string $domainName,
        ?string $registrantId = null,
        ?array $nameservers = null
    ): string {
        // In the UpdateDomain command you can set or add parameters
        // - Registrant is always set (you can only have one registrant)
        // - Admin, Tech, Billing contacts are Added (you can have multiple contacts, don't forget to remove the old ones)
        // - Nameservers are Added (you can have multiple nameservers, don't forget to remove the old ones)

        // If new nameservers are given, get the old ones to remove them
        if (isset($nameservers)) {
            $info = new eppInfoDomainRequest(new eppDomain($domainName));
            /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
            $response = $this->epp()->request($info);

            if ($oldNameservers = $response->getDomainNameservers()) {
                $removeInfo = new eppDomain($domainName);
                foreach ($oldNameservers as $ns) {
                    $removeInfo->addHost(new eppHost($ns->getHostname()));
                }
            }

            $addInfo = new eppDomain($domainName);
            $addInfo = $this->setNameservers($addInfo, $nameservers);
        }

        if (isset($registrantId)) {
            $updateInfo = new eppDomain($domainName);
            $updateInfo->setRegistrant(new eppContactHandle($registrantId));
        }

        $update = new eppUpdateDomainRequest(
            new eppDomain($domainName),
            $addInfo ?? null,
            $removeInfo ?? null,
            $updateInfo ?? null
        );

        /** @var \Metaregistrar\EPP\eppUpdateDomainResponse $response */
        $response = $this->epp()->request($update);

        return $response->getResultMessage();
    }

    /**
     * @param  string  $domainName
     * @param  string  $msg
     * @return DomainResult
     *
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getDomain(
        string $domainName,
        string $msg = 'Domain info obtained'
    ): DomainResult {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain, eppInfoDomainRequest::HOSTS_ALL);

        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $this->epp()->request($info);

        $returnNs = [];
        $nameservers = $response->getDomainNameservers();
        if (isset($nameservers)) {
            foreach ($nameservers as $i => $nameserver) {
                $ips = $nameserver->getIpAddresses();
                $returnNs['ns' . ($i + 1)] = [
                    "host" => trim($nameserver->getHostname(), '.'),
                    "ip" => isset($ips) ? array_shift($ips) : null,
                ];
            }
        }

        $contact = $this->_contactInfo($response->getDomainRegistrant());

        return DomainResult::create([
            'id' => $response->getDomainId(),
            'domain' => $response->getDomainName(),
            'statuses' => $response->getDomainStatuses() ?? [], // Not in standard response
            'registrant' => [
                'id' => $response->getDomainRegistrant(),
                'name' => $contact->getContactName(),
                'email' => $contact->getContactEmail(),
                'phone' => $contact->getContactVoice(),
                'organisation' => $contact->getContactCompanyname(),
                'address1' => $contact->getContactStreet(),
                'city' => $contact->getContactCity(),
                'postcode' => $contact->getContactZipcode(),
                'country_code' => $contact->getContactCountrycode(),
                'extra' => $contact->getNominetContactData(),
            ],
            'ns' => $returnNs,
            'created_at' => $this->formatDate($response->getDomainCreateDate()),
            'updated_at' => $this->formatDate($response->getDomainUpdateDate())
                ?? $this->formatDate($response->getDomainCreateDate()),
            'expires_at' => $this->formatDate($response->getDomainExpirationDate()),
        ])->setMessage($msg);
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _contactInfo(string $contactID): NominetEppInfoContactResponse
    {
        $check = new eppInfoContactRequest(new eppContactHandle($contactID), false);

        /** @var NominetEppInfoContactResponse */
        return $this->epp()->request($check);
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _updateContact(
        string $contactID,
        string $email,
        string $telephone,
        string $name,
        string $organization,
        string $address,
        string $postcode,
        string $city,
        string $countryCode
    ): string {
        $telephone = Utils::internationalPhoneToEpp($telephone);
        $countryCode = Utils::normalizeCountryCode($countryCode);
        $postcode = $this->normalizePostCode($postcode, $countryCode);

        $updateInfo = new eppContact(
            new eppContactPostalInfo(
                $name,
                $city,
                $countryCode,
                $organization,
                $address,
                null,
                $postcode,
                eppContact::TYPE_LOC
            ),
            $email,
            $telephone
        );
        $update = new eppUpdateContactRequest(new eppContactHandle($contactID), null, null, $updateInfo);

        /** @var \Metaregistrar\EPP\eppUpdateContactResponse $response */
        $response = $this->epp()->request($update);
        return $response->getResultMessage();
    }

    protected function formatDate(?string $date): ?string
    {
        if (!isset($date)) {
            return $date;
        }

        return Carbon::parse($date)->format('Y-m-d H:i:s');
    }

    /**
     * Check which of the given hosts/nameservers are not created yet.
     *
     * @param array $hosts
     * @return bool[]|array<string,bool>|null True means host needs to be created
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function checkUncreatedHosts(array $hosts): ?array
    {
        try {
            $checkHost = [];
            foreach ($hosts as $host) {
                $checkHost[] = new eppHost($host);
            }

            $check = new eppCheckRequest($checkHost);

            /** @var \Metaregistrar\EPP\eppCheckResponse $response */
            $response = $this->epp()->request($check);

            return $response->getCheckedHosts();
        } catch (eppException $e) {
            return null;
        }
    }

    /**
     * For creation of host
     *
     * @param string $host
     * @param string|null $ip
     * @return void
     *
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function createHost(string $host, string $ip = null): void
    {
        $create = new eppCreateHostRequest(new eppHost($host, $ip));

        $this->epp()->request($create);
    }

    /**
     * Renew domain
     *
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _renewDomain(string $domainName, int $renew_years): void
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);
        $domain->setPeriodUnit('y');
        $domain->setPeriod($renew_years);

        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $this->epp()->request($info);
        $expireAt = date('Y-m-d', strtotime($response->getDomainExpirationDate()));

        $renew = new eppRenewRequest($domain, $expireAt);

        $this->epp()->request($renew);
    }

    /**
     * Normalize a given contact address post code to satisfy nominet
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
     * /
     */
    private function _eppExceptionHandler(eppException $exception, array $data = [], array $debug = []): void
    {
        if ($response = $exception->getResponse()) {
            $debug['response_xml'] = $response->saveXML();
        }

        switch ($exception->getCode()) {
            case 2001:
                $errorMessage = 'Invalid request data';
                break;
            default:
                $errorMessage = $exception->getMessage();
        }

        $this->errorResult(sprintf('Registry Error: %s', $errorMessage), $data, $debug, $exception);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function epp(): NominetConnection
    {
        if (isset($this->connection)) {
            return $this->connection;
        }

        try {
            $connection = new NominetConnection(true);
            $connection->setPsrLogger($this->getLogger());

            $connection->setHostname(
                $this->configuration->sandbox
                    ? 'ssl://ote-epp.nominet.org.uk'
                    : 'ssl://epp.nominet.org.uk'
            );
            $connection->setPort(700);
            $connection->setUsername(
                strlen($this->configuration->ips_tag) === 2
                    ? sprintf('#%s', $this->configuration->ips_tag)
                    : $this->configuration->ips_tag
            );
            $connection->setPassword($this->configuration->password);

            // connect and authenticate
            $connection->login();

            return $this->connection = $connection;
        } catch (eppException $e) {
            switch ($e->getCode()) {
                case 2001:
                    $errorMessage = 'Authentication error; check credentials';
                    break;
                case 2200:
                    $errorMessage = 'Authentication error; check credentials and whitelisted IPs';
                    break;
                default:
                    $errorMessage = 'Connection error; check whitelisted IPs';
            }

            $this->errorResult(trim(sprintf('%s %s', $e->getCode() ?: null, $errorMessage)), [], [], $e);
        } catch (ErrorException $e) {
            if (Str::containsAll($e->getMessage(), ['stream_socket_client()', 'SSL'])) {
                // this usually means they've not whitelisted our IPs
                $errorMessage = 'Connection error; check whitelisted IPs';
            } else {
                $errorMessage = 'Unexpected provider connection error';
            }

            $this->errorResult($errorMessage, [], [], $e);
        }
    }
}
