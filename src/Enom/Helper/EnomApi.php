<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Enom\Helper;

use Carbon\Carbon;
use GuzzleHttp\Client;
use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Enom\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

/**
 * Nothing.To.See.Here.
⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⠋⣵⣶⣬⣉⡻⠿⠿⢿⣿⣿⣿⣿⣿⣿⣿⣿⣿
⣿⣿⣿⣿⣿⣿⣿⠿⠿⠛⣃⣸⣿⣿⣿⣿⣿⣿⣷⣦⢸⣿⣿⣿⣿⣿⣿⣿⣿
⣿⣿⣿⣿⣿⣿⢡⣶⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣦⣭⣙⠿⣿⣿⣿⣿⣿
⣿⣿⣿⣿⡿⠿⠘⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣧⢸⣿⣿⣿⣿
⣿⣿⣿⠋⣴⣾⣿⣿⣿⡟⠁⠄⠙⣿⣿⣿⣿⠁⠄⠈⣿⣿⣿⣿⣈⠛⢿⣿⣿
⣿⣿⣇⢸⣿⣿⣿⣿⣿⣿⣦⣤⣾⣿⣿⣿⣿⣦⣤⣴⣿⣿⣿⣿⣿⣷⡄⢿⣿
⣿⠟⣋⣠⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⢸⣿
⢁⣾⣿⣿⣿⣿⣿⡉⠉⠉⠉⠉⠉⠉⠉⠉⠉⠉⠉⠉⠉⠉⠉⣹⣿⣿⣿⣦⠙
⣾⣿⣿⣿⣿⣿⣿⣿⣦⣄⣤⣶⣿⣿⣿⣿⣿⣿⣷⣦⣄⣤⣾⣿⣿⣿⣿⣿⣧
⠘⢿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⠏
⣷⣦⣙⠛⠿⢿⣿⣿⡿⠿⠿⠟⢛⣛⣛⡛⠻⠿⠿⠿⣿⣿⣿⣿⠿⠟⢛⣡⣾
 */

/**
 * Class EnomCommand
 * @package Upmind\ProvisionProviders\DomainNames\Enom\Helper
 */
class EnomApi
{
    /**
     * Allowed contact types
     */
    public const ALLOWED_CONTACT_TYPES = ['registrant', 'tech', 'admin', 'auxbilling'];

    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'Registrant';
    public const CONTACT_TYPE_TECH = 'Tech';
    public const CONTACT_TYPE_ADMIN = 'Admin';
    public const CONTACT_TYPE_BILLING = 'AuxBilling';

    protected Client $client;
    protected Configuration $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * Get domain info
     *
     * @param string $sld
     * @param string $tld
     * @return array
     */
    public function getDomainInfo(string $sld, string $tld): array
    {
        // Params for basic domain info
        $params = [
            'command' => 'GetDomainInfo',
            'SLD' => $sld,
            'TLD' => $tld
        ];

        $domainInfo = $this->makeRequest($params);

        // Get Contacts
        $contacts = $this->getDomainContacts($sld, $tld);

        // Get NS from whois
        $whois = $this->getDomainWhois($sld, $tld);
        $nameServers = $whois['nameservers'] ?? [];

        // Domain Statuses
        $status = $domainInfo->GetDomainInfo->status;

        return [
            'id' => (string) $domainInfo->GetDomainInfo->domainname->attributes()['domainnameid'],
            'domain' => (string) $domainInfo->GetDomainInfo->{'domainname'},
            'statuses' => [(string) $status->{'purchase-status'}, (string) $status->{'registrationstatus'}],
            'registrant' => $contacts['registrant'],
            'ns' => $this->parseNameservers($nameServers),
            // Can't execute GetWhoisContact on test environment, so I wasn't able to test that.
            'created_at' => $this->configuration->sandbox ? Carbon::today()->toDateTimeString() : $whois['created_at'],
            // Can't execute GetWhoisContact on test environment, so I wasn't able to test that.
            'updated_at' => $this->configuration->sandbox ? Carbon::today()->toDateTimeString() : $whois['updated_at'],
            'expires_at' => $this->configuration->sandbox ? Utils::formatDate((string) $status->{'expiration'}) : $whois['expires_at'],
            'locked' => $this->getRegLock($sld, $tld),
        ];
    }

