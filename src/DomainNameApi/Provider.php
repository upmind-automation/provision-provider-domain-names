<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainNameApi;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Upmind\DomainNameApiSdk\Client as DomainNameApiSdkClient;
use Upmind\DomainNameApiSdk\ClientFactory;
use Upmind\DomainNameApiSdk\SDK\ArrayType\ArrayOfstring;
use Upmind\DomainNameApiSdk\SDK\EnumType\ContactType;
use Upmind\DomainNameApiSdk\SDK\StructType\BaseMethodResponse;
use Upmind\DomainNameApiSdk\SDK\StructType\ContactInfo;
use Upmind\DomainNameApiSdk\SDK\StructType\DisableTheftProtectionLock;
use Upmind\DomainNameApiSdk\SDK\StructType\DisableTheftProtectionLockRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\DomainInfo;
use Upmind\DomainNameApiSdk\SDK\StructType\EnableTheftProtectionLock;
use Upmind\DomainNameApiSdk\SDK\StructType\EnableTheftProtectionLockRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\GetContacts;
use Upmind\DomainNameApiSdk\SDK\StructType\GetContactsRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\GetDetails;
use Upmind\DomainNameApiSdk\SDK\StructType\GetDetailsRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\ModifyNameServer;
use Upmind\DomainNameApiSdk\SDK\StructType\ModifyNameServerRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\RegisterWithContactInfo;
use Upmind\DomainNameApiSdk\SDK\StructType\RegisterWithContactInfoRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\Renew;
use Upmind\DomainNameApiSdk\SDK\StructType\RenewRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\SaveContacts;
use Upmind\DomainNameApiSdk\SDK\StructType\SaveContactsRequest;
use Upmind\DomainNameApiSdk\SDK\StructType\Transfer;
use Upmind\DomainNameApiSdk\SDK\StructType\TransferRequest;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
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
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Data\DomainNameApiConfiguration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var DomainNameApiConfiguration
     */
    protected $configuration;

    /**
     * @var DomainNameApiSdkClient|null
     */
    protected $apiClient;

    /**
     * Max positions for nameservers
     */
    private const MAX_CUSTOM_NAMESERVERS = 4;

    /**
     * Common nameservers for DomainNameApi
     */
    private const NAMESERVERS = [
        ['host' => 'ns1.domainnameapi.com'],
        ['host' => 'ns2.domainnameapi.com']
    ];

    private const ERR_REGISTRANT_NOT_SET = 'Registrant contact details not set';

    public function __construct(DomainNameApiConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Domain Name Api')
            ->setDescription('Register, transfer, renew and manage domains, with over 700+ TLDs available')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/domainnameapi-logo.png');
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
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $ownNameServers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $ownNameServers[] = Arr::get($params, 'nameservers.ns' . $i)['host'];
            }
        }

        $nameServers = $ownNameServers ?: self::NAMESERVERS;

        $request = (new RegisterWithContactInfoRequest())
            ->setDomainName($domain)
            ->setPeriod(intval($params->renew_years))
            ->setNameServerList(new ArrayOfstring($nameServers))
            ->setLockStatus(true)
            ->setPrivacyProtectionStatus(true)
            ->setRegistrantContact($this->contactParamsToSoap($params->registrant->register))
            ->setAdministrativeContact($this->contactParamsToSoap($params->admin->register))
            ->setBillingContact($this->contactParamsToSoap($params->billing->register))
            ->setTechnicalContact($this->contactParamsToSoap($params->tech->register));

        $response = $this->api()->RegisterWithContactInfo(new RegisterWithContactInfo($request));
        $result = $response->getRegisterWithContactInfoResult();

        if ($result === null) {
            $this->errorResult('Domain registration failed');
        }

        $domainInfo = $result->getDomainInfo();

        if (!$domainInfo) {
            $this->handleApiErrorResult(
                $result,
                $result->getErrorCode() == 2302 ? 'Domain name already exists' : null
            );
        }

        return $this->domainInfoToResult($domainInfo)
            ->setMessage('Domain registered successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->getDomainResult($domainName, true)
                ->setMessage('Domain active in registrar account');
        } catch (ProvisionFunctionError $e) {
            // initiate transfer ...
        }

        $request = (new TransferRequest())
            ->setDomainName($domainName)
            ->setAuthCode($params->epp_code ?: null);
        $response = $this->api()->Transfer(new Transfer($request));
        $result = $response->getTransferResult();

        if ($result === null) {
            $this->errorResult('Domain transfer failed');
        }

        $this->assertResultSuccess($result);

        $this->errorResult('Domain transfer initiated');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        $renewRequest = (new RenewRequest())
            ->setDomainName($domain)
            ->setPeriod(intval($params->renew_years));
        $response = $this->api()->Renew(new Renew($renewRequest));
        $result = $response->getRenewResult();

        if ($result === null) {
            $this->errorResult('Domain renewal failed');
        }

        $this->assertResultSuccess($result);

        return $this->getDomainResult($domain)
            ->setMessage('Domain renewed successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        return $this->getDomainResult($domain);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $nameservers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameservers[] = Arr::get($params, 'ns' . $i)['host'];
            }
        }

        $request = (new ModifyNameServerRequest())
            ->setDomainName($domainName)
            ->setNameServerList(new ArrayOfstring($nameservers));
        $response = $this->api()->ModifyNameServer(new ModifyNameServer($request));
        $result = $response->getModifyNameServerResult();

        if ($result === null) {
            $this->errorResult('Nameservers update failed');
        }

        $this->assertResultSuccess($result);

        /** @var \Illuminate\Support\Collection $returnNameserversCollection */
        $returnNameserversCollection = collect($nameservers);

        $returnNameservers = $returnNameserversCollection
            ->mapWithKeys(fn ($ns, $i) => ['ns' . ($i + 1) => $ns])
            ->toArray();

        return NameserversResult::create($returnNameservers)
            ->setMessage('Nameservers are changed');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainInfo = $this->getDomainInfo(Utils::getDomain($params->sld, $params->tld));

        return EppCodeResult::create(['epp_code' => $domainInfo->getAuth()]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            $contactResults = $this->getContactResults($domain);
        } catch (ProvisionFunctionError $e) {
            if ($e->getMessage() !== self::ERR_REGISTRANT_NOT_SET) {
                throw $e;
            }

            $contactResults = [];
        }

        /**
         * Due to some instances of domains having no contacts whatsoever, and DomainNameApi requiring all to be passed,
         * we will fall back to the registrant contact for all contact types.
         */
        $request = (new SaveContactsRequest())
            ->setDomainName($domain)
            ->setRegistrantContact($this->contactParamsToSoap($params->contact))
            ->setAdministrativeContact($this->contactParamsToSoap($params->contact))
            ->setTechnicalContact($this->contactParamsToSoap($params->contact))
            ->setBillingContact($this->contactParamsToSoap($params->contact));
        if (isset($contactResults['admin'])) {
            $request->setAdministrativeContact(
                $this->contactParamsToSoap(new ContactParams($contactResults['admin'], false))
            );
        }
        if (isset($contactResults['tech'])) {
            $request->setTechnicalContact(
                $this->contactParamsToSoap(new ContactParams($contactResults['tech'], false))
            );
        }
        if (isset($contactResults['billing'])) {
            $request->setBillingContact(
                $this->contactParamsToSoap(new ContactParams($contactResults['billing'], false))
            );
        }

        $response = $this->api()->SaveContacts(new SaveContacts($request));
        $result = $response->getSaveContactsResult();

        if ($result === null) {
            $this->errorResult('Registrant contact update failed');
        }

        $this->assertResultSuccess($result);

        return $this->getContactResults($domain)['registrant']->setMessage('Registrant contact updated');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $domainResult = $this->getDomainResult($domainName);

        if ($domainResult->locked == $params->lock) {
            return $domainResult
                ->setMessage(sprintf('Domain already %s', $params->lock ? 'locked' : 'unlocked'));
        }

        if ($params->lock) {
            $request = (new EnableTheftProtectionLockRequest())
                ->setDomainName($domainName);
            $response = $this->api()->EnableTheftProtectionLock(new EnableTheftProtectionLock($request));
            $result = $response->getEnableTheftProtectionLockResult();
        } else {
            $request = (new DisableTheftProtectionLockRequest())
                ->setDomainName($domainName);
            $response = $this->api()->DisableTheftProtectionLock(new DisableTheftProtectionLock($request));
            $result = $response->getDisableTheftProtectionLockResult();
        }

        if ($result === null) {
            $this->errorResult('Domain lock operation failed');
        }

        $this->assertResultSuccess($result);

        return $domainResult
            ->setMessage(sprintf('Domain successfully %s', $params->lock ? 'locked' : 'unlocked'))
            ->setLocked(!!$params->lock);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getDomainResult(string $domain, bool $assertActive = false): DomainResult
    {
        return $this->domainInfoToResult($this->getDomainInfo($domain, $assertActive))
            ->setMessage('Domain info retrieved');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getDomainInfo(string $domain, bool $assertActive = false): DomainInfo
    {
        $getDetailsRequest = (new GetDetailsRequest())
            ->setDomainName($domain);
        $response = $this->api()->GetDetails(new GetDetails($getDetailsRequest));
        $result = $response->getGetDetailsResult();

        if ($result === null) {
            $this->errorResult('Domain not found');
        }

        if (!$domainInfo = $result->getDomainInfo()) {
            $this->handleApiErrorResult($result);
        }

        if ($assertActive && $domainInfo->getStatus() !== 'Active') {
            $this->errorResult(sprintf('Domain is %s', $domainInfo->getStatus()), [
                'domain' => $domain,
                'statuses' => [
                    $domainInfo->getStatus(),
                    $domainInfo->getStatusCode(),
                ]
            ]);
        }

        return $domainInfo;
    }

    /**
     * @return ContactResult[]|array<string,ContactResult>
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getContactResults(string $domainName): array
    {
        $request = (new GetContactsRequest())
            ->setDomainName($domainName);
        $response = $this->api()->GetContacts(new GetContacts($request));
        $result = $response->getGetContactsResult();

        if ($result === null) {
            $this->errorResult('Domain Contact details not found');
        }

        if (!$result->getRegistrantContact()) {
            $this->handleApiErrorResult($result, self::ERR_REGISTRANT_NOT_SET);
        }

        return [
            'registrant' => $this->contactInfoToResult($result->getRegistrantContact()),
            'billing' => $this->contactInfoToResult($result->getBillingContact()),
            'tech' => $this->contactInfoToResult($result->getTechnicalContact()),
            'admin' => $this->contactInfoToResult($result->getAdministrativeContact()),
        ];
    }

    protected function contactInfoToResult(?ContactInfo $contactInfo): ?ContactResult
    {
        if (empty($contactInfo)) {
            return null;
        }

        if ($contactInfo->getPhone()) {
            $phone = '+' . $contactInfo->getPhoneCountryCode() . $contactInfo->getPhone();
        }

        $country = $contactInfo->getCountry();
        if (!preg_match('/^[A-Z]{2}$/', strtoupper($country))) {
            $country = Utils::countryToCode($country);
        }

        return ContactResult::create($this->emptyContactValuesToNull([
            'id' => (string)$contactInfo->getId(),
            'name' => trim($contactInfo->getFirstName() . ' ' . $contactInfo->getLastName()),
            'organisation' => $contactInfo->getCompany(),
            'email' => $contactInfo->getEmail(),
            'phone' => $phone ?? null,
            'address1' => $contactInfo->getAddressLine1(),
            'city' => $contactInfo->getCity(),
            'state' => $contactInfo->getState(),
            'postcode' => $contactInfo->getZipCode(),
            'country_code' => Utils::normalizeCountryCode($country),
        ]));
    }

    protected function emptyContactValuesToNull($data): array
    {
        $empty = [
            '',
            'n/a',
        ];

        return array_map(fn ($value) => in_array($value, $empty, true) ? null : $value, $data);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function domainInfoToResult(DomainInfo $domainInfo): DomainResult
    {
        $contacts = $this->getContactResults($domainInfo->getDomainName());

        $nameServersList = $domainInfo->getNameServerList();

        // Empty array if nameServersList is null.
        /** @var \Illuminate\Support\Collection $nameServersCollection */
        $nameServersCollection = collect($nameServersList !== null ? $nameServersList->getString() : []);
        $nameservers = $nameServersCollection
            ->mapWithKeys(fn ($host, $i) => ['ns' . ($i + 1) => ['host' => $host]]);

        /** @var \Illuminate\Support\Collection $statusesCollection */
        $statusesCollection = collect([$domainInfo->getStatus() ?? 'Unknown', $domainInfo->getStatusCode()]);
        $statuses = $statusesCollection
            ->filter()
            ->map(fn($status) => ucfirst(strtolower((string)$status)))
            ->unique()
            ->values()
            ->toArray();

        return DomainResult::create([
            'id' => (string)$domainInfo->getId(),
            'domain' => $domainInfo->getDomainName(),
            'statuses' => $statuses,
            'locked' => $domainInfo->getLockStatus(),
            'registrant' => $contacts['registrant'],
            'billing' => $contacts['billing'],
            'tech' => $contacts['tech'],
            'admin' => $contacts['admin'],
            'ns' => $nameservers,
            'created_at' => $this->formatDate($domainInfo->getTransferDate() ?? $domainInfo->getStartDate()),
            'updated_at' => $this->formatDate($domainInfo->getUpdatedDate()),
            'expires_at' => $this->formatDate($domainInfo->getExpirationDate()),
        ]);
    }

    protected function formatDate(?string $date): ?string
    {
        if (!isset($date)) {
            return $date;
        }
        return Carbon::parse($date)->toDateTimeString();
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    protected function contactParamsToSoap(ContactParams $params): ContactInfo
    {
        @[$firstName, $lastName] = explode(' ', $params->name ?: $params->organisation, 2);

        $eppPhone = Utils::internationalPhoneToEpp($params->phone);
        $phoneDiallingCode = trim(Str::before($eppPhone, '.'), '+');
        $phoneNumber = Str::after($eppPhone, '.');

        $contactInfo = new ContactInfo();

        $contactInfo->setType(ContactType::VALUE_CONTACT);
        $contactInfo->setFirstName($firstName);
        $contactInfo->setLastName(empty($lastName) ? $firstName : $lastName);
        $contactInfo->setCompany($params->organisation);
        $contactInfo->setAddressLine1(trim((string)$params->address1) ?: 'N/A');
        $contactInfo->setCity(trim((string)$params->city) ?: 'N/A');
        $contactInfo->setState(strtoupper($params->country_code ?? '') === 'US' ? $params->state : null);
        $contactInfo->setZipCode(trim((string)$params->postcode) ?: 'N/A');
        $contactInfo->setCountry($params->country_code);
        $contactInfo->setEMail($params->email);
        $contactInfo->setPhoneCountryCode($phoneDiallingCode);
        $contactInfo->setPhone($phoneNumber);
        $contactInfo->setStatus('');

        return $contactInfo;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function assertResultSuccess(BaseMethodResponse $result, ?string $errorMessage = null): void
    {
        if ($result->getOperationResult() !== 'SUCCESS') {
            $this->handleApiErrorResult($result, $errorMessage);
        }
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleApiErrorResult(BaseMethodResponse $result, ?string $errorMessage = null): void
    {
        $errorMessage = $errorMessage ?: sprintf('Provider error: %s', $this->getApiErrorResultMessage($result));

        $this->errorResult($errorMessage, [
            'error_code' => $result->getErrorCode(),
            'operation_result' => $result->getOperationResult(),
            'operation_message' => $result->getOperationMessage(),
        ]);
    }

    protected function getApiErrorResultMessage(BaseMethodResponse $result): string
    {
        $message = $result->getOperationMessage() ?? 'Unknown error';

        return str_replace('Invalid api request for filed', 'Invalid api request for field', $message);
    }

    protected function api(): DomainNameApiSdkClient
    {
        if (isset($this->apiClient)) {
            return $this->apiClient;
        }

        return $this->apiClient = (new ClientFactory())->create(
            $this->configuration->username,
            $this->configuration->password,
            $this->configuration->sandbox ? ClientFactory::ENV_TEST : ClientFactory::ENV_LIVE,
            $this->getLogger()
        );
    }
}
