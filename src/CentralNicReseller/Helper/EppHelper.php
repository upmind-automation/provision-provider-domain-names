<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Helper;
use Metaregistrar\EPP\rrpproxyEppRenewalmodeRequest;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Metaregistrar\EPP\eppCheckDomainRequest;
use Metaregistrar\EPP\eppCheckDomainResponse;
use Metaregistrar\EPP\eppCheckHostResponse;
use Metaregistrar\EPP\eppCheckHostRequest;
use Metaregistrar\EPP\eppCheckRequest;
use Metaregistrar\EPP\eppPollResponse;
use Metaregistrar\EPP\eppPollRequest;
use Metaregistrar\EPP\eppContact;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppContactPostalInfo;
use Metaregistrar\EPP\eppCreateContactRequest;
use Metaregistrar\EPP\eppCreateContactResponse;
use Metaregistrar\EPP\eppCreateDomainRequest;
use Metaregistrar\EPP\eppCreateDomainResponse;
use Metaregistrar\EPP\eppCreateHostRequest;
use Metaregistrar\EPP\eppCreateResponse;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppHost;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppInfoContactResponse;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppInfoDomainResponse;
use Metaregistrar\EPP\eppInfoHostRequest;
use Metaregistrar\EPP\eppInfoHostResponse;
use Metaregistrar\EPP\eppRenewRequest;
use Metaregistrar\EPP\eppRenewResponse;
use Metaregistrar\EPP\eppTransferRequest;
use Metaregistrar\EPP\eppTransferResponse;
use Metaregistrar\EPP\eppUpdateContactRequest;
use Metaregistrar\EPP\eppUpdateContactResponse;
use Metaregistrar\EPP\eppUpdateDomainRequest;
use Metaregistrar\EPP\eppUpdateDomainResponse;
use Metaregistrar\EPP\eppUpdateResponse;
use Metaregistrar\EPP\eppResponse;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\EppExtension\EppConnection;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;

/**
 * Class EppHelper
 *
 * @package Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper
 */
class EppHelper
{
    protected EppConnection $connection;
    protected Configuration $configuration;

    protected const CONTACT_LOC = 'loc';
    protected const CONTACT_INT = 'int';
    protected const CONTACT_AUTO = 'auto';

    protected array $lockedStatuses = [
        'clientTransferProhibited',
        'clientUpdateProhibited',
    ];

    public function __construct(EppConnection $connection, Configuration $configuration)
    {
        $this->connection = $connection;
        $this->configuration = $configuration;
    }

    public function poll(int $limit, ?Carbon $since): array
    {
        $notifications = [];
        $countRemaining = 0;

        /**
         * Start a timer because there may be 1000s of irrelevant messages and we should try and avoid a timeout.
         */
        $timeLimit = 60; // 60 seconds
        $startTime = time();

        while (count($notifications) < $limit && (time() - $startTime) < $timeLimit) {
            // get oldest message from queue
            /** @var eppPollResponse $pollResponse */
            $pollResponse = $this->connection->request(new eppPollRequest(eppPollRequest::POLL_REQ, 0));
            $countRemaining = $pollResponse->getMessageCount();

            if ($countRemaining == 0) {
                break;
            }

            $messageId = $pollResponse->getMessageId();
            $type = $pollResponse->getMessageType();
            $message = $pollResponse->getMessage() ?: 'Domain Notification';
            $domain = $pollResponse->getDomainName();
            $messageDateTime = Carbon::parse($pollResponse->getMessageDate());

            $this->connection->request(new eppPollRequest(eppPollRequest::POLL_ACK, $messageId));

            if ($type != eppPollResponse::TYPE_TRANSFER) {
                // this message is irrelevant
                continue;
            }

            if (isset($since) && $messageDateTime->lessThan($since)) {
                // this message is too old
                continue;
            }

            $notifications[] = DomainNotification::create()
                ->setId($messageId)
                ->setType(DomainNotification::TYPE_TRANSFER_IN)
                ->setMessage($message)
                ->setDomains([$domain])
                ->setCreatedAt($messageDateTime)
                ->setExtra(['xml' => $pollResponse->saveXML()]);
        }

        return [
            'count_remaining' => $countRemaining,
            'notifications' => $notifications,
        ];
    }

