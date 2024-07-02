<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\ConnectReseller;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\ConnectReseller\Data\ConnectResellerConfiguration;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Countries;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var ConnectResellerConfiguration
     */
    protected $configuration;

    /**
     * Customer IDs keyed by email address.
     *
     * @var int[]
     */
    protected $customerIds = [];

    /**
     * @var int
     */
    private const PRODUCT_TYPE_REGISTER = 1;

    /**
     * @var int
     */
    private const ORDER_TYPE_TRANSFER = 4;

    /**
     * @var int
     */
    private const ORDER_TYPE_RENEW = 2;

    /**
     * Max positions for nameservers
     */
    private const MAX_CUSTOM_NAMESERVERS = 4;

    public function __construct(ConnectResellerConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('ConnectReseller')
            ->setDescription('Register, transfer, renew and manage ConnectReseller domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/connectreseller-logo_2x.png');
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
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $data = [];
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $customerId = $this->_getCustomerId($params->registrant->register);

        $data['Id'] = $customerId;
        $data['Duration'] = $params->renew_years;
        $data['IsWhoisProtection'] = true;
        $data['Websitename'] = $domainName;
        $data['ProductType'] = self::PRODUCT_TYPE_REGISTER;

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            $ns = 'ns' . $i;

            if (isset($params->nameservers->$ns->host)) {
                $data[$ns] = $params->nameservers->$ns->host;
            }
        }

        if (empty($data['ns1']) || empty($data['ns2'])) {
            $data['ns1'] = $this->_getDefaultNameserver(1);
            $data['ns2'] = $this->_getDefaultNameserver(2);
        }

        $this->_callApi($data, 'Order');

        $registrantContactId = $this->_createContact($params->registrant->register, $customerId);
        $billingContactId = $this->_createContact($params->billing->register);
        $techContactId = $this->_createContact($params->tech->register);
        $adminContactId = $this->_createContact($params->admin->register);

        $this->_setContacts(
            $domainName,
            $registrantContactId,
            $billingContactId,
            $techContactId,
            $adminContactId,
        );

        return $this->_getDomain($domainName)->setMessage('Domain registered - ' . $domainName);
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getDomain($domainName)->setMessage('Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        try {
            $this->_checkTransfer($domainName);
        } catch (InvalidArgumentException $e) {
            // we need to initiate a transfer order
        }

        $contact = $params->registrant->register
            ?? $params->admin->register
            ?? $params->tech->register
            ?? $params->billing->register
            ?? null;

        if (!$contact) {
            $this->errorResult('Contact details are required to initiate transfer');
        }

        $eppCode = $params->epp_code ?? '0000';
        $customerId = $this->_getCustomerId($contact);

        $transferResponse = $this->_initiateTransfer($customerId, $domainName, $eppCode);

        $this->errorResult('Domain transfer initiated', [], ['response_data' => $transferResponse]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $expiryDate = $this->_renewDomain($domain, $params->renew_years);

        return $this->_getDomain($domain)
            ->setMessage('Domain successfully renewed')
            ->setExpiresAt(Carbon::parse($expiryDate));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        return $this->_getDomain($domainName)->setMessage('Domain info obtained');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);
        $domainId = $this->_getDomainId($domain);

        $paramsApi = [
            'domainNameId' => $domainId,
            'websiteName' => $domain,
        ];

        $paramsApi['nameServer1'] = $params->ns1->host;
        $paramsApi['nameServer2'] = $params->ns2->host;
        if (isset($params->ns3->host)) {
            $paramsApi['nameServer3'] = $params->ns3->host;
        }
        if (isset($params->ns4->host)) {
            $paramsApi['nameServer4'] = $params->ns4->host;
        }

        $this->_callApi($paramsApi, 'UpdateNameServer');

        $returnNameservers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (isset($paramsApi['nameServer' . $i])) {
                $returnNameservers['ns' . $i] = [
                    'host' => $paramsApi['nameServer' . $i],
                    'ip' => Arr::get($params, 'ns' . $i)['ip']
                ];
            }
        }

        return NameserversResult::create($returnNameservers)
            ->setMessage('Nameservers updated');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainData = $this->_getDomain(Utils::getDomain($params->sld, $params->tld));

        $eppCode = $domainData['responseData']['authCode'] ?? null;

        if (empty($eppCode)) {
            $eppCode = $this->_callApi([
                'domainNameId' => $domainData['id'],
            ], 'ViewEPPCode')['responseData'];
        }

        return EppCodeResult::create([
            'epp_code' => $eppCode
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported!');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $registrantContactId = $this->_createContact($params->contact, $this->_getDomainCustomerId($domain));

        $domainInfo = $this->_getDomain($domain);

        $this->_setContacts(
            $domain,
            $registrantContactId,
            $domainInfo->billing->id,
            $domainInfo->tech->id,
            $domainInfo->admin->id
        );

        return ContactResult::create($this->_getContactData($registrantContactId)->toArray())
            ->setMessage('Registrant contact updated');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);
        $domainId = $this->_getDomainId($domain);

        $domainData = $this->_getDomain($domain);

        if ($domainData->locked == $params->lock) {
            return $domainData->setLocked($params->lock)
                ->setMessage(sprintf('Domain already %s', $domainData->locked ? 'locked' : 'unlocked'));
        }

        $this->_callApi([
            'domainNameId' => $domainId,
            'websiteName' => $domain,
            'isDomainLocked' => $params->lock,
        ], 'ManageDomainLock');

        return $domainData->setLocked($params->lock)
            ->setMessage(sprintf('Domain %s', $params->lock ? 'locked' : 'unlocked'));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('The requested operation not supported', $params);
    }

    /**
     * ProvisionFunctionError exception if domain doesn't exist or is otherwise not active.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _getDomain(string $domainName, $assertActive = true): DomainResult
    {
        $domainDataCall = $this->_callApi(
            [
                'websiteName' => $domainName
            ],
            'ViewDomain',
            'GET'
        );

        $domainData = $domainDataCall['responseData'];

        $ns = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (!empty($domainData['nameserver' . $i])) {
                $ns['ns' . $i] = [
                    'host' => $domainData['nameserver' . $i],
                ];
            }
        }

        $statuses = [];
        if (isset($domainData['status']) && $domainData['status']) {
            $statuses = [$domainData['status']];
        }

        $info = DomainResult::create([
            'id' => (string)$domainData['domainNameId'],
            'domain' => $domainData['websiteName'],
            'statuses' => $statuses,
            'locked' => $domainData['isDomainLocked'],
            'registrant' => $this->_getContactData($domainData['registrantContactId']),
            'billing' => ['id' => $domainData['billingContactId']],
            'admin' => ['id' => $domainData['adminContactId']],
            'tech' => ['id' => $domainData['technicalContactId']],
            'ns' => $ns,
            'created_at' => $this->_timestampToDateTime($domainData['creationDate']),
            'updated_at' => $this->_timestampToDateTime($domainData['lastUpdatedDate'] ?? $domainData['creationDate']),
            'expires_at' => $this->_timestampToDateTime($domainData['expirationDate']),
        ]);

        if ($assertActive && !array_intersect(['Active', 'Locked'], $statuses)) {
            $message = 'Domain is not active';

            $this->errorResult(
                $message,
                $info->toArray(),
                ['response_data' => $domainData]
            );
        }

        return $info;
    }

    /**
     * @param  string  $domain
     * @param  int|string  $registrantContactId
     * @param  int|string  $billingContactId
     * @param  int|string  $techContactId
     * @param  int|string  $adminContactId
     * @param  int|null  $domainId
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _setContacts(
        string $domain,
        $registrantContactId,
        $billingContactId,
        $techContactId,
        $adminContactId,
        $domainId = null
    ) {
        try {
            $this->_callApi([
                'domainNameId' => $domainId ?? $this->_getDomainId($domain),
                'websiteName' => $domain,
                'registrantContactId' => $this->_normalizeContactId($registrantContactId),
                'billingContactId' => $this->_normalizeContactId($billingContactId),
                'technicalContactId' => $this->_normalizeContactId($techContactId),
                'adminContactId' => $this->_normalizeContactId($adminContactId),
            ], 'updatecontact');
        } catch (ProvisionFunctionError $e) {
            if (1000 === Arr::get($e->getDebug(), 'response_data.responseData.statusCode')) {
                // unbelievably, they return an error code for this in responseMsg but it's not an error!
                return;
            }

            throw $e;
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _getDomainId(string $domain): int
    {
        return $this->_callApi([
            'websiteName' => $domain
        ], 'ViewDomain')['responseData']['domainNameId'];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _getDomainCustomerId(string $domain): int
    {
        return $this->_callApi([
            'websiteName' => $domain
        ], 'ViewDomain')['responseData']['customerId'];
    }

    /**
     * Renew the given domain name and obtain the new expiry date.
     *
     * @param string $domainName
     * @param int $renewYears
     *
     * @return string New expiry date
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _renewDomain(string $domainName, int $renewYears): string
    {
        $data = $this->_callApi([
            'Id' => $this->_getDomainCustomerId($domainName),
            'Websitename' => $domainName,
            'OrderType' => self::ORDER_TYPE_RENEW,
            'Duration' => $renewYears
        ], 'RenewalOrder');

        return $data['responseData']['exdate'];
    }

    /**
     * @param array $data
     * @param string $path
     * @param string $method
     * @return mixed
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _callApi(array $data, string $path, string $method = 'GET')
    {
        $url = 'https://api.connectreseller.com/ConnectReseller/ESHOP/';
        $url .= $path ;

        $client = new Client([
            'handler' => $this->getGuzzleHandlerStack(),
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $query = array_merge(
            $data,
            ['APIKey' => $this->configuration->api_key]
        );

        try {
            $response = $client->request(
                $method,
                $url,
                ['query' => $query]
            );

            $responseData = $this->getResponseData($response);

            $statusCode = $responseData['responseMsg']['statusCode']
                ?? $responseData['responseData']['statusCode']
                ?? $responseData['statusCode']
                ?? 'unknown';
            if (!in_array($statusCode, [200, 1000])) {
                $errorMessage = $this->getResponseErrorMessage($responseData);

                $this->errorResult(
                    sprintf('Provider API %s error: %s', $statusCode, $errorMessage),
                    [],
                    ['response_data' => $responseData],
                );
            }

            return $responseData;
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Generate a custom ProvisionFunctionError exceptiom, but throw as-is if error is completely unexpected.
     *
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                /** @var \Psr\Http\Message\ResponseInterface $response */
                $response = $e->getResponse();

                // application/json responses
                $responseData = $this->getResponseData($response);
                $errorMessage = $this->getResponseErrorMessage($responseData);

                $this->errorResult(
                    sprintf('Provider API error: %s', $errorMessage),
                    [],
                    ['response_data' => $responseData],
                    $e
                );
            }
        }

        // totally unexpected error - re-throw and let provision system handle
        throw $e;
    }

    /**
     * Obtain the response body data from the given api response.
     *
     * @return array|string|int
     *
     * @throws \Throwable
     */
    protected function getResponseData(Response $response)
    {
        $body = trim($response->getBody()->__toString());

        return json_decode($body, true);
    }

    /**
     * Get a friendly error message from the given response data.
     *
     * @param array $responseData
     *
     * @return string
     */
    protected function getResponseErrorMessage($responseData): string
    {
        $statusCode = $responseData['responseMsg']['statusCode'] ?? $responseData['statusCode'] ?? 'unknown';

        $errorMessage = trim(
            $responseData['responseText'] ?? $responseData['error'] ?? 'unknown error'
        );

        if (isset($responseData['statusText'])) {
            $errorMessage = ucwords(str_replace('_', ' ', Str::snake($responseData['statusText'])));
        }

        if (isset($responseData['responseText'])) {
            $errorMessage = ucwords($responseData['responseText']);
        }

        if (isset($responseData['responseMsg']['message'])) {
            $errorMessage = $responseData['responseMsg']['message'];
        }

        // Unauthorized error thrown when an un-whitelisted IP is used
        if ($statusCode === 401 && ($responseData['statusText'] ?? null) === 'Unauthorized') {
            $errorMessage = 'Authentication failed - please review whitelisted IPs';
        }

        return $errorMessage;
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _getCustomerId(ContactParams $contact)
    {
        $email = $this->_normalizeEmail($contact->email);

        if (isset($this->customerIds[$email])) {
            return $this->customerIds[$email];
        }

        $customerData = $this->_getCustomer($email);

        if (isset($customerData['responseData']['clientId'])) {
            return $customerData['responseData']['clientId'];
        }

        return $this->customerIds[$email] = $this->_createCustomer($contact);
    }

    /**
     * Create a contact and return the contact id.
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _createContact(ContactParams $contact, ?int $customerId = null): int
    {
        if ($contact->phone) {
            [$phoneCode, $phone] = $this->getPhoneParts($contact->phone);
        }

        $data = [
            'Id' => $customerId ?? $this->_getCustomerId($contact),
            'EmailAddress' => $this->_normalizeEmail($contact->email),
            'Password' => $this->_generateRandomPassword(),
            'Name' => $contact->name ?? $contact->organisation,
            'CompanyName' => $contact->organisation,
            'Address' => $contact->address1,
            'City' => $contact->city,
            'StateName' => $contact->state ?? $contact->city,
            'CountryName' => $this->_countryCodeToName($contact->country_code),
            'Zip' => $contact->postcode,
            'PhoneNo_cc' => $phoneCode ?? null,
            'PhoneNo' => $phone ?? null,
        ];

        return $this->_callApi($data, 'AddRegistrantContact')['responseMsg']['id'];
    }

    /**
     * @throws \Throwable
     */
    protected function _getCustomer(string $email): array
    {
        $data = [
            'UserName' => $email
        ];
        try {
            return $this->_callApi($data, 'ViewClient', 'GET');
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Create a customer and return its ID.
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _createCustomer(ContactParams $contact): int
    {
        if ($contact->phone) {
            [$phoneCode, $phone] = $this->getPhoneParts($contact->phone);
        }

        $data = [
            'FirstName' => $contact->name ?? $contact->organisation,
            'UserName' => $this->_normalizeEmail($contact->email),
            'Password' => $this->_generateRandomPassword(),
            'CompanyName' => $contact->organisation,
            'Address1' => $contact->address1,
            'City' => $contact->city,
            'StateName' => $contact->state ?? $contact->city,
            'CountryName' => $this->_countryCodeToName($contact->country_code),
            'Zip' => $contact->postcode,
            'PhoneNo_cc' => $phoneCode ?? null,
            'PhoneNo' => $phone ?? null,
        ];

        return $this->_callApi($data, 'AddClient')['responseData']['clientId'];
    }

    private function _generateRandomPassword()
    {
        return bin2hex(openssl_random_pseudo_bytes(4));
    }

    private function _getDefaultNameserver(int $int)
    {
        $serverName = 'connectreseller.com';

        return 'ns' . $int . '.' . $serverName;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function _initiateTransfer(int $customerId, string $domainName, $eppCode): array
    {
        $transferData = $this->_callApi([
            'Websitename' => $domainName,
            'AuthCode' => $eppCode,
            'Authcode' => $eppCode, // API docs inconsistent about the precise parameter name
            'OrderType' => self::ORDER_TYPE_TRANSFER,
            'Id' => $customerId,
            'IsWhoisProtection' => true
        ], 'TransferOrder');

        if ($transferData['responseData']['statusCode'] !== 200) {
            // transfer initiation failed somewhat
            $message = $transferData['responseData']['message'];

            if (Str::contains($message, 'invalid AuthCode')) {
                // epp code invalid
                $this->errorResult('Invalid EPP Code', [], ['response_data' => $transferData]);
            }

            $this->errorResult(
                sprintf('Provider API %s error: %s', $transferData['responseData']['statusCode'], $message),
                [],
                ['response_data' => $transferData],
            );
        }

        return $transferData;
    }

    /**
     * Check the status of an existing transfer order and return successful transfer data.
     *
     * @return array Successful transfer data, if transfer is complete
     *
     * @throws \InvalidArgumentException If transfer order doesn't exist or EPP code is invalid (initiate new transfer)
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If transfer is in progress and we just gotta wait
     * @throws \Throwable
     */
    protected function _checkTransfer(string $domain)
    {
        try {
            $checkData = $this->_callApi([
                'domainName' => $domain,
            ], 'syncTransfer');

            $status = Arr::get($checkData, 'responseData.status');
            $reason = Arr::get($checkData, 'responseData.reason');
            $expiryDate = Arr::get($checkData, 'responseData.expiryDate');

            if ($status === 'pending') {
                // transfer in progress
                if (Str::contains($reason, 'Invalid Auth Code')) {
                    // actually, transfer NOT in progress cause epp code was invalid ...!
                    throw new InvalidArgumentException('Transfer was initiated with an invalid auth code');
                }
            }

            if (empty($expiryDate)) {
                $this->errorResult(sprintf('Transfer %s: %s', $status, $reason), [], ['response_data' => $checkData]);
            }

            return $checkData;
        } catch (ProvisionFunctionError $e) {
            $checkData = Arr::get($e->getDebug(), 'response_data') ?: [];

            if (!Arr::has($checkData, 'responseData.status')) {
                // E.g., API returned a 500 error (super helpful response!)
                // maybe domain doesn't exist at all?
                throw new InvalidArgumentException('Transfer status uncheckable for this domain', 0, $e);
            }

            $status = Arr::get($checkData, 'responseData.status');

            if ($status === 'failed') {
                // E.g., Given domain was registered instead of transferred (super helpful response!)
                // maybe transfer order never existed?
                throw new InvalidArgumentException('Unable to check transfer status of this domain', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function _getContactData($contactId): ContactData
    {
        $data = $this->_callApi([
            'RegistrantContactId' => $this->_normalizeContactId($contactId),
        ], 'ViewRegistrant');

        $contact = $data['responseData'];

        return ContactData::create([
            'id' => $contactId,
            'name' => $contact['name'],
            'email' => $contact['emailAddress'],
            'phone' => '+' . $contact['phoneCode'] . $contact['phoneNo'],
            'organisation' => $contact['companyName'],
            'address1' => $contact['address1'],
            'city' => $contact['city'],
            'state' => $contact['stateName'],
            'postcode' => $contact['zipCode'],
            'country_code' => $this->_countryNameToCode($contact['countryName']),
        ]);
    }

    /**
     * Obtain the given contact id without a OR_ prefix.
     *
     * @param string|int $contactId
     */
    private function _normalizeContactId($contactId): string
    {
        $pieces = explode('_', (string)$contactId, 2);

        return array_pop($pieces);
    }

    /**
     * Get the international dialling code and local number as a 2-tuple.
     *
     * @param string $phone International phone number
     *
     * @return array<int, string|null> E.g., ['44', '1234567890']
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    protected function getPhoneParts($phone): array
    {
        if (empty($phone)) {
            return [null, null];
        }

        $eppPhone = Utils::internationalPhoneToEpp($phone);

        return explode('.', Str::replaceFirst('+', '', $eppPhone), 2);
    }

    /**
     * Removes the local part from an email address if present, to make it
     * compatible with ConnectReseller.
     *
     * @param string $email E.g., harry+test@upmind.com
     *
     * @return string E.g., harry@upmind.com
     */
    protected function _normalizeEmail(string $email): string
    {
        if (Str::contains($email, '+')) {
            $parts = explode('@', $email, 2);
            $parts[0] = preg_replace('/\+.+/', '', $parts[0]);

            $email = implode('@', $parts);
        }

        return $email;
    }

    /**
     * Obtain a ConnectReseller compatible country name for the given alpha-2
     * ISO country code.
     *
     * @param string $countryCode E.g., GB
     *
     * @return string|null E.g., United Kingdom
     */
    protected function _countryCodeToName(string $countryCode): ?string
    {
        $countryCode = Countries::normalizeCode($countryCode);

        switch ($countryCode) {
            case 'GB':
                return 'United Kingdom'; // woof!!
            default:
                return Countries::codeToName($countryCode);
        }
    }

    /**
     * Obtain an alpha-2 ISO country code from the given ConnectReseller country
     * name.
     *
     * @param string $country E.g., United Kingdom
     *
     * @return string|null E.g., GB
     */
    protected function _countryNameToCode(string $country): ?string
    {
        switch ($country) {
            case 'United Kingdom':
                return 'GB'; // woof!!
            default:
                return Countries::nameToCode($country);
        }
    }

    /**
     * Converts a ConnectReseller timestamp from weird unix with trailing zeroes
     * to ISO-8601 format.
     *
     * @param int|string $timestamp E.g., 1683761336000
     *
     * @return string E.g., 2023-05-10 23:28:56
     */
    protected function _timestampToDateTime($timestamp): string
    {
        return Carbon::parse(intval($timestamp / 1000))->format('Y-m-d H:i:s');
    }
}
