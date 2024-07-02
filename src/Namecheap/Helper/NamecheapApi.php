<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Namecheap\Helper;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\SystemInfo;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Namecheap\Data\NamecheapConfiguration;

/**
 * Class NamecheapCommand
 *
 * @package Upmind\ProvisionProviders\DomainNames\Namecheap\Helper
 */
class NamecheapApi
{
    /**
     * Allowed contact types
     */
    protected const ALLOWED_CONTACT_TYPES = ['registrant', 'tech', 'admin', 'auxbilling'];

    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'Registrant';
    public const CONTACT_TYPE_TECH = 'Tech';
    public const CONTACT_TYPE_ADMIN = 'Admin';
    public const CONTACT_TYPE_BILLING = 'AuxBilling';

    protected Client $client;

    protected NamecheapConfiguration $configuration;

    protected SystemInfo $systemInfo;

    public function __construct(Client $client, NamecheapConfiguration $configuration, SystemInfo $systemInfo)
    {
        $this->client = $client;
        $this->configuration = $configuration;
        $this->systemInfo = $systemInfo;
    }

    /**
     * @param  string  $domainList
     *
     * @return array
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function checkMultipleDomains(string $domainList): array
    {
        $params = [
            'command' => 'namecheap.domains.check',
            'DomainList' => $domainList,
        ];

        $response = $this->makeRequest($params);

        $dacDomains = [];

        foreach ($response->children() as $domain) {
            $domainName = (string) $domain->attributes()->Domain;

            $available = (string) $domain->attributes()->Available === "true";

            $dacDomains[] = DacDomain::create([
                'domain' => $domainName,
                'description' => sprintf(
                    'Domain is %s to register',
                    $available ? 'available' : 'not available'
                ),
                'tld' => Utils::getTld($domainName),
                'can_register' => $available,
                'can_transfer' => !$available,
                /** @phpstan-ignore-next-line */
                'is_premium' => $domain->attributes()->IsPremiumName === "true",
            ]);
        }

        return $dacDomains;
    }

    /**
     * @param  string  $domainName
     * @param  int  $years
     * @param  array  $contacts
     * @param  string  $nameServers
     *
     * @return void
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(string $domainName, int $years, array $contacts, string $nameServers): void
    {
        $params = [
            'command' => 'namecheap.domains.create',
            'DomainName' => $domainName,
            'Years' => $years,
            'Nameservers' => $nameServers,
        ];

        foreach ($contacts as $type => $contact) {
            $contactParams = $this->setContactParams($contact, $type);
            $params = array_merge($params, $contactParams);
        }

        $this->makeRequest($params);
    }

    /**
     * @param  string  $domainName
     * @param  string  $eppCode
     *
     * @return string
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function initiateTransfer(string $domainName, string $eppCode): string
    {
        $params = [
            'command' => 'namecheap.domains.transfer.create',
            'DomainName' => $domainName,
            'EPPCode' => 'base64:' . base64_encode($eppCode),
            'Years' => 1,
        ];

        $response = $this->makeRequest($params)->DomainTransferCreateResult;

        return (string) $response->attributes()->TransferID;
    }

    /**
     * @param  string  $domainName
     * @param  int  $period
     *
     * @return void
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(string $domainName, int $period): void
    {
        $params = [
            'command' => 'namecheap.domains.renew',
            'DomainName' => $domainName,
            'Years' => $period,
        ];

        $this->makeRequest($params);
    }

    /**
     * @param  string  $domainName
     * @param  int  $period
     *
     * @return void
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function reactivate(string $domainName, int $period): void
    {
        $params = [
            'command' => 'namecheap.domains.reactivate',
            'DomainName' => $domainName,
            'Years' => $period,
        ];

        $this->makeRequest($params);
    }

    /**
     * @param  string  $domainName
     *
     * @return array
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainInfo(string $domainName): array
    {
        $params = [
            'command' => 'namecheap.domains.getinfo',
            'DomainName' => $domainName,
        ];

        $response = $this->makeRequest($params)->DomainGetInfoResult;
        $lock = $this->getRegistrarLockStatus($domainName);
        $contacts = $this->getContacts($domainName);

        $status = (string) $response->attributes()->Status;

        return [
            'id' => (string) $response->attributes()->ID,
            'domain' => (string) $response->attributes()->DomainName,
            'statuses' => [$status === "Ok" ? 'Active' : $status],
            'locked' => $lock,
            'registrant' => $this->parseContact($contacts->Registrant, self::CONTACT_TYPE_REGISTRANT),
            'billing' => $this->parseContact($contacts->AuxBilling, self::CONTACT_TYPE_BILLING),
            'tech' => $this->parseContact($contacts->Tech, self::CONTACT_TYPE_TECH),
            'admin' => $this->parseContact($contacts->Admin, self::CONTACT_TYPE_ADMIN),
            'ns' => NameserversResult::create($this->parseNameservers($response->DnsDetails)),
            'created_at' => Utils::formatDate((string) $response->DomainDetails->CreatedDate),
            'updated_at' => null,
            'expires_at' => Utils::formatDate((string) $response->DomainDetails->ExpiredDate),
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \InvalidArgumentException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(string $domainName, ContactParams $contactParams): ContactData
    {
        $currentContacts = $this->getContacts($domainName);

        $registrantParams = $this->setContactParams($contactParams, self::CONTACT_TYPE_REGISTRANT);
        $techParams = $this->setXMLContactParams($currentContacts->Tech, self::CONTACT_TYPE_TECH);
        $adminParams = $this->setXMLContactParams($currentContacts->Admin, self::CONTACT_TYPE_ADMIN);
        $auxBillingParams = $this->setXMLContactParams($currentContacts->AuxBilling, self::CONTACT_TYPE_BILLING);

        $params = [
            'command' => 'namecheap.domains.setContacts',
            'DomainName' => $domainName,
        ];

        $params = array_merge($params, $registrantParams, $techParams, $adminParams, $auxBillingParams);

        $this->makeRequest($params);

        $registrant = $this->getContacts($domainName)->Registrant;

        return $this->parseContact($registrant, self::CONTACT_TYPE_REGISTRANT);
    }

    /**
     * @param  string  $sld
     * @param  string  $tld
     * @param  string  $nameservers
     *
     * @return array
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(string $sld, string $tld, string $nameservers): array
    {
        $command = 'namecheap.domains.dns.setCustom';

        if ($nameservers === "") {
            $command = 'namecheap.domains.dns.setDefault';
        }

        $params = [
            'command' => $command,
            'SLD' => $sld,
            'TLD' => $tld,
            'NameServers' => $nameservers,
        ];

        $this->makeRequest($params);

        $ns = $this->getDNSList($sld, $tld);

        return $this->parseNameservers($ns);
    }

    /**
     * @param  string  $domainName
     * @param  bool  $lock
     *
     * @return void
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setRegistrarLock(string $domainName, bool $lock): void
    {
        $params = [
            'command' => 'namecheap.domains.setRegistrarLock',
            'DomainName' => $domainName,
            'LockAction' => $lock ? "lock" : "unlock",
        ];

        $this->makeRequest($params);
    }

    /**
     * @param  string  $sld
     * @param  string  $tld
     *
     * @return \SimpleXMLElement
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getDNSList(string $sld, string $tld): SimpleXMLElement
    {
        $params = [
            'command' => 'namecheap.domains.dns.getList',
            'SLD' => $sld,
            'TLD' => $tld,
        ];

        return $this->makeRequest($params)->DomainDNSGetListResult;
    }

    /**
     * @param  string  $domainName
     *
     * @return bool
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getRegistrarLockStatus(string $domainName): bool
    {
        $params = [
            'command' => 'namecheap.domains.getRegistrarLock',
            'DomainName' => $domainName,
        ];

        if (!Utils::tldSupportsLocking(Utils::getTld($domainName))) {
            return false;
        }

        $response = $this->makeRequest($params);
        $lockStatus = (string) $response->DomainGetRegistrarLockResult->attributes()->RegistrarLockStatus;

        return $lockStatus === "true";
    }

    /**
     * @param  string  $domainName
     *
     * @return \SimpleXMLElement
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getContacts(string $domainName): SimpleXMLElement
    {
        $params = [
            'command' => 'namecheap.domains.getContacts',
            'DomainName' => $domainName,
        ];

        return $this->makeRequest($params)->DomainContactsResult;
    }

    /**
     * @param  string  $domainName
     *
     * @return array|null
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDomainTransferOrders(string $domainName): ?array
    {
        $params = [
            'command' => 'namecheap.domains.transfer.getlist',
            'SearchTerm' => $domainName,
            'ListType' => 'INPROGRESS',
        ];

        $response = $this->makeRequest($params);
        $orderCount = (int) $response->Paging->TotalItems;

        $orders = [];

        if ($orderCount > 0) {
            foreach ($response->TransferGetListResult->children() as $order) {
                $orders[] = [
                    'orderId' => (string) $order->attributes()->OrderID,
                    'status' => (string) $order->attributes()->Status,
                    'statusId' => (int) $order->attributes()->StatusID,
                    'date' => Utils::formatDate((string) $order->attributes()->StatusDate),
                ];
            }

            if (count($orders) > 0) {
                return $orders;
            }
        }

        return null;
    }

    /**
     * Send request and return the response.
     *
     * @param  array  $params
     *
     * @return \SimpleXMLElement
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(array $params): SimpleXMLElement
    {
        // Prepare command params
        $params = array_merge([
            'ApiUser' => $this->configuration->username,
            'ApiKey' => $this->configuration->api_token,
            'UserName' => $this->configuration->username,
            'ClientIp' => Arr::first($this->systemInfo->outgoing_ips),
        ], $params);

        $response = $this->client->get('/xml.response', ['query' => $params]);

        $result = $response->getBody()->__toString();
        $response->getBody()->close();

        if (empty($result)) {
            throw new RuntimeException('Empty provider api response');
        }

        return $this->parseResponseData($result);
    }

    /**
     * Parse and process the XML Response
     *
     * @param  string  $result
     *
     * @return \SimpleXMLElement
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(string $result): SimpleXMLElement
    {
        // Try to parse the response
        $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        // Check the XML for errors
        if ($errors = $this->parseXmlError($xml->Errors)) {
            throw ProvisionFunctionError::create($this->formatNamecheapErrorMessage($errors))
                ->withData([
                    'response' => $xml,
                ]);
        }

        if (empty($xml->CommandResponse)) {
            throw ProvisionFunctionError::create('No CommandResponse found')
                ->withData([
                    'response' => $result,
                ]);
        }

        return $xml->CommandResponse;
    }

    /**
     * @param  array  $xmlErrors
     *
     * @return string
     */
    private function formatNamecheapErrorMessage(array $xmlErrors): string
    {
        $errors = [];

        foreach ($xmlErrors as $error) {
            switch ($error->attributes()->Number) {
                case 1011150:
                    $errors[] = 'Rejected request - please review whitelisted IPs';
                    break;
                default:
                    $errors[] = sprintf('[%s] %s', $error->attributes()->Number, $error);
                    break;
            }
        }

        return sprintf("Provider API Error: %s", implode(', ', $errors));
    }

    private function parseXmlError(SimpleXMLElement $errors): array
    {
        $result = [];

        foreach ($errors->children() as $err) {
            $result[] = $err;
        }

        return $result;
    }

    /**
     * @param  \SimpleXMLElement  $contact
     * @param  string  $type
     *
     * @return ContactData
     *
     * @throws \InvalidArgumentException
     */
    private function parseContact(SimpleXMLElement $contact, string $type): ContactData
    {
        // Check if our contact type is valid
        self::validateContactType($type);

        return ContactData::create([
            'organisation' => (string) $contact->OrganizationName ?: '-',
            'name' => $contact->FirstName . " " . $contact->LastName,
            'address1' => (string) $contact->Address1,
            'city' => (string) $contact->City,
            'state' => (string) $contact->StateProvince ?: '-',
            'postcode' => (string) $contact->PostalCode,
            'country_code' => Utils::normalizeCountryCode((string) $contact->Country),
            'email' => (string) $contact->EmailAddress,
            'phone' => (string) $contact->Phone,
        ]);
    }

    /**
     * @param  string  $type
     *
     * @throws \InvalidArgumentException
     */
    public static function validateContactType(string $type): void
    {
        if (!in_array(strtolower($type), self::ALLOWED_CONTACT_TYPES)) {
            throw new InvalidArgumentException(sprintf('Invalid contact type %s used!', $type));
        }
    }

    /**
     * @param \SimpleXMLElement  $DnsDetails
     *
     * @return array
     */
    private function parseNameservers(SimpleXMLElement $DnsDetails): array
    {
        $result = [];
        $i = 1;

        foreach ($DnsDetails->children() as $ns) {
            $result['ns' . $i] = ['host' => (string) $ns];
            $i++;
        }

        return $result;
    }

    /**
     * @param  string|null  $name
     *
     * @return array
     */
    private function getNameParts(?string $name): array
    {
        $nameParts = explode(" ", $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(" ", $nameParts);

        return compact('firstName', 'lastName');
    }

    /**
     * @param \SimpleXMLElement  $contact
     * @param string  $type
     *
     * @return array
     */
    private function setXMLContactParams(SimpleXMLElement $contact, string $type): array
    {
        return [
            $type . 'OrganizationName' => (string) $contact->OrganizationName ?: '-',
            $type . 'FirstName' => (string) $contact->FirstName,
            $type . 'LastName' => (string) $contact->LastName,
            $type . 'Address1' => (string) $contact->Address1,
            $type . 'City' => (string) $contact->City,
            $type . 'StateProvince' => (string) $contact->StateProvince ?: '-',
            $type . 'PostalCode' => (string) $contact->PostalCode,
            $type . 'Country' => Utils::normalizeCountryCode((string) $contact->Country),
            $type . 'EmailAddress' => (string) $contact->EmailAddress,
            $type . 'Phone' => (string) $contact->Phone,
        ];
    }

    /**
     * @param  ContactParams  $contactParams
     * @param  string  $type
     *
     * @return array
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    private function setContactParams(ContactParams $contactParams, string $type): array
    {
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        return [
            $type . 'OrganizationName' => $contactParams->organisation ?: '-',
            $type . 'FirstName' => $nameParts['firstName'],
            $type . 'LastName' => $nameParts['lastName'] ?: $nameParts['firstName'],
            $type . 'Address1' => $contactParams->address1,
            $type . 'City' => $contactParams->city,
            $type . 'StateProvince' => $contactParams->state ?: '-',
            $type . 'PostalCode' => $contactParams->postcode,
            $type . 'Country' => Utils::normalizeCountryCode($contactParams->country_code),
            $type . 'EmailAddress' => $contactParams->email,
            $type . 'Phone' => Utils::internationalPhoneToEpp($contactParams->phone),
        ];
    }
}