    public function checkMultipleDomains(array $domains): array
    {
        $check = new eppCheckDomainRequest($domains);

        /** @var eppCheckDomainResponse */
        $response = $this->connection->request($check);

        $checks = $response->getCheckedDomains();

        $result = [];

        foreach ($checks as $check) {
            $canRegister = (bool)$check['available'] == "true";
            $canTransfer = !$canRegister;

            if (!$canRegister && isset($check['reason']) && Str::startsWith($check['reason'], 'Error:')) {
                $canTransfer = false;
            }

            $result[] = DacDomain::create([
                'domain' => $check['domainname'],
                'description' => $check['reason'] ?? sprintf(
                    'Domain is %s to register',
                    $canRegister ? 'available' : 'not available',
                ),
                'tld' => Utils::getTld($check['domainname']),
                'can_register' => $canRegister,
                'can_transfer' => $canTransfer,
                'is_premium' => false,
            ]);
        }

        return $result;
    }

    /**
     * @param string[] $contactIds
     * @param Nameserver[] $nameServers
     */
    public function register(
        string $domainName,
        int $period,
        array $contactIds,
        array $nameServers
    ): array {
        $domain = new eppDomain($domainName, $contactIds[eppContactHandle::CONTACT_TYPE_REGISTRANT], [
            new eppContactHandle($contactIds[eppContactHandle::CONTACT_TYPE_ADMIN], eppContactHandle::CONTACT_TYPE_ADMIN),
            new eppContactHandle($contactIds[eppContactHandle::CONTACT_TYPE_TECH], eppContactHandle::CONTACT_TYPE_TECH),
            new eppContactHandle($contactIds[eppContactHandle::CONTACT_TYPE_BILLING], eppContactHandle::CONTACT_TYPE_BILLING)
        ]);

        $domain->setRegistrant(new eppContactHandle($contactIds[eppContactHandle::CONTACT_TYPE_REGISTRANT]));
        $domain->setAuthorisationCode(self::generateValidAuthCode());

        $domain = $this->addNameServers($nameServers, $domain);

        // Set Domain Period
        $domain->setPeriod($period);
        $domain->setPeriodUnit('y');

        // Create the domain
        $create = new eppCreateDomainRequest($domain);

        /** @var eppCreateDomainResponse */
        $response = $this->connection->request($create);

        return [
            'domain' => $response->getDomainName(),
            'created_at' => Utils::formatDate($response->getDomainCreateDate()),
            'expires_at' => Utils::formatDate($response->getDomainExpirationDate())
        ];
    }

    public function getDomainInfo(string $domainName): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse */
        $response = $this->connection->request($info);

