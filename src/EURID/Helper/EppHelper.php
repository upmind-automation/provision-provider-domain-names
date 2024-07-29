<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EURID\Helper;

use Carbon\Carbon;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Metaregistrar\EPP\authEppInfoDomainRequest;
use Metaregistrar\EPP\eppCheckDomainRequest;
use Metaregistrar\EPP\eppPollRequest;
use Metaregistrar\EPP\eppContact;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppContactPostalInfo;
use Metaregistrar\EPP\eppCreateDomainRequest;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppHost;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppRenewRequest;
use Metaregistrar\EPP\eppTransferRequest;
use Metaregistrar\EPP\eppTransferResponse;
use Metaregistrar\EPP\eppUpdateContactRequest;
use Metaregistrar\EPP\eppUpdateDomainRequest;
use Metaregistrar\EPP\euridEppContact;
use Metaregistrar\EPP\euridEppCreateContactRequest;
use Metaregistrar\EPP\euridEppPollRequest;
use Metaregistrar\EPP\euridEppPollResponse;
use Upmind\ProvisionProviders\DomainNames\EURID\EppExtension\EppConnection;
use Upmind\ProvisionProviders\DomainNames\EURID\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\EURID\EppExtension\euridEppTransferDomainRequest;

class EppHelper
{
    protected EppConnection $connection;
    protected Configuration $configuration;
    private array $lockedStatuses = [
        'serverTransferProhibited',
        'serverUpdateProhibited',
    ];

