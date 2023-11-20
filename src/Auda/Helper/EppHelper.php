<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Auda\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Helper;
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
use Upmind\ProvisionProviders\DomainNames\Auda\EppExtension\Requests\EppCreateDomainRequest;
use Upmind\ProvisionProviders\DomainNames\Auda\EppExtension\EppConnection;
use Upmind\ProvisionProviders\DomainNames\Auda\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;

/**
 * Class EppHelper
 *
 * @package Upmind\ProvisionProviders\DomainNames\Auda\Helper
 */
class EppHelper
{
    protected EppConnection $connection;
    protected Configuration $configuration;

    protected array $lockedStatuses = [
        'clientUpdateProhibited',
    ];

    public function __construct(EppConnection $connection, Configuration $configuration)
    {
        $this->connection = $connection;
        $this->configuration = $configuration;
    }

    /**
     * @param string[] $domains
     * @return array
     */
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

            if (!$canRegister && isset($check['reason']) && Str::contains($check['reason'], 'Invalid name syntax')) {
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
     * @param int $limit
     * @param Carbon|null $since
     * @return array
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

    /**
     * @param string $domainName
     * @param int $period
     * @param string[] $nameServers
     * @param array $contacts
     * @return array
     */
    public function register(
        string $domainName,
        int    $period,
        array  $nameServers,
        array  $contacts
    ): array
    {
        $domain = new eppDomain($domainName, $contacts['registrant'], [
            new eppContactHandle($contacts['tech'], eppContactHandle::CONTACT_TYPE_TECH),
            new eppContactHandle($contacts['billing'], eppContactHandle::CONTACT_TYPE_BILLING),
            new eppContactHandle($contacts['admin'], eppContactHandle::CONTACT_TYPE_ADMIN)
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
        $create = new eppCreateDomainRequest($domain);

        $organisationName = $this->getContactInfo($contacts['registrant'])->organisation;
        $create->setRegistrantExt($organisationName);

        /** @var eppCreateDomainResponse */
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
     * @param ContactParams $params
     * @param string $type
     * @return string
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

        $contactInfo = new eppContact($postalInfo, $params->email, $telephone);

        $contactInfo->setType($type);
        $contactInfo->setPassword($params->password ?? self::generateValidAuthCode());

        $contact = new eppCreateContactRequest($contactInfo);

        /** @var eppCreateContactResponse $response */
        $response = $this->connection->request($contact);

        return $response->getContactId();
    }

    /**
     * @param string $contactId
     * @return ContactData
     */
    public function getContactInfo(string $contactId): ContactData
    {
        $request = new eppInfoContactRequest(new eppContactHandle($contactId), false);

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

    /**
     * @param string $domainName
     * @return array
     */
    public function getDomainInfo(string $domainName): array
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse */
        $response = $this->connection->request($info);

        if ($response->getDomainClientId() !== $this->configuration->username) {
            throw ProvisionFunctionError::create(sprintf('Domain %s does not belong to the user', $domainName));
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

    /**
     * @param array $nameServers
     * @return array
     */
    private function parseNameServers(array $nameServers): array
    {
        $result = [];

        if (count($nameServers) > 0) {
            foreach ($nameServers as $i => $ns) {
                $result['ns' . ($i + 1)] = [
                    'host' => strtolower($ns->getHostName()),
                ];
            }
        }

        return $result;
    }

    /**
     * @param string $domainName
     * @return string[]
     */
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

    /**
     * @param string $domainName
     * @param string[] $addStatuses
     * @param string[] $removeStatuses
     * @return void
     */
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

    /**
     * @param string $domainName
     * @return string
     */
    public function getDomainEppCode(string $domainName): string
    {
        $domain = new eppDomain($domainName);
        $info = new eppInfoDomainRequest($domain);

        /** @var eppInfoDomainResponse $response */
        $response = $this->connection->request($info);

        return $response->getDomainAuthInfo();
    }

    /**
     * @param string $domainName
     * @param int $period
     * @return void
     */
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
     * @param string $domainName
     * @param string[] $nameservers
     * @return void
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
            $updateInfo ?? null,
        );

        $this->connection->request($update);
    }

    /**
     * @param string $domainName
     * @return string[]
     */
    public function getHosts(string $domainName): array
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
     * @param string $domainName
     * @param ContactParams $params
     * @return ContactData
     */
    public function updateRegistrantContact(string $domainName, ContactParams $params): ContactData
    {
        $registrantId = $this->createContact($params, 'registrant');

        $upd = new eppDomain($domainName);
        $upd->setRegistrant(new eppContactHandle($registrantId));

        $update = new eppUpdateDomainRequest(
            new eppDomain($domainName),
            null,
            null,
            $upd
        );

        $this->connection->request($update);

        return $this->getContactInfo($registrantId);
    }

    /**
     * @param string $domainName
     * @param int $renewYears
     * @param string|null $eppCode
     * @param array $contacts
     * @return eppTransferResponse
     */
    public function initiateTransfer(
        string  $domainName,
        int     $renewYears,
        ?string $eppCode,
        array   $contacts
    ): eppTransferResponse
    {
        $contactHandle = [];

        if ($contacts['tech']) {
            $contactHandle[] = new eppContactHandle($contacts['tech'], eppContactHandle::CONTACT_TYPE_TECH);
        }

        if ($contacts['billing']) {
            $contactHandle[] = new eppContactHandle($contacts['billing'], eppContactHandle::CONTACT_TYPE_BILLING);
        }

        if ($contacts['admin']) {
            $contactHandle[] = new eppContactHandle($contacts['admin'], eppContactHandle::CONTACT_TYPE_ADMIN);
        }

        $domain = new eppDomain($domainName, $contacts['registrant'], $contactHandle);

        // Set EPP Code
        if ($eppCode != null) {
            $domain->setAuthorisationCode($eppCode);
        }

        $domain->setPeriod($renewYears);
        $domain->setPeriodUnit('y');

        $transferRequest = new eppTransferRequest(eppTransferRequest::OPERATION_REQUEST, $domain);

        /** @var eppTransferResponse */
        return $this->connection->request($transferRequest);
    }

    /**
     * @param string $domainName
     * @return string
     */
    public function getTransferInfo(string $domainName): string
    {
        $domain = new eppDomain($domainName);

        $transferRequest = new eppTransferRequest(eppTransferRequest::OPERATION_QUERY, $domain);

        /** @var eppTransferResponse */
        $response = $this->connection->request($transferRequest);

        return (string)$response->getTransferStatus();
    }
}
