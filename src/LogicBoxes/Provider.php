<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\LogicBoxes;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\LogicBoxes\Data\Configuration;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * Array of contact ids keyed by contact data hash.
     *
     * @var array<string, int>
     */
    protected $contactIds = [];

    /**
     * Max positions for nameservers
     */
    private const MAX_CUSTOM_NAMESERVERS = 4;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('LogicBoxes')
            ->setDescription(
                'LogicBoxes offers 800+ gTLDs, ccTLDs and new domains at a highly competitive price point'
            )
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/logicboxes-logo.png');
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
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        $privacy = Utils::tldSupportsWhoisPrivacy($params->tld) && $params->whois_privacy;

        $data = [
            'domain-name' => $domain,
            'years' => Arr::get($params, 'renew_years'),
            'invoice-option' => 'NoInvoice',
            'purchase-privacy' => $privacy,
            'protect-privacy' => $privacy,
            'auto-renew' => false,
        ];

        $contacts = $params->toArray();
        $data['customer-id'] = $this->_getCustomerId($contacts, 'registrant');
        $data['reg-contact-id'] = $this->_handelContact($contacts, 'registrant', $data['customer-id'], $params->tld);
        $data['admin-contact-id'] = $this->_handelContact($contacts, 'admin', $data['customer-id'], $params->tld);
        $data['tech-contact-id'] = $this->_handelContact($contacts, 'tech', $data['customer-id'], $params->tld);
        $data['billing-contact-id'] = $this->_handelContact($contacts, 'billing', $data['customer-id'], $params->tld);

        // Determine which name servers to use
        $ownNameServers = null;

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'nameservers.ns' . $i)) {
                $ownNameServers[] = Arr::get($params, 'nameservers.ns' . $i . '.host');
            }
        }

        // Use the default name servers in case we didn't provide our own
        if (!is_null($ownNameServers)) {
            $data['ns'] = $ownNameServers;
        } else {
            $data['ns'] = $this->_getCustomerNameServers($data['customer-id']); // Must start coming from the customer!
        }

        $this->_callApi($data, 'domains/register.json');
        $result = $this->_getDomain($domain, 'Domain registered - ' . $domain);

        return $result;
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _handelContact(array $params, string $type, string $customerId, string $tld)
    {
        if (!$this->tldHasContactType($tld, $type)) {
            return -1;
        }

        if (Arr::has($params, $type . '.id')) {
            $contactID = Arr::get($params, $type . '.id');
        } else {
            $contactID = $this->_createContact(
                Arr::get($params, $type . '.register.email'),
                Arr::get($params, $type . '.register.phone'),
                Arr::get($params, $type . '.register.name', Arr::get($params, $type . '.register.organisation')),
                Arr::get($params, $type . '.register.organisation', Arr::get($params, $type . '.register.name')),
                Arr::get($params, $type . '.register.address1'),
                Arr::get($params, $type . '.register.postcode'),
                Arr::get($params, $type . '.register.city'),
                Arr::get($params, $type . '.register.country_code'),
                $customerId,
                $type,
                $tld
            );
        }

        return $contactID;
    }

    /**
     * @link https://manage.logicboxes.com/kb/answer/752
     */
    protected function tldHasContactType(string $tld, string $contactType): bool
    {
        $tld = Utils::getRootTld($tld);

        if ($contactType === 'registrant') {
            return true;
        }

        if ($contactType === 'admin') {
            return !in_array($tld, ['eu', 'nz', 'ru', 'uk']);
        }

        if ($contactType === 'tech') {
            return !in_array($tld, ['eu', 'fr', 'nz', 'ru', 'uk']);
        }

        if ($contactType === 'billing') {
            return !in_array($tld, ['at', 'berlin', 'ca', 'eu', 'fr', 'nl', 'nz', 'ru', 'uk', 'london']);
        }

        // unknown contact type
        return false;
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _handelContactId(array $params)
    {
        if (Arr::has($params, 'id')) {
            $contactID = Arr::get($params, 'id');
        } else {
            $contactID = $this->_addContact(
                Arr::get($params, 'customer_id'),
                Arr::get($params, 'email'),
                Arr::get($params, 'phone'),
                Arr::get($params, 'name', Arr::get($params, 'organisation')),
                Arr::get($params, 'organisation', Arr::get($params, 'name')),
                Arr::get($params, 'address1'),
                Arr::get($params, 'postcode'),
                Arr::get($params, 'city'),
                Arr::get($params, 'country_code')
            );
        }

        return $contactID;
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getCustomerNameServers(string $customerId): array
    {
        $data = [
            'customer-id' => $customerId
        ];

        return $this->_callApi($data, 'domains/customer-default-ns.json', 'GET');
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getCustomerId(array $params, string $type): string
    {
        $customer = $this->_getCustomer(Arr::get($params, $type . '.register.email'));
        if (count($customer) >= 1) {
            return $customer['customerid'];
        }

        return (string) $this->_createCustomer(
            Arr::get($params, $type . '.register.email'),
            Arr::get($params, $type . '.register.phone'),
            Arr::get($params, $type . '.register.name', Arr::get($params, $type . '.register.organisation')),
            Arr::get($params, $type . '.register.organisation', Arr::get($params, $type . '.register.name')),
            Arr::get($params, $type . '.register.address1'),
            Arr::get($params, $type . '.register.postcode'),
            Arr::get($params, $type . '.register.city'),
            Arr::get($params, $type . '.register.country_code')
        );
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));
        $privacy = Utils::tldSupportsWhoisPrivacy($params->tld) && $params->whois_privacy;

        try {
            // check to see if domain is already active in the account
            return $this->_getDomain($domain, 'Domain active in registrar account');
        } catch (ProvisionFunctionError $e) {
            if (Str::contains(strtolower($e->getMessage()), 'transfer')) {
                // transfer already in progress - stop here
                throw $e;
            }

            // initiate transfer...
        }

        // check if domain is eligible for transfer (not locked etc)
        if (false === $this->_callApi(['domain-name' => $domain], 'domains/validate-transfer.json', 'GET')) {
            $this->errorResult('Domain is not currently transferrable');
        }

        // initiate the transfer
        $contacts = $params->toArray();
        $customerId = $this->_getCustomerId($contacts, 'registrant');
        $response = $this->_callApi([
            'domain-name' => $domain,
            'auth-code' => Arr::get($params, 'epp_code'),
            'customer-id' => $customerId,
            'reg-contact-id' => $this->_handelContact($contacts, 'registrant', $customerId, $params->tld),
            'admin-contact-id' => $this->_handelContact($contacts, 'admin', $customerId, $params->tld),
            'tech-contact-id' => $this->_handelContact($contacts, 'tech', $customerId, $params->tld),
            'billing-contact-id' => $this->_handelContact($contacts, 'billing', $customerId, $params->tld),
            'invoice-option' => 'NoInvoice',
            'purchase-privacy' => $privacy,
            'protect-privacy' => $privacy,
            'auto-renew' => false,
        ], 'domains/transfer.json', 'POST');

        if (isset($response['actionstatus']) && $response['actionstatus'] === 'Failed') {
            $this->errorResult('Transfer initiation failed: ' . $response['actionstatusdesc']);
        }

        /**
         * In case of an error, a status key with value as ERROR along with an error message will be returned.
         * However, if the transfer action is waiting on user input or registry response, the value NoError will be returned.
         */
        if ($response == 'NoError') {
            $this->errorResult('Transfer awaiting owner or registry approval');
        }

        try {
            // check to see if domain transferred instantly
            return $this->_getDomain($domain, 'Domain transferred successfully - ' . $domain);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult('Domain transfer initiated', [], [], $e);
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
        $tag = strlen($ips_tag) == 2 ? '#' . $ips_tag : $ips_tag;
        return $this->okResult('Completed', $this->_callApi([
            'order-id' => $domainData['id'],
            'new-tag' => $tag,
        ], 'domains/uk/release.json', 'POST'));
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $newExpiry = $this->_renewDomain($domain, Arr::get($params, 'renew_years'));

        return $this->_getDomain($domain, 'Domain renewed successfully')
            ->setExpiresAt($newExpiry);
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
        $domain = Utils::getDomain($params->sld, $params->tld);
        $domainData = $this->_getDomain($domain);

        $nameservers = [Arr::get($params, 'ns1')['host']];
        // if (!is_null(Arr::get($params, 'ns1.ip'))) {
        //     $this->_callApi([
        //         'order-id' => $domainData['id'],
        //         'cns' => Arr::get($params, 'ns1.host'),
        //         'ip' => Arr::get($params, 'ns1.ip'),
        //     ], 'domains/add-cns.json');
        // }
        $nameservers[] = Arr::get($params, 'ns2')['host'];
        if (Arr::get($params, 'ns3')) {
            $nameservers[] = Arr::get($params, 'ns3')['host'];
        }
        if (Arr::get($params, 'ns4')) {
            $nameservers[] = Arr::get($params, 'ns4')['host'];
        }

        $this->_callApi([
            'order-id' => $domainData['id'],
            'ns' => $nameservers,
        ], 'domains/modify-ns.json');

        $domainData = $this->_getDomain($domain);

        return NameserversResult::create($domainData->ns)
            ->setMessage('Nameservers are changed');
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
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        return $this->release($params);
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $domainData = $this->_getDomainData($domainName);

        $contactData = $params->contact->toArray();
        $contactData['customer_id'] = $domainData['customerid'];
        $contactId = $this->_handelContactId($contactData);

        $this->_callApi([
            'order-id' => $domainData['entityid'],
            'reg-contact-id' => $contactId,
            'admin-contact-id' => $domainData['admincontact']['contactid'] ?? -1,
            'tech-contact-id' => $domainData['techcontact']['contactid'] ?? -1,
            'billing-contact-id' => $domainData['billingcontact']['contactid'] ?? -1,
        ], 'domains/modify-contact.json', 'POST');

        return new ContactResult($this->_contactInfo($contactId));
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
            $this->_callApi([
                'order-id' => $domainData['id'],
            ], 'domains/enable-theft-protection.json');
        } else {
            $this->_callApi([
                'order-id' => $domainData['id'],
            ], 'domains/disable-theft-protection.json');
        }

        return $this->_getDomain(Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld')))
            ->setMessage(sprintf('Domain %s', $params->lock ? 'locked' : 'unlocked'));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Not supported!', $params);
    }

    /**
     * Used for profile data changes.
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateContact(UpdateDomainContactParams $params): ResultData
    {
        $contactInfo = $this->_contactInfo(Arr::get($params, 'contact_id'));

        $newContactData = Arr::get($params, 'contact', []);
        if (Arr::has($newContactData, 'name')) {
            if ($contactInfo['name'] == $contactInfo['organisation'] && !Arr::has($newContactData, 'organisation')) {
                $name = Arr::get($newContactData, 'name');
                $organisation = Arr::get($newContactData, 'name');
            } else {
                $name = Arr::get($newContactData, 'name');
                $organisation = Arr::get($newContactData, 'organisation', $contactInfo['organisation']);
            }
        } elseif (Arr::has($newContactData, 'organisation')) {
            if ($contactInfo['name'] == $contactInfo['organisation']) {
                $name = Arr::get($newContactData, 'organisation');
                $organisation = Arr::get($newContactData, 'organisation');
            } else {
                $name = Arr::get($newContactData, 'name', $contactInfo['name']);
                $organisation = Arr::get($newContactData, 'organisation');
            }
        } else {
            $name = $contactInfo['name'];
            $organisation = $contactInfo['organisation'];
        }

        $this->_updateContact(
            Arr::get($params, 'contact_id'),
            Arr::get($newContactData, 'email', $contactInfo['email']),
            Arr::get($newContactData, 'phone', $contactInfo['phone']),
            $name,
            $organisation,
            Arr::get($newContactData, 'address1', $contactInfo['address1']),
            Arr::get($newContactData, 'postcode', $contactInfo['postcode']),
            Arr::get($newContactData, 'city', $contactInfo['city']),
            Arr::get($newContactData, 'country_code', $contactInfo['country_code'])
        );

        return $this->okResult('Contact data.', $this->_contactInfo(Arr::get($params, 'contact_id')));
    }

    /**
     * Creates contact and returns its ID or `null` for error
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
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
        string $customerId,
        string $type,
        string $tld
    ): int {
        if ($telephone) {
            $telephone = Utils::internationalPhoneToEpp($telephone);
            $phone = phone($telephone);
            $phoneCode = $phone->getPhoneNumberInstance()->getCountryCode();
            $phone = $phone->getPhoneNumberInstance()->getNationalNumber();
        } else {
            $phoneCode = '';
            $phone = '';
        }

        $contactHash = sha1(json_encode(compact(
            'email',
            'telephone',
            'name',
            'organization',
            'address',
            'postcode',
            'city',
            'countryCode'
        )));
        if (isset($this->contactIds[$contactHash])) {
            return $this->contactIds[$contactHash];
        }

        $data = [
            'email' => $email,
            'name' => $name,
            'company' => $organization,
            'address-line-1' => $address,
            'city' => $city,
            'country' => Utils::normalizeCountryCode($countryCode),
            'zipcode' => $postcode,
            'phone-cc' => $phoneCode,
            'phone' => $phone,
            'type' => $this->getTldContactType($tld, $type),
            'customer-id' => $customerId,
        ];

        // returns the contact id
        return $this->contactIds[$contactHash] = $this->_callApi($data, 'contacts/add.json');
    }

    /**
     * @link https://manage.logicboxes.com/kb/answer/790
     * @link https://manage.logicboxes.com/kb/answer/752
     */
    protected function getTldContactType(string $tld, string $type): string
    {
        $tld = Utils::getRootTld($tld);

        if ($tld === 'br') {
            return $type === 'registrant' ? 'BrOrgContact' : 'BrContact';
        }

        $map = [
            'at' => 'AtContact',
            'ca' => 'CaContact',
            'cl' => 'ClContact',
            'cn' => 'CnContact',
            'co' => 'CoContact',
            // ToDo: Remove dupe entry if not needed and was added by mistake.
//            'co' => 'CoopContact', This is a dupe entry, and the first mapping will be used.
            'de' => 'DeContact',
            'es' => 'EsContact',
            'eu' => 'EuContact',
            'fr' => 'FrContact',
            'mx' => 'MxContact',
            'nl' => 'NlContact',
            'ny' => 'NycContact',
            'uk' => 'UkContact',
            'ru' => 'RuContact',
        ];

        return $map[$tld] ?? 'Contact';
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _addContact(
        string $customerId,
        string $email,
        string $telephone,
        string $name,
        string $organization = null,
        string $address,
        string $postcode,
        string $city,
        string $countryCode
    ): int {
        if ($telephone) {
            $telephone = Utils::internationalPhoneToEpp($telephone);
            $phone = phone($telephone);
            $phoneCode = $phone->getPhoneNumberInstance()->getCountryCode();
            $phone = $phone->getPhoneNumberInstance()->getNationalNumber();
        } else {
            $phoneCode = '';
            $phone = '';
        }

        $data = [
            'email' => $email,
            'name' => $name,
            'company' => $organization,
            'address-line-1' => $address,
            'city' => $city,
            'country' => Utils::normalizeCountryCode($countryCode),
            'zipcode' => $postcode,
            'phone-cc' => $phoneCode,
            'phone' => $phone,
            'type' => 'Contact',
            'customer-id' => $customerId,
        ];

        return $this->_callApi($data, 'contacts/add.json'); // Expected integer - contact_id
    }

    /**
     * Returns customer data if exist
     *
     * @param string $email
     * @return array
     *
     * @throws \Throwable
     */
    protected function _getCustomer(string $email): array
    {
        $data = [
            'username' => $email
        ];

        try {
            return $this->_callApi($data, 'customers/details.json', 'GET');
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * @link https://manage.logicboxes.com/kb/servlet/KBServlet/faq489.html#password
     */
    protected function _generateRandomPassword(): string
    {
        $special = '~*!@$#%_+.?:,{}';
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $characters = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $charactersLength = strlen($characters);
        $specialLength = strlen($special);
        $upperLength = strlen($upper);
        $numbersLength = strlen($numbers);
        $randomString = $special[rand(0, $specialLength - 1)];
        $randomString .= $upper[rand(0, $upperLength - 1)];
        for ($i = 0; $i < 3; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
            $randomString .= $numbers[rand(0, $numbersLength - 1)];
        }
        $randomString .= $special[rand(0, $specialLength - 1)];
        $randomString .= $upper[rand(0, $upperLength - 1)];
        return $randomString;
    }

    /**
     * Creates contact and returns its ID or `null` for error
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _createCustomer(
        string $email,
        string $telephone,
        string $name,
        string $organization = null,
        string $address,
        string $postcode,
        string $city,
        string $countryCode
    ): int {
        if ($telephone) {
            $telephone = Utils::internationalPhoneToEpp($telephone);
            $phone = phone($telephone);
            $phoneCode = $phone->getPhoneNumberInstance()->getCountryCode();
            $phone = $phone->getPhoneNumberInstance()->getNationalNumber();
        } else {
            $phoneCode = '';
            $phone = '';
        }
        $data = [
            'username' => $email,
            'passwd' => $this->_generateRandomPassword(),
            'name' => $name,
            'company' => $organization,
            'address-line-1' => $address,
            'city' => $city,
            'state' => '-',
            'country' => Utils::normalizeCountryCode($countryCode),
            'zipcode' => $postcode,
            'phone-cc' => $phoneCode,
            'phone' => $phone,
            'lang-pref' => 'en'
        ];

        return $this->_callApi($data, 'customers/v2/signup.json'); // Expected integer - contact_id
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _callApi(array $data, string $path, string $method = 'POST')
    {
        if ($this->configuration['sandbox']) {
            $url = 'https://test.httpapi.com/api/';
        } else {
            $url = 'https://httpapi.com/api/';
        }
        $url .= $path;

        $client = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'multipart/form-data',
            ],
            'http_errors' => true,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        $query = array_merge(
            $data,
            ['auth-userid' => $this->configuration['reseller_id'], 'api-key' => $this->configuration['api_key']]
        );
        $query = preg_replace('/\%5B\d+\%5D/', '', http_build_query($query));

        try {
            switch (strtoupper($method)) {
                case 'GET':
                case 'DELETE': //fall-through
                    /** @var \GuzzleHttp\Psr7\Response $response */
                    $response = $client->request($method, $url, [
                        'query' => $query,
                    ]);
                    break;
                default:
                    /** @var \GuzzleHttp\Psr7\Response $response */
                    $response = $client->request($method, $url, [
                        'query' => $query,
                        // 'body' => $query,
                    ]);
                    break;
            }

            $responseData = $this->getResponseData($response);

            if (isset($responseData['status'])) {
                $status = strtolower($responseData['status']);
                if (in_array($status, ['error', 'failed'])) {
                    $errorMessage = $this->getResponseErrorMessage($response, $responseData);

                    $this->errorResult(
                        sprintf('Provider API %s: %s', $status, $errorMessage),
                        ['response_data' => $responseData],
                    );
                }
            }

            return $responseData;
        } catch (Throwable $e) {
            $this->handleException($e);
        }
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
     */
    protected function getResponseErrorMessage(Response $response, $responseData): string
    {
        $errorMessage = trim($responseData['message'] ?? $responseData['error'] ?? 'unknown error');

        // sometimes failed actions arent returned like other errors
        if (!empty($responseData['actionstatus']) && !empty($responseData['actionstatusdesc'])) {
            if ($responseData['actionstatus'] === 'Failed') {
                $errorMessage = $responseData['actionstatusdesc'];
            }
        }

        // only return the first sentence of the error message
        // $errorMessage = preg_replace('/\. .+$/', '', $errorMessage); // problematic if input string contains a .

        // neaten up validation errors
        if (preg_match('/^\{\w+=([^}]+)\}$/', $errorMessage)) {
            // remove weird-ass curly braces
            $errorMessage = trim($errorMessage, '{}');

            // remove attribute names from error messages
            $errorMessage = preg_replace('/\w+=(?=\w+ )/', '', $errorMessage);

            // ucfirst each error message
            /** @var \Illuminate\Support\Collection $errorMessageCollection */
            $errorMessageCollection = collect(explode(', ', $errorMessage));
            $errorMessage = $errorMessageCollection
                ->map(function ($message) {
                    return ucfirst($message);
                })
                ->implode('; ');
        }

        // override confusing "not found" error message
        if (Str::startsWith($errorMessage, 'Website doesn\'t exist')) {
            $errorMessage = 'Domain name not found';
        }

        // override "not registered" error message
        if (Str::contains($errorMessage, 'is currently available for Registration')) {
            $errorMessage = 'Domain is not registered';
        }

        // cloudflare response?
        if (empty($responseData) && $response->getStatusCode() === 403) {
            $errorMessage = 'Forbidden - check IP whitelisting';
        }

        return $errorMessage;
    }

    /**
     * @return no-return
     *
     * @throws \Throwable If error is completely unexpected
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleException(Throwable $e): void
    {
        if (($e instanceof RequestException) && $e->hasResponse()) {
            /** @var \Psr\Http\Message\ResponseInterface&\GuzzleHttp\Psr7\Response $response */
            $response = $e->getResponse();

            // text/plain responses
            if (Str::contains($response->getHeaderLine('Content-Type'), 'text/plain')) {
                $body = trim($response->getBody()->__toString());

                // check for error codes
                if (preg_match('/error code: (\d+)/i', $body, $matches)) {
                    switch ($matches[1]) {
                        case "1020":
                            $this->errorResult(
                                'Provider API rejected our request - please review whitelisted IPs',
                                [],
                                ['response_body' => $body],
                                $e
                            );
                        default:
                            $this->errorResult(
                                sprintf('Unexpected provider API error: %s', $matches[1]),
                                [],
                                ['response_body' => $body],
                                $e
                            );
                    }
                }
            }

            // application/json responses
            $responseData = $this->getResponseData($response);

            $status = strtolower($responseData['status'] ?? 'error');
            $errorMessage = $this->getResponseErrorMessage($response, $responseData);

            $this->errorResult(
                sprintf('Provider API %s: %s', ucfirst($status), $errorMessage),
                ['response_data' => $responseData],
                [],
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
        ?string $msg = 'Domain data retrieved',
        bool $assertActive = true
    ): DomainResult {
        $domainData = $this->_getDomainData($domainName);
        $msg = $msg ?: 'Domain data retrieved';

        $ns = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $nsI) {
            if (isset($domainData[$nsI])) {
                $ns[$nsI] = [
                    'host' => $domainData[$nsI],
                ];
            }
        }

        $privacy = $domainData['isprivacyprotected'] ?? null;
        if ($privacy === 'true') {
            $privacy = true;
        } elseif ($privacy === 'false') {
            $privacy = false;
        } elseif ($privacy !== null) {
            $privacy = (bool) $privacy;
        }

        $autoRenew = $domainData['recurring'] ?? null;
        if ($autoRenew === 'true') {
            $autoRenew = true;
        } elseif ($autoRenew === 'false') {
            $autoRenew = false;
        } elseif ($autoRenew !== null) {
            $autoRenew = (bool) $autoRenew;
        }

        $datetimeCreated = $domainData['creationtime'] ?? null; // On new order might be missing
        $datetimeEnd = $domainData['endtime'] ?? null; // On new order might be missing
        $info = DomainResult::create([
            'id' => $domainData['entityid'],
            'domain' => $domainData['domainname'],
            'statuses' => array_merge([$domainData['currentstatus']], $domainData['domainstatus']),
            'locked' => in_array('transferlock', $domainData['orderstatus']) ? true : false,
            'whois_privacy' => $privacy,
            'auto_renew' => $autoRenew,
            'registrant' => $this->_parseContactInfo($domainData['registrantcontact']),
            'ns' => $ns,
            'created_at' => $this->formatDate($datetimeCreated),
            'updated_at' => $this->formatDate($datetimeCreated) ?? $this->formatDate($datetimeCreated),
            'expires_at' => $this->formatDate($datetimeEnd),
        ])
            ->setMessage($msg);

        if ($assertActive && $domainData['currentstatus'] !== 'Active') {
            $message = 'Domain is not active';

            if (isset($domainData['actionstatusdesc'])) {
                $message .= ' - ' . $domainData['actionstatusdesc'];
            }

            if (isset($domainData['actiontype']) && $domainData['actiontype'] === 'AddTransferDomain') {
                // transfer in progress
                $message = 'Domain transfer in progress';

                if ($initiatedTimestamp = $domainData['executioninfoparams']['invoicepaidtime'] ?? null) {
                    $initiated = CarbonImmutable::parse(intval($initiatedTimestamp));
                }

                if (isset($initiated) && $initiated->addDays(7)->greaterThan(Carbon::now())) {
                    $message .= ' since ' . $initiated->diffForHumans();
                } elseif ($domainData['actionstatusdesc']) {
                    $message .= ' - ' . $domainData['actionstatusdesc'];
                }
            }

            $this->errorResult(
                $message,
                $info->toArray(),
                ['response_data' => $domainData]
            );
        }

        return $info;
    }

    /**
     * Get domain details by domain name.
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getDomainData(string $domainName): array
    {
        return $this->_callApi(
            [
                'domain-name' => $domainName,
                'options' => 'All',
            ],
            'domains/details-by-name.json',
            'GET'
        );
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getEppCode(string $domainName): string
    {
        $domainData = $this->_callApi(
            [
                'domain-name' => $domainName,
                'options' => 'OrderDetails',
            ],
            'domains/details-by-name.json',
            'GET'
        );

        return $domainData['domsecret'];
    }

    protected function _parseContactInfo(array $contact): array
    {
        return [
            'id' => $contact['contactid'],
            'name' => $contact['name'],
            'email' => $contact['emailaddr'],
            'phone' => '+' . $contact['telnocc'] . $contact['telno'],
            'organisation' => $contact['company'],
            'address1' => $contact['address1'],
            'city' => $contact['city'],
            'postcode' => $contact['zip'],
            'country_code' => $contact['country'],
            // 'status' => $contact['contactstatus'],
        ];
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _contactInfo(int $contactID): array
    {
        $contactData = $this->_callApi(
            [
                'contact-id' => $contactID
            ],
            'contacts/details.json',
            'GET'
        );

        return $this->_parseContactInfo($contactData);
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _updateContact(
        string $contactID,
        string $email,
        string $telephone,
        string $name,
        string $organization,
        string $address,
        string $postcode,
        string $city,
        string $country
    ): array {
        if ($telephone) {
            $telephone = Utils::internationalPhoneToEpp($telephone);
            $phone = phone($telephone);
            $phoneCode = $phone->getPhoneNumberInstance()->getCountryCode();
            $phone = $phone->getPhoneNumberInstance()->getNationalNumber();
        } else {
            $phoneCode = '';
            $phone = '';
        }

        return $this->_callApi(
            [
                'contact-id' => $contactID,
                'email' => $email,
                'name' => $name,
                'company' => $organization,
                'address-line-1' => $address,
                'city' => $city,
                'country' => Utils::normalizeCountryCode($country),
                'zipcode' => $this->normalizePostCode($postcode, $country),
                'phone-cc' => $phoneCode,
                'phone' => $phone,
            ],
            'contacts/modify.json'
        ); // Array
    }

    protected function formatDate(?string $date): ?string
    {
        if (!isset($date)) {
            return $date;
        }
        return Carbon::parse((int) $date)->toDateTimeString();
    }

    /**
     * Renew domain
     *
     * @return DateTimeInterface New expiry date
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _renewDomain(string $domainName, int $renew_years): DateTimeInterface
    {
        $domain = $this->_getDomain($domainName, 'The expire date is extended.');
        $this->_callApi(
            [
                'order-id' => $domain->id,
                'years' => $renew_years,
                'exp-date' => Carbon::parse($domain->expires_at)->unix(),
                'auto-renew' => $domain->auto_renew,
                'invoice-option' => 'NoInvoice',
                'purchase-privacy' => $domain->whois_privacy,
            ],
            'domains/renew.json'
        );

        return Carbon::parse($domain->expires_at)->addYears($renew_years);
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
}