    public function __construct(EppConnection $connection, Configuration $configuration)
    {
        $this->connection = $connection;
        $this->configuration = $configuration;
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
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
            /** @var \Metaregistrar\EPP\euridEppPollResponse $pollResponse */
            $pollResponse = $this->connection->request(new euridEppPollRequest(eppPollRequest::POLL_REQ, 0));
            $countRemaining = $pollResponse->getMessageCount();

            if ($countRemaining == 0) {
                break;
            }

            $messageId = $pollResponse->getMessageId();
            $context = $pollResponse->getContext();
            $action = $pollResponse->getAction();
            $objectType = $pollResponse->getObjectType();

            $message = $pollResponse->getMessage() ?: 'Domain Notification';
            $domain = $pollResponse->getObject();
            $messageDateTime = Carbon::parse($pollResponse->getMessageDate());

            $this->connection->request(new eppPollRequest(eppPollRequest::POLL_ACK, $messageId));

            if ($objectType !== euridEppPollResponse::TYPE_DOMAIN && $context !== 'TRANSFER') {
                // this message is irrelevant
                continue;
            }

            if (isset($since) && $messageDateTime->lessThan($since)) {
                // this message is too old
                continue;
            }

            $notifications[] = DomainNotification::create()
                ->setId($messageId)
                ->setType($action === 'AWAY'
                    ? DomainNotification::TYPE_TRANSFER_OUT
                    : DomainNotification::TYPE_TRANSFER_IN)
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

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function checkMultipleDomains(array $domains): array
    {
        $check = new eppCheckDomainRequest($domains);

        /** @var \Metaregistrar\EPP\eppCheckDomainResponse $response */
        $response = $this->connection->request($check);

        $checks = $response->getCheckedDomains();

        $result = [];

        foreach ($checks as $check) {
            $available = $check['available'] == 1;

            $premium = false;

            $description = sprintf(
                'Domain is %s to register. %s',
                $available ? 'available' : 'not available',
                $check['reason'],
            );

            $canTransfer = !$available;
            if (!$available && strtolower($check['reason']) === 'invalid domain name') {
                $canTransfer = false;
            }

            $result[] = DacDomain::create([
                'domain' => $check['domainname'],
                'description' => $description,
                'tld' => Utils::getTld($check['domainname']),
                'can_register' => $available,
                'can_transfer' => $canTransfer,
                'is_premium' => $premium,
            ]);
        }

        return $result;
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function getDomainInfo(string $domainName): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var \Metaregistrar\EPP\euridEppInfoDomainResponse $response */
        $response = $this->connection->request($info);

        $registrantId = $response->getDomainRegistrant();
        $billingId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_BILLING);
        $techId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_TECH);
        $adminId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_ADMIN);

        $statuses = $response->getDomainStatuses() ?? [];

        if ($response->getQuarantined()) {
            $statuses[] = 'quarantined';
        }

        if ($response->getOnHold()) {
            $statuses[] = 'on hold';
        }

        if ($response->getSuspended()) {
            $statuses[] = 'suspended';
        }

        if ($response->getSeized()) {
            $statuses[] = 'seized';
        }

        return [
            'id' => $response->getDomainId(),
            'domain' => $response->getDomainName(),
            'statuses' => $statuses,
            'locked' => boolval(array_intersect($this->lockedStatuses, $response->getDomainStatuses() ?? [])),
            'registrant' => $registrantId ? $this->getContactInfo($registrantId) : null,
            'billing' => $billingId ? $this->getContactInfo($billingId) : null,
            'tech' => $techId ? $this->getContactInfo($techId) : null,
            'admin' => $adminId ? $this->getContactInfo($adminId) : null,
            'ns' => $this->parseNameServers($response->getDomainNameservers() ?? []),
            'created_at' => Utils::formatDate($response->getDomainCreateDate()),
            'updated_at' => Utils::formatDate($response->getDomainUpdateDate() ?: $response->getDomainCreateDate()),
            'expires_at' => Utils::formatDate($response->getDomainExpirationDate()),
        ];
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function getContactInfo(string $contactId): ContactData
    {
        $request = new eppInfoContactRequest(new eppContactHandle($contactId), false);

        /** @var \Metaregistrar\EPP\eppInfoContactResponse $response */
        $response = $this->connection->request($request);

        return ContactData::create([
            'id' => $contactId,
            'name' => $response->getContactName(),
            'email' => $response->getContactEmail(),
            'phone' => $response->getContactVoice(),
            'organisation' => $response->getContactCompanyname(),
            'address1' => $response->getContactStreet(),
            'city' => $response->getContactCity(),
            'state' => $response->getContactProvince(),
            'postcode' => $response->getContactZipcode(),
            'country_code' => $response->getContactCountrycode(),
        ]);
    }

    private function parseNameServers(array $nameServers): array
    {
        $result = [];

        if (count($nameServers) > 0) {
            foreach ($nameServers as $i => $ns) {
                $result['ns' . ($i + 1)] = [
                    'host' => $ns->getHostName(),
                    'ip' => $ns->getIpAddresses()
                ];
            }
        }

        return $result;
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function renew(string $domainName, int $period): void
    {
        $domainData = new eppDomain($domainName);
        $domainData->setPeriod($period);
        $domainData->setPeriodUnit('y');

        $info = new eppInfoDomainRequest($domainData);
        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $this->connection->request($info);

        $expiresAt = Utils::formatDate($response->getDomainExpirationDate(), 'Y-m-d');

        $renewRequest = new eppRenewRequest($domainData, $expiresAt);

        $this->connection->request($renewRequest);
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function getEppCode(string $domainName, bool $updateCode = false): ?string
    {
        $domain = new eppDomain($domainName);
        $info = new authEppInfoDomainRequest($domain, null, $updateCode);

        /** @var \Metaregistrar\EPP\authEppInfoDomainResponse $response */
        $response = $this->connection->request($info);

        return $response->getDomainAuthInfo();
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function updateNameservers(string $domainName, array $nameservers): void
    {
        $attachedHosts = $this->getHosts($domainName);

        if (array_diff($attachedHosts, $nameservers) == array_diff($nameservers, $attachedHosts)) {
            return;
        }

        if ($attachedHosts) {
            $removeInfo = new eppDomain($domainName);

            foreach ($attachedHosts as $ns) {
                if (in_array($ns, $nameservers)) {
                    continue;
                }

                $removeInfo->addHost(new eppHost($ns));
            }
        }

        $addInfo = new eppDomain($domainName);

        foreach ($nameservers as $nameserver) {
            if (!in_array($nameserver, $attachedHosts)) {
                $addInfo->addHost(new eppHost($nameserver));
            }
        }

        $update = new eppUpdateDomainRequest(
            new eppDomain($domainName),
            $addInfo,
            $removeInfo ?? null,
            null,
            true
        );

        $this->connection->request($update);
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function getHosts(string $domainName): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $this->connection->request($info);

        $nameservers = $response->getDomainNameservers();

        $attachedHosts = [];
        if ($nameservers) {
            foreach ($nameservers as $ns) {
                $attachedHosts[] = $ns->getHostname();
            }
        }

        return $attachedHosts;
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function updateRegistrantContact(string $domainName, ContactParams $params): ContactData
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var \Metaregistrar\EPP\eppInfoDomainResponse $response */
        $response = $this->connection->request($info);
        $registrantId = $response->getDomainRegistrant();

        $contact = new eppContactHandle($registrantId);

        $update = $this->setUpdateContactParams($params);

        $up = new eppUpdateContactRequest($contact, null, null, $update);

        $this->connection->request($up);

        return $this->getContactInfo($registrantId);
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    private function setUpdateContactParams(ContactParams $params): eppContact
    {
        $telephone = null;
        if ($params->phone) {
            $telephone = Utils::internationalPhoneToEpp($params->phone);
        }

        $countryCode = null;
        if ($params->country_code) {
            $countryCode = Utils::normalizeCountryCode($params->country_code);
        }

        $name = $params->name ?: $params->organisation;
        @[$firstName, $lastName] = explode(' ', $name, 2);

        $name = $firstName;

        if (!empty($lastName)) {
            $name .= ' ' . $lastName;
        }

        $postalInfo = new eppContactPostalInfo(
            $name,
            $params->city,
            $countryCode,
            $params->organisation,
            $params->address1,
            $params->state,
            $params->postcode,
            eppContact::TYPE_LOC,
        );
        return new eppContact($postalInfo, $params->email, $telephone);
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function register(
        string $domainName,
        int    $period,
        array  $nameServers,
        array  $contacts
    ): array {
        $domain = new eppDomain($domainName, $contacts['registrant'], [
            new eppContactHandle($contacts['tech'], eppContactHandle::CONTACT_TYPE_TECH),
            new eppContactHandle($contacts['billing'], eppContactHandle::CONTACT_TYPE_BILLING)
        ]);

        $domain->setRegistrant(new eppContactHandle($contacts['registrant']));

        foreach ($nameServers as $nameserver) {
            $domain->addHost(new eppHost($nameserver));
        }

        // Set Domain Period
        $domain->setPeriod($period);
        $domain->setPeriodUnit('y');

        $domain->setAuthorisationCode(self::generateValidAuthCode());

        // Create the domain
        $create = new eppCreateDomainRequest($domain, true);

        /** @var \Metaregistrar\EPP\eppCreateDomainResponse $response */
        $response = $this->connection->request($create);

        return [
            'domain' => $response->getDomainName(),
            'created_at' => Utils::formatDate($response->getDomainCreateDate()),
            'expires_at' => Utils::formatDate($response->getDomainExpirationDate())
        ];
    }

    private static function generateValidAuthCode(int $length = 16): string
    {
        return Helper::generateStrictPassword($length, true, true, true, '!@#$%^*_');
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    public function createContact(ContactParams $params, string $type): string
    {
        $telephone = null;
        if ($params->phone) {
            $telephone = Utils::internationalPhoneToEpp($params->phone);
        }

        $countryCode = null;
        if ($params->country_code) {
            $countryCode = Utils::normalizeCountryCode($params->country_code);
        }

        $postalInfo = new eppContactPostalInfo(
            $params->name ?: $params->organisation,
            $params->city,
            $countryCode,
            $params->organisation,
            $params->address1,
            $params->state,
            $params->postcode,
            eppContact::TYPE_LOC,
        );

        $contactInfo = new euridEppContact($postalInfo, $params->email, $telephone);

        $contactInfo->setContactExtType($type);

        $contact = new euridEppCreateContactRequest($contactInfo);

        /** @var \Metaregistrar\EPP\eppCreateContactResponse $response */
        $response = $this->connection->request($contact);

        return $response->getContactId();
    }

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function initiateTransfer(
        string $domainName,
        int $renewYears,
        ?string $eppCode,
        array $contacts
    ): eppTransferResponse {
        $contactHandle = [];

        if ($contacts['tech']) {
            $contactHandle[] = new eppContactHandle($contacts['tech'], eppContactHandle::CONTACT_TYPE_TECH);
        }

        if ($contacts['billing']) {
            $contactHandle[] = new eppContactHandle($contacts['billing'], eppContactHandle::CONTACT_TYPE_BILLING);
        }

        $domain = new eppDomain($domainName, $contacts['registrant'], $contactHandle);

        // Set EPP Code
        $domain->setAuthorisationCode($eppCode ?? '1234');

        $domain->setPeriod($renewYears);
        $domain->setPeriodUnit('y');

        $transferRequest = new euridEppTransferDomainRequest(eppTransferRequest::OPERATION_REQUEST, $domain);

        // Process Response
        /** @var eppTransferResponse */
        return $this->connection->request($transferRequest);
    }
}
