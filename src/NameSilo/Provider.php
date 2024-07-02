<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\NameSilo;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use SimpleXMLElement;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\NameSilo\Data\NameSiloConfiguration;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var NameSiloConfiguration
     */
    protected $configuration;

    /**
     * Array of contact ids keyed by contact data hash.
     *
     * @var string[]
     */
    protected $contactIds = [];

    /**
     * @var Client|null
     */
    protected $client;

    /**
     * Max positions for nameservers
     */
    private const MAX_CUSTOM_NAMESERVERS = 4;

    public function __construct(NameSiloConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('NameSilo')
            ->setDescription('Register, transfer, renew and manage domains with NameSilo')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/namesilo-logo.png');
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
        $this->errorResult('Polling not available for this provider');
    }

    /**
     * @throws \Throwable
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $checkedDomains = $this->_callApi([
            'domains' => $domain
        ], 'checkRegisterAvailability');

        if (isset($checkedDomains->reply->unavailable->domain)) {
            $this->errorResult('The domain is unavailable!');
        }

        $data = [
            'domain' => $domain,
            'years' => Arr::get($params, 'renew_years'),
            'private' => intval(Utils::tldSupportsWhoisPrivacy($params->tld)),
            'auto_renew' => 0,
        ];

        $contactIds = [
            'registrant' => $this->_handleContact($params->registrant),
            'administrative' => $this->_handleContact($params->admin),
            'technical' => $this->_handleContact($params->tech),
            'billing' => $this->_handleContact($params->billing),
        ];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $data['ns' . $i] = Arr::get($params, 'nameservers.ns' . $i . '.host');
            }
        }

        $this->_callApi($data, 'registerDomain');

        foreach ($contactIds as $type => $contactId) {
            $this->_associateContact($domain, (string)$contactId, $type);
        }

        $this->_addRegisteredNameServer($params);
        return $this->_getDomain($domain, 'Domain registered - ' . $domain);
    }

    /**
     * Get a contact id for the given contact params.
     *
     * @param RegisterContactParams $params
     *
     * @return string Contact id
     *
     * @throws \Throwable
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _handleContact(RegisterContactParams $params): string
    {
        if ($params->id) {
            return $params->id;
        }

        return $this->_createContact(
            $params->register->email,
            $params->register->phone,
            $params->register->name ?? $params->register->organisation,
            $params->register->organisation,
            $params->register->address1,
            $params->register->postcode,
            $params->register->city,
            $params->register->country_code,
            $params->register->state ?? '-'
        );
    }

    /**
     * @throws \Throwable
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $transferStatus = $this->getTransferStatus($domain);

        try {
            $info = $this->_getDomain($domain, 'Domain active in registrar account', false);

            if (in_array(['Active'], $info->statuses)) {
                return $info;
            }

            if ($transferStatus && $this->transferStatusInProgress($transferStatus)) {
                $this->errorResult(
                    sprintf('Domain transfer in progress since %s', $info->created_at),
                    array_merge($info, ['transfer_status' => $transferStatus])
                );
            }

            // transfer failed - proceed to initiate new transfer
        } catch (ProvisionFunctionError $e) {
            if (Str::startsWith($e->getMessage(), 'Domain transfer in progress')) {
                throw $e;
            }

            if ($transferStatus && $this->transferStatusInProgress($transferStatus)) {
                $this->errorResult('Domain transfer in progress', ['transfer_status' => $transferStatus]);
            }

            // domain does not exist - proceed to initiate transfer
        }

        // check if domain is eligible for transfer (not locked etc)
        $checkTransferAvailability = $this->_callApi(['domains' => $domain], 'checkTransferAvailability');

        if (!isset($checkTransferAvailability->reply->available->domain)) {
            $this->errorResult('Domain not eligible for transfer', [
                'availability_response' => $checkTransferAvailability,
            ]);
        }

        $this->_callApi([
            'domain' => $domain,
            'auth' => Arr::get($params, 'epp_code'),
            'contact_id' => $this->_handleContact($params->registrant ?? $params->admin),
            'auto_renew' => 0,
        ], 'transferDomain');

        $this->errorResult('Domain transfer initiated');
    }

    /**
     * Returns the transfer status of the given domain name, if any.
     *
     * @throws \Throwable
     */
    protected function getTransferStatus(string $domain): ?string
    {
        try {
            $result = $this->_callApi(['domain' => $domain], 'checkTransferStatus');

            return isset($result->reply->status) ? ((string)$result->reply->status) : null;
        } catch (ProvisionFunctionError $e) {
            return null;
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function release(IpsTagParams $params): ResultData
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $domainData = $this->_getDomain($domain, 'Domain release - ' . $domain);

        $ips_tag = Arr::get($params, 'ips_tag');
        $tag = strlen($ips_tag) === 2 ? '#' . $ips_tag : $ips_tag;
        return $this->okResult('Completed', $this->_callApi([
            'order-id' => $domainData['id'],
            'new-tag' => $tag,
        ], 'domains/uk/release.json'));
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $this->_renewDomain($domain, Arr::get($params, 'renew_years'));

        return $this->_getDomain($domain, 'The expire date is extended.');
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        return $this->_getDomain($domain);
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $this->_updateRegisteredNameServer($params);

        $domain = $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));

        return NameserversResult::create($domain->ns)
            ->setMessage('Nameservers updated');
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $eppCode = $this->_getEppCode(Utils::getDomain($params->sld, $params->tld));

        return EppCodeResult::create([
            'epp_code' => $eppCode,
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Not supported!', $params);
    }

    /**
     * @throws \Throwable
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        $domainData = $this->_callApi(
            [
                'domain' => $domainName,
            ],
            'getDomainInfo'
        );

        $contactIds = $domainData->reply->contact_ids;
        $registrantId = (string)$contactIds->registrant;

        if (in_array($registrantId, [$contactIds->administrative, $contactIds->technical, $contactIds->billing])) {
            // contact ID is shared with other contacts - create new contact
            $registrantId = $this->_createContact(
                $params->contact->email,
                $params->contact->phone,
                $params->contact->name ?: $params->contact->organisation,
                $params->contact->organisation ?: $params->contact->name,
                $params->contact->address1,
                $params->contact->postcode,
                $params->contact->city,
                $params->contact->country_code,
                $params->contact->state
            );

            $this->_associateContact($domainName, $registrantId, 'registrant');
        } else {
            $this->_updateContact(
                $registrantId,
                $params->contact->email,
                $params->contact->phone,
                $params->contact->name ?: $params->contact->organisation,
                $params->contact->organisation ?: $params->contact->name,
                $params->contact->address1,
                $params->contact->postcode,
                $params->contact->city,
                $params->contact->country_code,
                $params->contact->state
            );
        }

        return $this->_contactInfo($registrantId);
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domainData = $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));
        if ($domainData['locked'] == $params->lock) {
            return $domainData->setMessage(sprintf('Domain already %s', $domainData['locked'] ? 'locked' : 'unlocked'));
        }
        if ($params->lock == true) {
            $path = 'domainLock';
        } else {
            $path = 'domainUnlock';
        }

        $this->_callApi([
            'domain' => Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')),
        ], $path);

        return $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')))
            ->setMessage(sprintf('Domain %s', $params->lock ? 'locked' : 'unlocked'));
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        $domainData = $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')));
        if ($domainData['renew'] == $params->auto_renew) {
            $this->errorResult(sprintf('Domain already is set to %s', $domainData['renew'] ? 'auto renew' : 'handle renew'), $params);
        }
        if ($params->auto_renew == true) {
            $path = 'addAutoRenewal';
        } else {
            $path = 'removeAutoRenewal';
        }

        $this->_callApi(
            [
                'domain' => $domainName,
            ],
            $path
        );

        return $this->_getDomain($domainName)
            ->setMessage('Auto-renew mode updated');
    }

    /**
     * Returns customer data if exist
     *
     * @param string $email
     * @return array
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getContact(string $email): array
    {
        try {
            $listedContacts = $this->_callApi([], 'contactList');

            if (isset($listedContacts->reply->contact)) {
                $num = count($listedContacts->reply->contact);
                for ($i = 0; $i < $num; $i++) {
                    if ((string)$listedContacts->reply->contact[$i]->email == $email) {
                        return [
                            'contact_id' => (string)$listedContacts->reply->contact[$i]->contact_id,
                            'name' => (string)$listedContacts->reply->contact[$i]->first_name . ' ' . (string)$listedContacts->reply->contact->last_name,
                            'email' => (string)$listedContacts->reply->contact[$i]->email,
                            'phone' => (string)$listedContacts->reply->contact[$i]->phone,
                            'company' => (string)$listedContacts->reply->contact[$i]->company,
                            'address1' => (string)$listedContacts->reply->contact[$i]->address,
                            'city' => (string)$listedContacts->reply->contact->city,
                            'postcode' => (string)$listedContacts->reply->contact[$i]->zip,
                            'country_code' => (string)$listedContacts->reply->contact[$i]->country,
                            'state' => Utils::stateNameToCode((string)$listedContacts->reply->contact[$i]->country, (string)$listedContacts->reply->contact[$i]->sate),
                        ];
                    }
                }
            }
            return [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _callApiPromise(array $data, string $path): PromiseInterface
    {
        $query = array_merge(
            $data,
            [
                'version' => 1,
                'type' => 'xml',
                'key' => $this->configuration['api_key'],
            ]
        );

        return $this->http()
            ->requestAsync('GET', $path . '?' . http_build_query($query), [
                'headers' => [
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.104 Safari/537.36',
                    'accept' => 'text/xml,application/xhtml+xml,application/xml;q=0.9'
                ]
            ])
            ->then(function (ResponseInterface $response) {
                return $this->handleResponse($response);
            })
            ->otherwise(function (Throwable $e) {
                $this->handleException($e);
            });
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _callApi(array $data, string $path): SimpleXMLElement
    {
        return $this->_callApiPromise($data, $path)->wait();
    }

    /**
     * @link https://www.namesilo.com/api-reference
     *
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If we encounter an error response
     */
    protected function handleResponse(ResponseInterface $response): SimpleXMLElement
    {
        $xmlString = trim($response->getBody()->getContents());

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);

        if (empty(trim($xmlString)) || $xmlError = $this->display_xml_error(libxml_get_errors(), $xmlString)) {
            $this->errorResult(
                'Invalid Provider Response',
                ['xml_error' => $xmlError ?? 'Empty Response'],
                ['xml' => $xmlString]
            );
        }

        $code = isset($xml->reply->code) ? intval($xml->reply->code) : null;

        if ($code !== 300) {
            $message = $this->getResponseErrorMessage($code, $xml);
            $description = $this->getResponseErrorDescription($code);

            $this->errorResult(
                $message,
                ['error_code' => $code, 'error_description' => $description],
                ['xml' => $xmlString]
            );
        }

        return $xml;
    }

    /**
     * Get a customer-friendly error message from the given response data.
     *
     * @param int|null $code Error code
     * @param \SimpleXMLElement $xml Parsed XML response
     *
     * @return string Customer-friendly error message
     */
    protected function getResponseErrorMessage(?int $code, SimpleXMLElement $xml): string
    {
        $message = strval($xml->reply->detail ?? 'Unknown Error');

        // override specific messages
        switch ($code) {
            case 113:
                $message = sprintf('IP address %s has not been granted access', $xml->request->ip ?? 'UNKNOWN');
                break;
            case 119:
                $message = 'Insufficient funds';
                break;
        }

        return sprintf('Provider API Error: %s', str_ireplace('namesilo', 'provider', $message));
    }

    /**
     * Get the error description of the given API response error code.
     *
     * @link https://www.namesilo.com/api-reference Response Codes
     *
     * @param int|null $code Error code
     *
     * @return string
     */
    protected function getResponseErrorDescription(?int $code): string
    {
        switch ($code) {
            case 101:
                return 'HTTPS not used';
            case 102:
                return 'No version specified';
            case 103:
                return 'Invalid API version';
            case 104:
                return 'No type specified';
            case 105:
                return 'Invalid API type';
            case 106:
                return 'No operation specified';
            case 107:
                return 'Invalid API operation';
            case 108:
                return 'Missing parameters for the specified operation';
            case 109:
                return 'No API key specified';
            case 110:
                return 'Invalid API key';
            case 111:
                return 'Invalid User';
            case 112:
                return 'API not available to Sub-Accounts';
            case 113:
                return 'This API account cannot be accessed from your IP';
            case 114:
                return 'Invalid Domain Syntax';
            case 115:
                return 'Central Registry Not Responding - try again later';
            case 116:
                return 'Invalid sandbox account';
            case 117:
                return 'The provided credit card profile either does not exist, or is not associated with your account';
            case 118:
                return 'The provided credit card profile has not been verified';
            case 119:
                return 'Insufficient account funds for requested transaction';
            case 120:
                return 'API key must be passed as a GET';
            case 200:
                return 'Domain is not active, or does not belong to this user';
            case 201:
                return 'Internal system error';
            case 210:
                return 'General error (details provided in response)';
            case 250:
                return 'Domain is already set to AutoRenew - No update made.';
            case 251:
                return 'Domain is already set not to AutoRenew - No update made.';
            case 252:
                return 'Domain is already Locked - No update made.';
            case 253:
                return 'Domain is already Unlocked - No update made.';
            case 254:
                return 'NameServer update cannot be made. (details provided in response)';
            case 255:
                return 'Domain is already Private - No update made.';
            case 256:
                return 'Domain is already Not Private - No update made.';
            case 261:
                return 'Domain processing error (details provided in response)';
            case 262:
                return 'This domain is already active within our system and therefore cannot be processed.';
            case 263:
                return 'Invalid number of years, or no years provided.';
            case 264:
                return 'Domain cannot be renewed for specified number of years (details provided in response)';
            case 265:
                return 'Domain cannot be transferred at this time (details provided in response)';
            case 266:
                return 'No domain transfer exists for this user for this domain';
            case 267:
                return 'Invalid domain name, or we do not support the provided extension/TLD.';
            case 280:
                return 'DNS modification error';
            case 300:
                return 'Successful API operation';
            case 301:
                return 'Successful registration, but not all provided hosts were valid resulting in our nameservers being used';
            case 302:
                return 'Successful order, but there was an error with the contact information provided so your account default contact profile was used (you can configure your account to reject orders with invalid contact information via the Reseller Manager page in your account.)';
            case 400:
                return 'Existing API request is still processing - request will need to be re-submitted';
            default:
                return 'Unknown error code';
        }
    }

    /**
     * @link https://www.php.net/manual/en/function.libxml-get-errors.php#refsect1-function.libxml-get-errors-examples
     */
    protected function display_xml_error($error, $xml)
    {
        if (is_array($error)) {
            $return = '';
            foreach ($error as $e) {
                $return .= $this->display_xml_error($e, $xml);
            }
            return $return;
        }

        $return = $xml[$error->line - 1] . "\n";
        $return .= str_repeat('-', $error->column) . "^\n";

        switch ($error->level) {
            case LIBXML_ERR_WARNING:
                $return .= "Warning $error->code: ";
                break;
            case LIBXML_ERR_ERROR:
                $return .= "Error $error->code: ";
                break;
            case LIBXML_ERR_FATAL:
                $return .= "Fatal Error $error->code: ";
                break;
        }

        $return .= trim($error->message) .
            "\n  Line: $error->line" .
            "\n  Column: $error->column";

        if ($error->file) {
            $return .= "\n  File: $error->file";
        }

        return "$return\n---\n";
    }

    /**
     * @throws \Throwable
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _createContact(
        string $email,
        string $telephone,
        string $name,
        string $organization = null,
        string $address,
        string $postcode,
        string $city,
        string $countryCode,
        ?string $state
    ): string {
        $lastName = '';
        $nameParts = explode(' ', $name);
        if (isset($nameParts[1])) {
            $lastName = $nameParts[1];
        }
        $firstName = $nameParts[0];
        if (!$lastName) {
            $lastName = $firstName;
        }

        $data = [
            'em' => $email,
            'fn' => $firstName,
            'ln' => $lastName,
            'cp' => $organization,
            'ad' => $address,
            'cy' => $city,
            'st' => Utils::stateNameToCode($countryCode, $state) ?: '-',
            'ct' => Utils::normalizeCountryCode($countryCode),
            'zp' => $postcode,
            'ph' => Utils::internationalPhoneToEpp($telephone),
        ];

        $contact = $this->_callApi($data, 'contactAdd');

        return (string)$contact->reply->contact_id;
    }

    /**
     * @throws \Throwable
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _updateContact(
        string $contactId,
        string $email,
        string $telephone,
        string $name,
        string $organization = null,
        string $address,
        string $postcode,
        string $city,
        string $countryCode,
        ?string $state = null
    ): void {
        unset($this->contactIds[$contactId]);

        $lastName = '';
        $nameParts = explode(' ', $name);
        if (isset($nameParts[1])) {
            $lastName = $nameParts[1];
        }
        $firstName = $nameParts[0];
        if (!$lastName) {
            $lastName = $firstName;
        }

        $data = [
            'contact_id' => $contactId,
            'em' => $email,
            'fn' => $firstName,
            'ln' => $lastName,
            'cp' => $organization,
            'ad' => $address,
            'cy' => $city,
            'st' => Utils::stateNameToCode($countryCode, $state) ?: '-',
            'ct' => Utils::normalizeCountryCode($countryCode),
            'zp' => $postcode,
            'ph' => Utils::internationalPhoneToEpp($telephone),
        ];

        $this->_callApi($data, 'contactUpdate');
    }

    /**
     * @return no-return
     *
     * @throws \Throwable If error is completely unexpected
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                /** @var \Psr\Http\Message\ResponseInterface $response */
                $response = $e->getResponse();

                $httpCode = $response->getStatusCode();
                $reason = $response->getReasonPhrase();
                $responseBody = $response->getBody()->__toString();
            }

            $this->errorResult(
                'Provider API request failed',
                [
                    'http_code' => $httpCode ?? null,
                    'reason' => $reason ?? null,
                ],
                [
                    'response_body' => $responseBody ?? null
                ],
                $e
            );
        }

        // totally unexpected error - re-throw and let provision system handle
        throw $e;
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getDomain(
        string $domainName,
        string $msg = 'Domain data retrieved',
        bool $assertActive = true
    ): DomainResult {
        $domainData = $this->_callApi(
            [
                'domain' => $domainName,
            ],
            'getDomainInfo'
        );

        $ns = [];
        $nameServicesCount = count($domainData->reply->nameservers->nameserver);

        for ($i = 0; $i < $nameServicesCount; $i++) {
            if (isset($domainData->reply->nameservers->nameserver[$i])) {
                $ns['ns' . ($i + 1)] = [
                    'host' => (string)$domainData->reply->nameservers->nameserver[$i],
                ];
            }
        }

        $contacts = $this->_allContactInfo(
            (string)$domainData->reply->contact_ids->registrant,
            (string)$domainData->reply->contact_ids->billing,
            (string)$domainData->reply->contact_ids->administrative,
            (string)$domainData->reply->contact_ids->technical
        );

        $info = DomainResult::create([
            'id' => 'N/A',
            'domain' => $domainName,
            'statuses' => [(string)$domainData->reply->status],
            'locked' => ((string) $domainData->reply->locked) == 'Yes' ? true : false,
            'renew' => ((string) $domainData->reply->auto_renew) == 'Yes' ? true : false,
            'registrant' => $contacts['registrant'],
            'billing' => $contacts['billing'],
            'admin' => $contacts['administrative'],
            'tech' => $contacts['technical'],
            'ns' => $ns,
            'created_at' => Utils::formatDate((string)$domainData->reply->created),
            'updated_at' => null,
            'expires_at' => Utils::formatDate((string)$domainData->reply->expires),
        ])->setMessage($msg);

        if ($assertActive && !in_array('Active', $info->statuses)) {
            $this->errorResult('Domain name not active', $info->toArray());
        }

        return $info;
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getEppCode(string $domainName): string
    {
        $domainData = $this->_callApi(
            [
                'domain' => $domainName,
            ],
            'retrieveAuthCode'
        );

        return 'The authorization code has been sent to the admin contact';
    }

    /**
     * @throws \Throwable
     */
    protected function _parseContactInfo(array $contact): ContactResult
    {
        return ContactResult::create(array_map(fn ($value) => in_array($value, ['', '-'], true) ? null : $value, [
            'id' => $contact['contact_id'],
            'name' => $contact['first_name'] . ' ' . $contact['last_name'],
            'email' => $contact['email'],
            'phone' => Utils::localPhoneToInternational($contact['phone'], $contact['country'], false),
            'organisation' => !empty($contact['company']) ? $contact['company'] : '',
            'address1' => $contact['address'],
            'city' => $contact['city'],
            'state' => Utils::stateCodeToName($contact['country'], $contact['state']),
            'postcode' => $contact['zip'],
            'country_code' => $contact['country'],
        ]));
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _contactInfoPromise(string $contactId): PromiseInterface
    {
        if (isset($this->contactIds[$contactId])) {
            return new FulfilledPromise($this->contactIds[$contactId]);
        }

        return $this->_callApiPromise(
            ['contact_id' => $contactId],
            'contactList'
        )->then(function (SimpleXMLElement $contactData) use ($contactId) {
            $contactJson = json_encode($contactData->reply->contact);
            return $this->contactIds[$contactId] = $this->_parseContactInfo(json_decode($contactJson, true));
        });
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _contactInfo(string $contactID): ContactResult
    {
        return $this->_contactInfoPromise($contactID)->wait();
    }

    /**
     * @return ContactResult[] [registrant, billing, administrative, technical]
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _allContactInfo(string $registrantId, string $billingId, string $adminId, string $techId): array
    {
        /** @var \Illuminate\Support\Collection $functionArgumentsCollection */
        $functionArgumentsCollection = collect(func_get_args());

        $contacts = $functionArgumentsCollection->unique()->mapWithKeys(function ($contactId) {
            return [$contactId => $this->_contactInfo($contactId)];
        });

        $promises = [
            'registrant' => $contacts->get($registrantId),
            'billing' => $contacts->get($billingId),
            'administrative' => $contacts->get($adminId),
            'technical' => $contacts->get($techId),
        ];

        return PromiseUtils::all($promises)->wait();
    }

    protected function formatDate(?string $date): ?string
    {
        if (!isset($date)) {
            return $date;
        }
        return Carbon::parse((int) $date)->toDateTimeString();
    }

    /**
     * Renew
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _renewDomain(string $domainName, int $renew_years): void
    {
        $this->_callApi(
            [
                'domain' => $domainName,
                'years' => $renew_years,
                'invoice-option' => 'NoInvoice',
            ],
            'renewDomain'
        );
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

    protected function http(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        return $this->client = new Client([
            'base_uri' => 'https://www.namesilo.com/api/',
            'handler' => $this->getGuzzleHandlerStack(),
        ]);
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _associateContact(string $domain, string $contactId, string $associateType): void
    {
        $this->_callApi([
            'domain' => $domain,
            $associateType => $contactId,
        ], 'contactDomainAssociate');
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     *
     * @phpstan-ignore method.unused
     */
    private function _getRegisteredNameServers(string $domainName): \SimpleXMLElement
    {
        return $this->_callApi([
            'domain' => $domainName,
        ], 'listRegisteredNameServers');
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _addRegisteredNameServer(RegisterDomainParams $params)
    {
        $data = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $domainParts = explode('.', Arr::get($params, 'nameservers.ns' . $i . '.host'));
                $tld = array_pop($domainParts);
                $data['domain'] = array_pop($domainParts) . '.' . $tld;
                $data['new_host'] = implode('.', $domainParts);
                if (!Arr::has($params, 'nameservers.ns' . $i . '.ip') || Arr::get($params, 'nameservers.ns' . $i . '.ip') == null) {
                    continue;
                }
                $data['ip1'] = Arr::get($params, 'nameservers.ns' . $i . '.ip');
                $this->_callApi(
                    $data,
                    'addRegisteredNameServer'
                );
            }
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _updateRegisteredNameServer(UpdateNameserversParams $params)
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        $data = [];
        $data['domain'] = $domain;
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $data['ns' . $i] = Arr::get($params, 'ns' . $i . '.host');
            }
        }
        $this->_callApi(
            $data,
            'changeNameServers'
        );
    }

    /**
     * @phpstan-ignore method.unused
     */
    private function mapType(string $status): ?string
    {
        switch ($status) {
            case 'Transfer Rejected':
                return DomainNotification::TYPE_SUSPENDED;
            case 'Transfer Completed':
            case 'Transfer Accepted':
                return DomainNotification::TYPE_TRANSFER_IN;
            case 'Pending Reply from Administrative Contact':
            case 'Pending at Registry':
            case 'Domain has a pendingTransfer status':
                return DomainNotification::TYPE_TRANSFER_OUT;
            case 'Domain has a pendingDelete status':
                return DomainNotification::TYPE_DELETED;
        }

        return null;
    }

    protected function transferStatusInProgress(string $status): bool
    {
        return in_array($status, [
            'Retrieving Administrative Contact Email',
            'Pending Reply from Administrative Contact',
            'Transfer Accepted',
            'Pending at Registry',
            'Approved',
            'Approved (Auto)',
            'Transfer Completed',
            'Checking Domain Status',
            'Retrieving Administrative Contact Email (2)',
            'Submitting Transfer Request to Registry',
            'Domain has a pendingTransfer status',
        ]);
    }

    protected function transferStatusRejected(string $status): bool
    {
        return in_array($status, [
            'Missing Authorization Code',
            'Transfer Rejected',
            'Transfer Timed Out',
            'Registry Transfer Request Failed',
            'Registrar Rejected',
            'Incorrect Authorization Code',
            'Domain is Locked',
            'Domain is Private',
            'On Hold - Created in last 60 days',
            'On Hold - Transferred in last 60 days',
            'Registry Rejected',
            'Domain Transferred Elsewhere',
            'User Cancelled',
            'Domain has a pendingDelete status',
            'Time Out',
        ]);
    }
}
