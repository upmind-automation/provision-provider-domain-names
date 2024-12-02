<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Norid\Helper;

use Carbon\Carbon;
use DateTime;
use Upmind\ProvisionBase\Helper;
use Metaregistrar\EPP\authEppInfoDomainRequest;
use Metaregistrar\EPP\authEppInfoDomainResponse;
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
use Metaregistrar\EPP\eppUpdateContactRequest;
use Metaregistrar\EPP\noridEppContact;
use Metaregistrar\EPP\noridEppDomain;
use Metaregistrar\EPP\noridEppCreateDomainRequest;
use Metaregistrar\EPP\noridEppCreateDomainResponse;
use Metaregistrar\EPP\noridEppCreateContactRequest;
use Metaregistrar\EPP\noridEppInfoContactResponse;
use Metaregistrar\EPP\noridEppInfoContactRequest;
use Metaregistrar\EPP\noridEppUpdateDomainRequest;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Norid\EppExtension\EppConnection;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Norid\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;

/**
 * Class NoridHelper
 *
 * @package Upmind\ProvisionProviders\DomainNames\Norid\Helper
 */
class NoridApi
{
    protected EppConnection $connection;
    protected Configuration $configuration;

    protected array $lockedStatuses = [
        'serverTransferProhibited',
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
            $available = (bool)$check['available'] == "true";

            $premium = false;

            $description = sprintf(
                'Domain is %s to register. %s',
                $available ? 'available' : 'not available',
                $check['reason'],
            );

            $canTransfer = !$available;
            if (!$available && strtolower($check['reason']) === 'invalid domain') {
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

    public function register(
        string $domainName,
        int    $period,
        array  $contacts,
        array  $nameServers
    ): array
    {
        $domain = new noridEppDomain($domainName, $contacts[eppContactHandle::CONTACT_TYPE_REGISTRANT], [
            new eppContactHandle($contacts[eppContactHandle::CONTACT_TYPE_TECH], eppContactHandle::CONTACT_TYPE_TECH)
        ]);

        $domain->setAuthorisationCode(self::generateValidAuthCode());
        $date = new DateTime();
        $domain->setExtApplicantDataset('3.2', $contacts[eppContactHandle::CONTACT_TYPE_REGISTRANT], $date->format("Y-m-d\TH:i:s.v\Z"));

        $this->createNameServers($nameServers);

        foreach ($nameServers as $nameserver) {
            $domain->addHost(new eppHost($nameserver));
        }

        // Set Domain Period
        $domain->setPeriod($period);
        $domain->setPeriodUnit('y');

        // Create the domain
        $create = new noridEppCreateDomainRequest($domain);

        /** @var noridEppCreateDomainResponse $response */
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

    public function renew(string $domainName): void
    {
        $domainData = new eppDomain($domainName);
        $domainData->setPeriod(1);
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

        if ($checkRegistrar && $response->getDomainClientId() !== $this->configuration->username) {
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

        $postalInfo = new eppContactPostalInfo(
            $params->name ?: $params->organisation,
            $params->city,
            $countryCode,
            $params->organisation,
            $params->address1,
            '',
            'NO-'.$params->postcode,
            eppContact::TYPE_LOC,
        );

        return new eppContact($postalInfo, $params->email, $telephone);
    }

    public function getEppCode(string $domainName): ?string
    {
        $domain = new eppDomain($domainName);
        $info = new authEppInfoDomainRequest($domain);

        /** @var authEppInfoDomainResponse $response */
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

        $this->createNameServers($nameservers);

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
            null
        );

        $this->connection->request($update);
    }

    public function getContactInfo(string $contactId): ContactData
    {
        $request = new noridEppInfoContactRequest(new eppContactHandle($contactId), false);
        try {
            /** @var noridEppInfoContactResponse $response */
            $response = $this->connection->request($request);
        } catch (eppException $e) {
            return ContactData::create(['id' => $contactId]);
        }

        return ContactData::create([
            'id' => $contactId,
            'name' => $response->getContactName(),
            'email' => $response->getContactEmail(),
            'phone' => $response->getExtMobilePhone(),
            'organisation' => $response->getContactCompanyname(),
            'address1' => $response->getContactStreet(),
            'city' => $response->getContactCity(),
            'postcode' => $response->getContactZipcode(),
            'country_code' => $response->getContactCountrycode(),
            'type' => $response->getExtType(),
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

    public function getHosts(string $domainName): ?array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse */
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
        return Helper::generateStrictPassword($length, true, true, true, '-_.');
    }

    public function createContact(ContactParams $params, string $contactType = "role"): string
    {
        $telephone = null;
        if ($params->phone) {
            $telephone = Utils::internationalPhoneToEpp($params->phone);
        }

        $countryCode = null;
        if ($params->country_code) {
            $countryCode = Utils::normalizeCountryCode($params->country_code);
        }

        $org = '';

        if ($contactType == "organization") {
            $org = $params->organisation;
        }

        $postalInfo = new eppContactPostalInfo(
            $params->name ?: $params->organisation,
            $params->city,
            $countryCode,
            $org,
            $params->address1,
            '',
            'NO-'.$params->postcode,
            eppContact::TYPE_LOC,
        );

        $contactInfo = new noridEppContact($postalInfo, $params->email, null, null, ' ');

        $contactInfo->setExtType($contactType);

        if ($contactType == "organization") {
            $contactInfo->setExtIdentity('organizationNumber', $this->configuration->organisationNumber);
            $contactInfo->setExtMobilePhone($telephone);
        }

        $contact = new noridEppCreateContactRequest($contactInfo);

        /** @var eppCreateContactResponse $response */
        $response = $this->connection->request($contact);

        return $response->getContactId();
    }

    private function createNameServers(array $nameservers)
    {
        $uncreatedHosts = $this->checkUncreatedHosts($nameservers);

        foreach ($nameservers as $nameserver) {
            if (!empty($uncreatedHosts[$nameserver])) {
                $this->createHost($nameserver, null);
            }
        }

    }

    private function checkUncreatedHosts(array $nameservers): ?array
    {
        $checkHost = [];
        foreach ($nameservers as $host) {
            $checkHost[] = new eppHost($host);
        }

        $check = new eppCheckRequest($checkHost);
        /** @var \Metaregistrar\EPP\eppCheckResponse|null $response */
        $response = $this->connection->request($check);

        return $response?->getCheckedHosts();
    }
}