    /**
     * @param string $sld
     * @param string $tld
     * @return ContactData[]
     */
    public function getDomainContacts(string $sld, string $tld): array
    {
        // Params for basic domain info
        $params = [
            'command' => 'GetContacts',
            'SLD' => $sld,
            'TLD' => $tld
        ];

        $contacts = $this->makeRequest($params);

        $registrant = $this->parseContact($contacts->GetContacts->Registrant, self::CONTACT_TYPE_REGISTRANT);
        $admin = $this->parseContact($contacts->GetContacts->Admin, self::CONTACT_TYPE_ADMIN);
        $tech = $this->parseContact($contacts->GetContacts->Tech, self::CONTACT_TYPE_TECH);
        $billing = $this->parseContact($contacts->GetContacts->Billing, self::CONTACT_TYPE_BILLING);

        return compact('registrant', 'admin', 'tech', 'billing');
    }

    /**
     * @return array
     */
    public function getAccountDomains(): array
    {
        // Command Params
        $params = [
            'command' => 'GetAllDomains'
        ];

        $result = $this->makeRequest($params);

        $domains = [];

        foreach ($result->GetAllDomains->children() as $childTagName => $childTagData) {
            // Process only domain records
            if ($childTagName == 'DomainDetail') {
                // Get TLD and SLD
                $parts = Utils::getSldTld((string) $childTagData->DomainName);

                $domains[] = [
                    'sld' => $parts['sld'],
                    'tld' => $parts['tld'],
                    'domain' => (string) $childTagData->DomainName,
                    'created_at' => '',
                    'expires_at' => Utils::formatDate((string) $childTagData->{'expiration-date'}),
                ];
            }
        }

        return $domains;
    }

    /**
     * The EPP code itself is not returned (no way to obtain it from eNom), but will be sent to the email if the code exists.
     *
     * @param string $sld
     * @param string $tld
     * @return void
     */
    public function getEppCode(string $sld, string $tld): void
    {
        // Command params
        $params = [
            'command' => 'SynchAuthInfo',
            'SLD' => $sld,
            'TLD' => $tld,
            'EmailEPP' => 'True',
            'RunSynchAutoInfo' => 'True'
        ];

        $result = $this->makeRequest($params);
    }