        $registrantId = $response->getDomainRegistrant();
        $billingId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_BILLING);
        $techId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_TECH);
        $adminId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_ADMIN);

        return [
            'id' => $response->getDomainId(),
            'domain' => $response->getDomainName(),
            'statuses' => $response->getDomainStatuses() ?? [],
            'locked' => boolval(array_intersect($this->lockedStatuses, $response->getDomainStatuses() ?? [])),
            'registrant' => $registrantId ? $this->getContactInfo($registrantId) ?? $registrantId : null,
            'billing' => $billingId ? $this->getContactInfo($billingId) ?? $billingId : null,
            'tech' => $techId ? $this->getContactInfo($techId) ?? $techId : null,
            'admin' => $adminId ? $this->getContactInfo($adminId) ?? $adminId : null,
            'ns' => $this->parseNameServers($response->getDomainNameservers() ?? []),
            'created_at' => Utils::formatDate($response->getDomainCreateDate()),
            'updated_at' => Utils::formatDate($response->getDomainUpdateDate() ?: $response->getDomainCreateDate()),
            'expires_at' => Utils::formatDate($response->getDomainExpirationDate()),
        ];
    }

    public function initiateTransfer(string $domainName, ?string $eppCode, int $renewYears): eppTransferResponse
    {
        $domain = new eppDomain($domainName);

        // Set EPP Code
        if ($eppCode != null) {
            $domain->setAuthorisationCode($eppCode);
        }

        $domain->setPeriod($renewYears);
        $domain->setPeriodUnit('y');

        $transferRequest = new eppTransferRequest(eppTransferRequest::OPERATION_REQUEST, $domain);

        // Process Response
        /** @var eppTransferResponse */
        return $this->connection->request($transferRequest);
    }

    public function getTransferInfo(string $domainName): string
    {
        $domain = new eppDomain($domainName);

        $transferRequest = new eppTransferRequest(eppTransferRequest::OPERATION_QUERY, $domain);

        /** @var eppTransferResponse */
        $response = $this->connection->request($transferRequest);

        return (string)$response->getTransferStatus();
    }

    public function renew(string $domainName, int $period): void
    {
        $domainData = new eppDomain($domainName);
        $domainData->setPeriod($period);
        $domainData->setPeriodUnit('y');

        $info = new eppInfoDomainRequest($domainData);
        /** @var eppInfoDomainResponse $response */
        $response = $this->connection->request($info);

        $expiresAt = Utils::formatDate($response->getDomainExpirationDate(), 'Y-m-d');

        $renewRequest = new eppRenewRequest($domainData, $expiresAt);

        $this->connection->request($renewRequest);
    }

    public function getContactInfo(string $contactId): ?ContactData
    {
        $request = new eppInfoContactRequest(new eppContactHandle($contactId), false);

        try {
            /** @var eppInfoContactResponse */
            $response = $this->connection->request($request);
        } catch (eppException $e) {
            if($e->getCode() === 2303) {
                return null;
            }
        }

        return ContactData::create([
            'id' => $contactId,
            'name' => $response->getContactName() ?: null,
            'email' => $response->getContactEmail(),
            'phone' => $response->getContactVoice(),
            'organisation' => $response->getContactCompanyname() ?: null,
            'address1' => $response->getContactStreet(),
            'city' => $response->getContactCity(),
            'state' => $response->getContactProvince(),
            'postcode' => $response->getContactZipcode(),
            'country_code' => $response->getContactCountrycode(),
            'type' => $response->getContact()->getType(),
        ]);
    }

    private function parseNameServers(array $nameServers): array
    {
        $result = [];

        if (count($nameServers) > 0) {
            foreach ($nameServers as $i => $ns) {
                // $ips = $this->getHostAddresses($ns->getHostName());

                $result['ns' . ($i + 1)] = [
                    'host' => strtolower($ns->getHostName()),
                    // 'ip' => count($ips) ? $ips[0] : ''
                ];
            }
        }

        return $result;
    }

    private function getHostAddresses(string $hostName): array
    {
        $host = new eppHost($hostName);
        $info = new eppInfoHostRequest($host);
        /** @var eppInfoHostResponse */
        try {
            $response = $this->connection->request($info);
            return array_keys($response->getHostAddresses());
        } catch (eppException $e) {
            if ($e->getCode() == 2303) {
                return [];
            }

            throw $e;
        }
    }

    public function setRegistrarLock(string $domainName, array $addStatuses, array $removeStatuses): void
    {
        if (count($addStatuses)) {
            $add = new eppDomain($domainName);

            foreach ($addStatuses as $status) {
                $add->addStatus($status);
            }
        }

        if (count($removeStatuses)) {
            $del = new eppDomain($domainName);

            foreach ($removeStatuses as $status) {
                $del->addStatus($status);
            }
        }

        $domain = new eppDomain($domainName);
        $update = new eppUpdateDomainRequest($domain, $add ?? null, $del ?? null);

        $this->connection->request($update);
    }

    public function getRegistrarLockStatuses(string $domainName): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse */
        $response = $this->connection->request($info);

        return $response->getDomainStatuses();
    }

    public function getLockedStatuses(): array
    {
        return $this->lockedStatuses;
    }

    public function setRenewalMode(string $domainName, bool $autoRenew): void
    {
        $renewalMode = ($autoRenew) ? rrpproxyEppRenewalmodeRequest::RRP_RENEWALMODE_AUTORENEW : rrpproxyEppRenewalmodeRequest::RRP_RENEWALMODE_AUTOEXPIRE;
        $domain = new eppDomain($domainName);
        $renew = new rrpproxyEppRenewalmodeRequest($domain, $renewalMode);

        $this->connection->request($renew);
    }

    public function getDomainEppCode(string $domainName): string
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse $response */
        $response = $this->connection->request($info);

        return $response->getDomainAuthInfo();
    }

    public function updateRegistrantContact(string $domainName, ContactParams $params): ContactData
    {
        $contactID = $this->createContact($params);

        $mod = new eppDomain($domainName);
        $mod->setRegistrant(new eppContactHandle($contactID));

        $update = new eppUpdateDomainRequest(
            new eppDomain($domainName),
            null,
            null,
            $mod
        );

        /** @var eppUpdateDomainResponse $response */
        $this->connection->request($update);

        return $this->getContactInfo($contactID);
    }

    public function createContact(ContactParams $params): string
    {
        $telephone = null;
        if ($params->phone) {
            $telephone = Utils::internationalPhoneToEpp($params->phone);
        }

        $countryCode = null;
        if ($params->country_code) {
            $countryCode = Utils::normalizeCountryCode($params->country_code);
        }

        $eppContactType = $params->type;
        if (!in_array($eppContactType, [self::CONTACT_LOC, self::CONTACT_INT, self::CONTACT_AUTO])) {
            $eppContactType = self::CONTACT_AUTO;
        }

        $name = $params->name ?: $params->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);
        $lastName ??= $firstName;
        $name = $firstName . ' ' . $lastName;

        $postalInfo = new eppContactPostalInfo(
            $name,
            $params->city,
            $countryCode,
            $params->organisation,
            $params->address1,
            $params->state,
            $params->postcode
        );

        $contactInfo = new eppContact($postalInfo, $params->email, $telephone);

        $contactInfo->setType($eppContactType);
        $contactInfo->setPassword($params->password ?? self::generateValidAuthCode());

        $contact = new eppCreateContactRequest($contactInfo);

        /** @var eppCreateContactResponse $response */
        $response = $this->connection->request($contact);

        return $response->getContactId();
    }

    private static function generateValidAuthCode(int $length = 16): string
    {
        return Helper::generateStrictPassword($length, true, true, true, '!@#$%^*_');
    }

    public function updateNameServers(
        string $domainName,
        array  $nameservers
    ): string {
        // If new nameservers are given, get the old ones to remove them
        $hosts = [];
        foreach ($nameservers as $nameserver) {
            $hosts[] = strtolower($nameserver['host']);
        }

        $oldNameservers = $this->getHosts($domainName);
        if ($oldNameservers) {
            $removeInfo = new eppDomain($domainName);

            foreach ($oldNameservers as $ns) {
                $oldNs = strtolower($ns->getHostname());

                if (in_array($oldNs, $hosts)) {
                    foreach ($nameservers as $i => $nameserver) {
                        if ($nameserver['host'] == $oldNs) {
                            unset($nameservers[$i]);
                        }
                    }
                    continue;
                }

                $removeInfo->addHost(new eppHost($oldNs));
            }
        }

        if (!count($nameservers) && !isset($removeInfo)) {
            return "";
        }

        if ($nameservers) {
            $addInfo = new eppDomain($domainName);
            $addInfo = $this->addNameServers($nameservers, $addInfo);
        }

        $update = new eppUpdateDomainRequest(
            new eppDomain($domainName),
            $addInfo ?? null,
            $removeInfo ?? null,
            $updateInfo ?? null
        );

        /** @var eppUpdateDomainResponse $response */
        $response = $this->connection->request($update);

        return $response->getResultMessage();
    }

    public function getHosts(string $domainName): ?array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse */
        $response = $this->connection->request($info);

        return $response->getDomainNameservers();
    }

    /**
     * @param Nameserver[] $nameservers
     */
    private function addNameServers(array $nameservers, eppDomain $domain): eppDomain
    {
        $uncreatedHosts = $this->checkUncreatedHosts($nameservers);

        foreach ($nameservers as $nameserver) {
            if (!empty($uncreatedHosts) && in_array(strtolower($nameserver->host), $uncreatedHosts)) {
                $this->createHost($nameserver->host, $nameserver->ip ?? Utils::lookupIpAddress($nameserver->host));
            }

            $domain->addHost(new eppHost($nameserver->host));
        }

        return $domain;
    }

    private function createHost(string $host, string $ip = null): void
    {
        $create = new eppCreateHostRequest(new eppHost($host, $ip));

        /** @var eppCreateHostResponse */
        $this->connection->request($create);
    }

    private function checkUncreatedHosts(array $nameservers): ?array
    {
        $hosts = [];
        foreach ($nameservers as $nameserver) {
            $hosts[] = $nameserver['host'];
        }

        $checkHost = [];
        foreach ($hosts as $host) {
            $checkHost[] = new eppHost($host);
        }

        $check = new eppCheckHostRequest($checkHost);

        /** @var eppCheckHostResponse $response */
        $response = $this->connection->request($check);

        $uncreatedHosts = null;

        foreach ($response->getCheckedHosts() as $ns => $val) {
            if ($val == 1) {
                $uncreatedHosts[] = strtolower($ns);
            }
        }

        return $uncreatedHosts;
    }
}
