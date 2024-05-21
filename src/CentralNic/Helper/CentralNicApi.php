<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNic\Helper;

use Carbon\Carbon;
use Upmind\ProvisionBase\Helper;
use Metaregistrar\EPP\eppCheckDomainRequest;
use Metaregistrar\EPP\eppCheckDomainResponse;
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
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppHost;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppInfoContactResponse;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppInfoDomainResponse;
use Metaregistrar\EPP\eppRenewRequest;
use Metaregistrar\EPP\eppTransferRequest;
use Metaregistrar\EPP\eppTransferResponse;
use Metaregistrar\EPP\eppUpdateDomainRequest;
use Metaregistrar\EPP\eppUpdateDomainResponse;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\CentralNic\EppExtension\EppConnection;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\CentralNic\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;

/**
 * Class CentralNicHelper
 *
 * @package Upmind\ProvisionProviders\DomainNames\CentralNic\Helper
 */
class CentralNicApi
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

    public function getLockedStatuses(): array
    {
        return $this->lockedStatuses;
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
            $available = (bool)$check['available'] == "true";

            $premium = false;
            if (!$available && $check['reason'] == 'premium') {
                $premium = true;
            }

            $description = sprintf(
                'Domain is %s to register. %s',
                $available ? 'available' : 'not available',
                $check['reason'],
            );

            $canTransfer = !$available;

            if ($check['reason']) {
                if (preg_match('/suffix .* does not exist/i', $check['reason'])) {
                    $description = "This TLD is unavailable for new registrations";
                    $canTransfer = false;
                }
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

    public function register(
        string $domainName,
        int    $period,
        array  $contacts,
        array  $nameServers
    ): array {
        $domain = new eppDomain($domainName, $contacts[eppContactHandle::CONTACT_TYPE_REGISTRANT], [
            new eppContactHandle($contacts[eppContactHandle::CONTACT_TYPE_ADMIN], eppContactHandle::CONTACT_TYPE_ADMIN),
            new eppContactHandle($contacts[eppContactHandle::CONTACT_TYPE_TECH], eppContactHandle::CONTACT_TYPE_TECH),
            new eppContactHandle($contacts[eppContactHandle::CONTACT_TYPE_BILLING], eppContactHandle::CONTACT_TYPE_BILLING)
        ]);

        $domain->setRegistrant(new eppContactHandle($contacts[eppContactHandle::CONTACT_TYPE_REGISTRANT]));

        $domain->setAuthorisationCode(self::generateValidAuthCode());

        $domain = $this->addNameServers($nameServers, $domain);

        // Set Domain Period
        $domain->setPeriod($period);
        $domain->setPeriodUnit('y');

        // Create the domain
        $create = new eppCreateDomainRequest($domain);

        /** @var eppCreateDomainResponse $response */
        $response = $this->connection->request($create);

        return [
            'domain' => $response->getDomainName(),
            'created_at' => Utils::formatDate($response->getDomainCreateDate()),
            'expires_at' => Utils::formatDate($response->getDomainExpirationDate())
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

    /**
     * @throws \Metaregistrar\EPP\eppException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainInfo(string $domainName, bool $checkRegistrar = true): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse */
        $response = $this->connection->request($info);

        if ($checkRegistrar && $response->getDomainClientId() !== $this->configuration->registrar_handle_id) {
            throw new ProvisionFunctionError('Domain not owned by registrar account');
        }

        $registrantId = $response->getDomainRegistrant();
        $billingId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_BILLING);
        $techId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_TECH);
        $adminId = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_ADMIN);

        return [
            'id' => $response->getDomainId(),
            'domain' => $response->getDomainName(),
            'statuses' => $response->getDomainStatuses() ?? [],
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

        $this->connection->request($update);

        return $this->getContactInfo($contactID);
    }

    public function updateEppCode(string $domainName): string
    {
        $code = self::generateValidAuthCode();

        $mod = new eppDomain($domainName);
        $mod->setAuthorisationCode($code);

        $update = new eppUpdateDomainRequest(
            new eppDomain($domainName),
            null,
            null,
            $mod
        );

        $this->connection->request($update);

        return $code;
    }

    public function updateNameServers(
        string $domainName,
        array  $nameservers
    ): string {
        // If new nameservers are given, get the old ones to remove them
        $hosts = [];
        foreach ($nameservers as $nameserver) {
            $hosts[] = $nameserver['host'];
        }

        $oldNameservers = $this->getHosts($domainName);
        if ($oldNameservers) {
            $removeInfo = new eppDomain($domainName);

            foreach ($oldNameservers as $ns) {
                if (in_array($ns->getHostname(), $hosts)) {
                    continue;
                }

                $removeInfo->addHost(new eppHost($ns->getHostname()));
            }
        }

        $addInfo = new eppDomain($domainName);
        $addInfo = $this->addNameServers($nameservers, $addInfo);

        $update = new eppUpdateDomainRequest(
            new eppDomain($domainName),
            $addInfo,
            $removeInfo ?? null,
            null
        );

        /** @var eppUpdateDomainResponse $response */
        $response = $this->connection->request($update);

        return $response->getResultMessage();
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

    public function getContactInfo(string $contactId): ContactData
    {
        $request = new eppInfoContactRequest(new eppContactHandle($contactId), false);
        try {
            /** @var eppInfoContactResponse $response */
            $response = $this->connection->request($request);
        } catch (eppException $e) {
            return ContactData::create(['id' => $contactId]);
        }

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
            'type' => $response->getContact()->getType(),
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

    public function getRegistrarLockStatuses(string $domainName): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse */
        $response = $this->connection->request($info);

        return $response->getDomainStatuses();
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
     * @throws \Metaregistrar\EPP\eppException
     */
    private function createHost(string $host, ?string $ip): void
    {
        $create = new eppCreateHostRequest(new eppHost($host, $ip));

        $this->connection->request($create);
    }

    /**
     * Generates a random auth code containing lowercase letters, uppercase letters, numbers and special characters.
     *
     * @return string
     */
    private static function generateValidAuthCode(int $length = 16): string
    {
        return Helper::generateStrictPassword($length, true, true, true, '!@#$%^*_');
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

        $postalInfo = new eppContactPostalInfo(
            $params->name ?: $params->organisation,
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

    private function addNameServers(array $nameservers, eppDomain $domain): eppDomain
    {
        $uncreatedHosts = $this->checkUncreatedHosts($nameservers);

        foreach ($nameservers as $nameserver) {
            if (!empty($uncreatedHosts[$nameserver['host']])) {
                $this->createHost($nameserver['host'], $nameserver['ip'] ?? null);
            }

            $domain->addHost(new eppHost($nameserver['host']));
        }

        return $domain;
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

        $check = new eppCheckRequest($checkHost);
        /** @var \Metaregistrar\EPP\eppCheckResponse|null $response */
        $response = $this->connection->request($check);

        return $response === null ? null : $response->getCheckedHosts();
    }
}