    /**
     * @param string $sld
     * @param string $tld
     * @return string
     */
    public function setDomainPassword(string $sld, string $tld): string
    {
        // Generate Password
        $password = bin2hex(random_bytes(20));

        // Command params
        $params = [
            'command' => 'SetPassword',
            'SLD' => $sld,
            'TLD' => $tld,
            'EmailEPP' => 'True',
            'RunSynchAutoInfo' => 'True',
            'DomainPassword' => $password
        ];

        $result = $this->makeRequest($params);

        return $password;
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param bool $autoRenew
     */
    public function setRenewalMode(string $sld, string $tld, bool $autoRenew): void
    {
        // Command params
        $params = [
            'command' => 'SetRenew',
            'SLD' => $sld,
            'TLD' => $tld,
            'RenewFlag' => (int) $autoRenew
        ];

        $result = $this->makeRequest($params);
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param bool $lock
     */
    public function setRegLock(string $sld, string $tld, bool $lock): void
    {
        // Command params
        $params = [
            'command' => 'SetRegLock',
            'SLD' => $sld,
            'TLD' => $tld,
            'UnlockRegistrar' => (string)intval(!$lock),
        ];

        $result = $this->makeRequest($params);
    }

    /**
     * @param string $sld
     * @param string $tld
     * @return bool
     */
    public function getRegLock(string $sld, string $tld): bool
    {
        // Command params
        $params = [
            'command' => 'GetRegLock',
            'SLD' => $sld,
            'TLD' => $tld
        ];

        $result = $this->makeRequest($params);

        return (bool) (int) $result->{'reg-lock'};
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param $contactParams
     * @param string $type
     */
    public function createUpdateDomainContact(
        string $sld,
        string $tld,
        ContactParams $contactParams,
        string $type
    ): void {
        // Validate Contact Type first
        self::validateContactType($type);

        // Prepare params
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        $params = [
            'command' => 'Contacts',
            'SLD' => $sld,
            'TLD' => $tld,
            'ContactType' => strtoupper($type),
            $type . 'FirstName' => $nameParts['firstName'],
            $type . 'LastName' => $nameParts['lastName'],
            $type . 'OrganizationName' => $contactParams->organisation,
            $type . 'Address1' => $contactParams->address1,
            $type . 'City' => $contactParams->city,
            $type . 'PostalCode' => $contactParams->postcode,
            $type . 'Country' => Utils::normalizeCountryCode($contactParams->country_code),
            $type . 'EmailAddress' => $contactParams->email,
            $type . 'Phone' => $contactParams->phone
        ];

        $result = $this->makeRequest($params);
    }

    /**
     * @param string $sld
     * @param string $tld
     * @return string
     */
    public function getDomainPassword(string $sld, string $tld): string
    {
        // Command params
        $params = [
            'command' => 'GetPasswordBit',
            'SLD' => $sld,
            'TLD' => $tld
        ];

        $result = $this->makeRequest($params);

        return (string) $result->DomainPassword;
    }

    /**
     * @return array
     */
    public function getDomainWhois(string $sld, string $tld): array
    {
        // Command params
        $params = [
            'command' => 'GetWhoisContact',
            'SLD' => $sld,
            'TLD' => $tld
        ];

        $whois = $this->makeRequest($params);

        $rrp = $whois->GetWhoisContacts->{'rrp-info'};

        $nameServers = [];

        foreach ($rrp->nameserver->children() as $ns) {
            $nameServers[] = strtolower((string) $ns);
        }

        return [
            'nameservers' => $nameServers,
            'created_at' => Utils::formatDate((string) $rrp->{'created-date'}),
            'updated_at' => Utils::formatDate((string) $rrp->{'updated-date'}),
            'expires_at' => Utils::formatDate((string) $rrp->{'registration-expiration-date'})
        ];
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param string[] $nameServers
     *
     * @return string[]
     */
    public function modifyNameServers(string $sld, string $tld, array $nameServers): array
    {
        // Update Name Servers Command Params
        $params = [
            'command' => 'ModifyNS',
            'SLD' => $sld,
            'TLD' => $tld
        ];

        $nameServerNumber = 1;

        // Attach the new nameservers
        foreach ($nameServers as $host) {
            $params['NS' . $nameServerNumber] = $host;

            $nameServerNumber++;
        }

        $this->makeRequest($params);

        $whois = $this->getDomainWhois($sld, $tld);
        return $this->parseNameservers($whois['nameservers'] ?? []);
    }

    /**
     * @return int Order ID
     */
    public function renew(string $sld, string $tld, int $period, bool $renewIdProtect = false): int
    {
        // Renew command params
        $params = [
            'command' => 'extend',
            'SLD' => $sld,
            'TLD' => $tld,
            'NumYears' => $period,
            'OverrideOrder' => 1 // allow multiple renewal orders in the same day
        ];

        $result = $this->makeRequest($params);

        $orderId = (int) $result->OrderID;

        if ($renewIdProtect) {
            // Get Domain Info and Additional Services
            $idProtectParams = [
                'command' => 'RenewServices',
                'SLD' => $sld,
                'TLD' => $tld,
                'Service' => 'WPPS'
            ];

            $idProtectResult = $this->makeRequest($idProtectParams);
        }

        return $orderId;
    }

    /**
     * @param array $nameServers
     * @return array
     */
    private function parseNameservers(array $nameServers): array
    {
        $result = [];

        if (count($nameServers) > 0) {
            foreach ($nameServers as $i => $ns) {
                $result['ns' . ($i + 1)] = [
                    'host' => $ns,
                    'ip' => null // No IP address available
                ];
            }
        }

        return $result;
    }

    /**
     * @param string $domainList
     * @return array
     */
    public function checkMultipleDomains(string $domainList): array
    {
        // Params
        $params = [
            'command' => 'Check',
            'DomainList' => $domainList
        ];

        $result = $this->makeRequest($params);

        $domainResults = [];

        for ($i = 1; $i <= 30; $i++) {
            if (isset($result->{'Domain' . $i})) {
                $domainResults[] = [
                    'domain' => (string) $result->{'Domain' . $i},
                    'available' => (int) $result->{'RRPCode' . $i} == 210 ? true : false,
                    'reason' => (string) $result->{'RRPText' . $i}
                ];
            }
        }

        return $domainResults;
    }

    /**
     * @return int Order ID
     */
    public function register(
        string $sld,
        string $tld,
        int $numYears,
        ContactParams $contactParams,
        ?array $nameServers = null,
        bool $transferLock = true
    ): int {
        // Command Params
        $nameParts = $this->getNameParts($contactParams->name ?? $contactParams->organisation);

        $params = [
            'command' => 'Purchase',
            'SLD' => $sld,
            'TLD' => $tld,
            'NumYears' => $numYears,
            'UnLockRegistrar' => (int) (bool) !$transferLock,
            'RegistrantFirstName' => $nameParts['firstName'],
            'RegistrantLastName' => $nameParts['lastName'],
            'RegistrantOrganizationName' => $contactParams->organisation,
            'RegistrantAddress1' => $contactParams->address1,
            'RegistrantCity' => $contactParams->city,
            'RegistrantPostalCode' => $contactParams->postcode,
            'RegistrantCountry' => Utils::normalizeCountryCode($contactParams->country_code),
            'RegistrantEmailAddress' => $contactParams->email,
            'RegistrantPhone' => $contactParams->phone
        ];

        // Set NameServers
        if (is_null($nameServers) || count($nameServers) < 1) {
            $params['UseDNS'] = 'default';
        } else {
            $nameServerNumber = 1;

            foreach ($nameServers as $host) {
                $params['NS' . $nameServerNumber] = $host;

                $nameServerNumber++;
            }
        }

        $result = $this->makeRequest($params);

        return (int) $result->OrderID;
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param string $eppCode
     * @param bool $lock
     * @param bool $autoRenew
     * @return array
     */
    public function initiateTransfer(
        string $sld,
        string $tld,
        string $eppCode,
        bool $lock = false,
        bool $autoRenew = false
    ): array {
        // Command Params
        $params = [
            'command' => 'TP_CreateOrder',
            'SLD1' => $sld,
            'TLD1' => trim($tld, '.'),
            'AuthInfo1' => $eppCode,
            'OrderType' => 'Autoverification',
            'DomainCount' => 1,
            'Lock' => (int) $lock,
            'Renew' => (int) $autoRenew
        ];

        $transfer = $this->makeRequest($params);

        return [
            'orderId' => (int) $transfer->transferorder->transferorderid,
            'status' => (string) $transfer->transferorder->statusdesc,
            'statusId' => (int) $transfer->transferorder->statusid,
            'date' => Utils::formatDate((string) $transfer->transferorder->orderdate)
        ];
    }

    /**
     * @param string $sld
     * @param string $tld
     * @return array|null
     */
    public function getDomainTransferOrders(string $sld, string $tld): ?array
    {
        // Command Params
        $params = [
            'command' => 'TP_GetOrdersByDomain',
            'SLD' => $sld,
            'TLD' => $tld
        ];

        $result = $this->makeRequest($params);

        $orderCount = (int) $result->ordercount;

        $orders = [];

        if ($orderCount > 0) {
            foreach ($result->children() as $key => $childData) {
                if ((string) $childData->loginid !== $this->configuration->username) {
                    continue; // skip transfer orders belonging to different registrars (?!)
                }

                if ((string) $key == 'TransferOrder') {
                    $orders[] = [
                        'orderId' => (int) $childData->transferorderid,
                        'status' => (string) $childData->orderstatus,
                        'statusId' => (int) $childData->statusid,
                        'date' => Utils::formatDate((string) $childData->orderdate, null, (string)$result->TimeDifference)
                    ];
                }
            }

            if (count($orders) > 0) {
                return $orders;
            }
        }

        return null;
    }

    /**
     * @param string $orderId
     * @return array|null
     */
    public function getOrderDetails(string $orderId): ?array
    {
        // Command Params
        $params = [
            // 'command' => 'TP_GetOrderDetail',
            // 'TransferOrderDetailID' => $orderId,
            'command' => 'TP_GetOrder',
            'TransferOrderID' => $orderId,
        ];

        $result = $this->makeRequest($params);

        $order = (array)$result->transferorder;
        $order['transferorderdetail'] = (array)$order['transferorderdetail'];

        return $order;
    }

    /**
     * @param string|null $name
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
     * @param \SimpleXMLElement $rawContactData
     * @param   string  $type   Contact Type (Registrant, Tech, Admin, Billing)
     * @return ContactData
     */
    private function parseContact(\SimpleXMLElement $rawContactData, string $type): ContactData
    {
        // Check if our contact type is valid
        self::validateContactType($type);

        return ContactData::create([
            // 'id' => $type, // Using type here, because sometimes there's no obtainable PartyId
            'name' => sprintf('%s %s', (string) $rawContactData->{$type . 'FirstName'}, (string) $rawContactData->{$type . 'LastName'}),
            'email' => (string) $rawContactData->{$type . 'EmailAddress'},
            'phone' => (string) $rawContactData->{$type . 'Phone'},
            'organisation' => (string) $rawContactData->{$type . 'OrganizationName'},
            'address1' => (string) $rawContactData->{$type . 'Address1'},
            'city' => (string) $rawContactData->{$type . 'City'},
            'postcode' => (string) $rawContactData->{$type . 'PostalCode'},
            'country_code' => Utils::normalizeCountryCode((string) $rawContactData->{$type . 'Country'}),
            'type' => $type,
        ]);
    }

    /**
     * @param string $type
     *
     * @throws InvalidArgumentException
     */
    public static function validateContactType(string $type): void
    {
        if (!in_array(strtolower($type), self::ALLOWED_CONTACT_TYPES)) {
            throw new InvalidArgumentException(sprintf('Invalid contact type %s used!', $type));
        }
    }

    /**
     * Send request and return the response.
     *
     * @param array $params
     *
     * @return SimpleXMLElement
     *
     * @throws ProvisionFunctionError
     */
    public function makeRequest(array $params): SimpleXMLElement
    {
        // Prepare command params
        $params = array_merge([
            'UID' => $this->configuration->username,
            'PW' => $this->configuration->api_token,
        ], $params);

        $httpQuery = [
            'responseType' => 'xml'
        ];

        // Format params
        foreach ($params as $key => $param) {
            if (is_array($param)) {
                $param = implode(',', $param);
            }

            if (strtolower($key) == 'tld' && substr($param, 0, 1) == '.') {
                $param = substr($param, 1);
            }

            $httpQuery[$key] = $param;
        }

        $response = $this->client->get(
            $this->configuration->sandbox
                ? 'https://resellertest.enom.com/interface.asp'
                : 'https://reseller.enom.com/interface.asp',
            [
                'query' => $httpQuery
            ]
        );

        $result = $response->getBody()->__toString();
        $response->getBody()->close();

        // Init cUrl
        if (empty($result)) {
            // Something bad happened...
            throw new RuntimeException('Empty enom api response');
        }

        return $this->parseResponseData($result);
    }

    /**
     * Parse and process the XML Response
     *
     * @param string $result
     *
     * @return SimpleXMLElement
     *
     * @throws ProvisionFunctionError
     */
    private function parseResponseData(string $result): SimpleXMLElement
    {
        // Try to parse the response
        $xml = simplexml_load_string($result, 'SimpleXMLElement', LIBXML_NOCDATA);

        // Just in case...
        if ($xml === false) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        // Check the XML for errors
        if (
            (isset($xml->RRPCode) && (int)$xml->RRPCode != 200)
            || (isset($xml->ErrCount) && $xml->ErrCount > 0)
        ) {
            throw ProvisionFunctionError::create($this->formatEnomErrorMessage((array)$xml->errors))
                ->withData([
                    'response' => $xml,
                ]);
        }

        return $xml;
    }

    /**
     * @param array $xmlErrors
     * @return string
     */
    private function formatEnomErrorMessage(array $xmlErrors): string
    {
        if (empty($xmlErrors)) {
            $xmlErrors = ['Unknown error'];
        }

        return sprintf('Provider API Error: %s', implode(', ', $xmlErrors));
    }
}
