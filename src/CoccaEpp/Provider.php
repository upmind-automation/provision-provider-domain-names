<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CoccaEpp;

use AfriCC\EPP\Frame\Response;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\CoccaEpp\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\CoccaEpp\Helper\EppHelper;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
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
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var int
     */
    public const MAX_CUSTOM_NAMESERVERS = 5;

    /**
     * @var \Upmind\ProvisionProviders\DomainNames\CoccaEpp\Client|null
     */
    protected $client;

    /**
     * @var Configuration
     */
    protected $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('CoCCA EPP')
            ->setDescription('Register, transfer, renew and manage CoCCA registry domains such as .ng and .co.ke')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/cocca-logo.jpeg');
    }

    protected function makeClient(): Client
    {
        return new Client(
            $this->configuration->epp_username,
            $this->configuration->epp_password,
            $this->configuration->hostname,
            intval($this->configuration->port) ?: null,
            $this->configuration->certificate
                ? $this->getCertificatePath($this->configuration->certificate)
                : __DIR__ . '/default_cert.pem',
            $this->getLogger()
        );
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function getClient(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        $client = $this->makeClient();
        $client->connect();

        return $this->client = $client;
    }

    /**
     * Returns an array of normalized TLDs this provider supports.
     *
     * @return string[]
     */
    protected function getSupportedTlds(): array
    {
        // Get supported TLDs from configuration

        /** @var \Illuminate\Support\Collection $tldsCollection */
        $tldsCollection = collect(explode(',', $this->configuration->supported_tlds ?? ''));
        $tlds = $tldsCollection->map(function ($tld) {
                return Utils::normalizeTld(trim($tld));
            })
            ->filter()
            ->values()
            ->all();

        if ($tlds) {
            return $tlds;
        }

        // Get default TLDs from recognised registrars

        switch ($this->configuration->hostname) {
            case 'registry.nic.net.ng':
                return ['ng'];
            case 'registry.ricta.org.rw':
                return ['rw'];
        }

        // Last resort

        return [Arr::last(explode('.', $this->configuration->hostname))];
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $dacDomains = [];

        $supportedTlds = $this->getSupportedTlds();

        $sendRequest = false;
        $checkRequest = new \AfriCC\EPP\Frame\Command\Check\Domain();
        foreach ($params->tlds as $tld) {
            if (!in_array(Utils::getRootTld($tld), $supportedTlds)) {
                $dacDomains[] = new DacDomain([
                    'domain' => Utils::getDomain($params->sld, $tld),
                    'tld' => Str::start(Utils::normalizeTld($tld), '.'),
                    'can_register' => false,
                    'can_transfer' => false,
                    'is_premium' => false,
                    'description' => 'TLD not supported',
                ]);

                continue;
            }

            $sendRequest = true;
            $checkRequest->addDomain(Utils::getDomain($params->sld, $tld));
        }

        if ($sendRequest) {
            /** @var \AfriCC\EPP\Frame\Response\MessageQueue $xmlResult */
            $xmlResult = $this->getClient()->request($checkRequest);

            $this->checkResponse($xmlResult);

            $checkDomains = $xmlResult->data()['chkData']['cd'];
            if (Arr::isAssoc($checkDomains)) {
                // this happens when there's only one result
                $checkDomains = [$checkDomains];
            }
        }

        foreach ($checkDomains ?? [] as $chk) {
            $canRegister = boolval($chk['@name']['avail']);
            $canTransfer = false;
            $isPremium = false;

            if (isset($chk['reason']) && preg_match('/^\((\d+)\)/', $chk['reason'], $matches)) {
                $canTransfer = $matches[1] === '00';
            }

            $dacDomains[] = new DacDomain([
                'domain' => $chk['name'],
                'tld' => Str::start(Utils::getTld($chk['name']), '.'),
                'can_register' => $canRegister,
                'can_transfer' => $canTransfer,
                'is_premium' => $isPremium,
                'description' => $chk['reason'] ?? ($canRegister ? 'Available' : 'Not Available'),
            ]);
        }

        return new DacResult([
            'domains' => $dacDomains,
        ]);
    }

    /**
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Operation not currently supported');
    }

    /**
     * @throws \Exception
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $client = $this->getClient();

        $infoFrame = new \AfriCC\EPP\Frame\Command\Create\Domain();
        $infoFrame->setDomain($domainName);
        $infoFrame->setPeriod($params->renew_years . 'y');
        $infoFrame->setRegistrant($this->createContact($params->registrant->register));
        $infoFrame->setAdminContact($this->createContact($params->admin->register));
        $infoFrame->setBillingContact($this->createContact($params->billing->register));
        $infoFrame->setTechContact($this->createContact($params->tech->register));
        foreach ($params->nameservers->toArray() as $key => $ns) {
            $infoFrame->addHostObj($ns['host']);
        }

        $xmlResponse = $client->request($infoFrame);
        $this->checkResponse($xmlResponse);

        return $this->_getDomain($domainName);
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $client = $this->getClient();

        $infoFrame = new \AfriCC\EPP\Frame\Command\Transfer\Domain();
        $infoFrame->setDomain($domainName);
        $infoFrame->setAuthInfo($params->epp_code);
        $infoFrame->setOperation('request');

        $xmlResponse = $client->request($infoFrame);
        $this->checkResponse($xmlResponse);

        return $this->_getDomain($domainName);
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $client = $this->getClient();
        $domainInfo = $this->_getDomain($domainName);

        $infoFrame = new \AfriCC\EPP\Frame\Command\Renew\Domain();
        $infoFrame->setDomain($domainName);
        $infoFrame->setPeriod($params->renew_years . 'y');
        $infoFrame->setCurrentExpirationDate(substr($domainInfo->expires_at, 0, 10));

        $xmlResponse = $client->request($infoFrame);
        $this->checkResponse($xmlResponse);

        return $this->_getDomain($domainName);
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        return $this->_getDomain($domainName);
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $client = $this->getClient();

        $infoFrame = new \AfriCC\EPP\Frame\Command\Info\Domain();
        $infoFrame->setDomain($domainName);

        $xmlResponse = $client->request($infoFrame);

        $domainData = $xmlResponse->data();

        if (empty($domainData) || !isset($domainData['infData'])) {
            $codeRes = $xmlResponse->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $xmlResponse->getElementsByTagName('msg')->item(0)->nodeValue;

            $this->errorResult(
                'Unable to obtain EPP code for this domain',
                ['code' => $codeRes, 'msg' => $msg, 'data' => $domainData],
                ['xml' => (string)$xmlResponse]
            );
        }

        return EppCodeResult::create([
            'epp_code' => ($domainData['infData']['authInfo']['pw'])
        ])->setMessage('EPP/Auth code obtained');
    }

    /**
     * @throws \Exception
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $client = $this->getClient();

        $infoFrame = new \AfriCC\EPP\Frame\Command\Update\Domain();
        $infoFrame->setDomain($domainName);

        if ($params->lock) {
            $infoFrame->addStatus('clientUpdateProhibited', 'Locked');
            $infoFrame->addStatus('clientTransferProhibited', 'Transfer Locked');
        } else {
            $infoFrame->removeStatus('clientUpdateProhibited');
            $infoFrame->removeStatus('clientTransferProhibited');
        }

        $client->request($infoFrame);

        return $this->_getDomain($domainName);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('The requested operation not supported', $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $domainInfo = $this->_getDomain($domainName);

        $existingNameservers = [];
        $newNameservers = [];
        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if ($existingNs = $domainInfo->ns->{'ns' . $i}->host ?? null) {
                $existingNameservers[] = $existingNs;
            }
            if ($newNs = $params->{'ns' . $i}->host ?? null) {
                $newNameservers[] = $newNs;
            }
        }

        $updateFrame = new \AfriCC\EPP\Frame\Command\Update\Domain();
        $updateFrame->setDomain($domainName);

        // add
        foreach (array_diff($newNameservers, $existingNameservers) as $ns) {
            $this->addNameserverHost($ns);
            $updateFrame->addHostObj($ns);
        }

        // remove
        foreach (array_diff($existingNameservers, $newNameservers) as $ns) {
            $updateFrame->addHostObj($ns, true);
        }

        $xmlResult = $this->getClient()->request($updateFrame);
        $this->checkResponse($xmlResult);

        $result = [];
        foreach ($newNameservers as $i => $ns) {
            $result['ns' . ($i + 1)] = ['host' => $ns];
        }

        return NameserversResult::create($result)
            ->setMessage('Nameservers updated');
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        return $this->updateContact($params->sld, $params->tld, $params->contact, EppHelper::CONTACT_TYPE_REGISTRANT);
    }

    /**
     * Returns a filesystem path to use for the given certificate PEM, creating the file if necessary.
     *
     * @param string|null $certificate Certificate PEM
     *
     * @return string|null Filesystem path to the certificate
     */
    protected function getCertificatePath(?string $certificate): ?string
    {
        if (empty($certificate)) {
            return null;
        }

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sha1($certificate);

        if (!file_exists($path)) {
            file_put_contents($path, $certificate, LOCK_EX);
        }

        return $path;
    }

    /**
     * @return ContactResult[] [registrant, billing, administrative, technical]
     *
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _allContactInfo(
        string $registrantId,
        ?string $billingId,
        ?string $adminId,
        ?string $techId
    ): array {
        $promises = [
            'registrant' => $this->_getContactInfo($registrantId),
            'billing' => $billingId ? $this->_getContactInfo($billingId) : null,
            'administrative' => $adminId ? $this->_getContactInfo($adminId) : null,
            'technical' => $techId ? $this->_getContactInfo($techId) : null,
        ];

        return PromiseUtils::all($promises)->wait();
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function _getContactInfo(string $contactId): ContactResult
    {
        $client = $this->getClient();

        $infoFrame = new \AfriCC\EPP\Frame\Command\Info\Contact();
        $infoFrame->setId($contactId);

        $xmlResponse = $client->request($infoFrame);
        $contactData = $xmlResponse->data();

        if (empty($contactData) || !isset($contactData['infData'])) {
            $codeRes = $xmlResponse->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $xmlResponse->getElementsByTagName('msg')->item(0)->nodeValue;

            $this->errorResult(
                'Unable to obtain contact data',
                ['code' => $codeRes, 'msg' => $msg, 'contact_id' => $contactId],
                ['xml' => (string)$xmlResponse]
            );
        }

        return $this->_parseContactInfo($contactData);
    }

    protected function _parseContactInfo(array $contact): ContactResult
    {
        return ContactResult::create([
            'id' => $contact['infData']['id'],
            'name' => $contact['infData']['postalInfo@int']['name']
                ?? $contact['infData']['postalInfo@loc']['name']
                ?? null,
            'email' => $contact['infData']['email'],
            'phone' => $contact['infData']['voice'],
            'organisation' => $contact['infData']['postalInfo@int']['org']
                ?? $contact['infData']['postalInfo@loc']['org']
                ?? null,
            'address1' => $contact['infData']['postalInfo@int']['addr']['street'][0]
                ?? $contact['infData']['postalInfo@loc']['addr']['street'][0],
            'city' => $contact['infData']['postalInfo@int']['addr']['city']
                ?? $contact['infData']['postalInfo@loc']['addr']['city'],
            'state' => Utils::stateCodeToName(
                $contact['infData']['postalInfo@int']['addr']['cc']
                    ?? $contact['infData']['postalInfo@loc']['addr']['cc'],
                $contact['infData']['postalInfo@int']['addr']['sp']
                    ?? ''
            ),
            'postcode' => $contact['infData']['postalInfo@int']['addr']['pc']
                ?? $contact['infData']['postalInfo@loc']['addr']['pc']
                ?? null,
            'country_code' => $contact['infData']['postalInfo@int']['addr']['cc']
                ?? $contact['infData']['postalInfo@loc']['addr']['cc'],
        ]);
    }

    /**
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _getDomain(
        string $domainName,
        string $msg = 'Domain data retrieved',
        bool $assertActive = true
    ) {
        $status = '';
        $client = $this->getClient();

        $infoFrame = new \AfriCC\EPP\Frame\Command\Info\Domain();
        $infoFrame->setDomain($domainName);

        $xmlResponse = $client->request($infoFrame);
        $domainData = $xmlResponse->data();

        if (empty($domainData) || !isset($domainData['infData'])) {
            $codeRes = $xmlResponse->getElementsByTagName('result')->item(0)->getAttribute('code');
            $msg = $xmlResponse->getElementsByTagName('msg')->item(0)->nodeValue;

            $this->errorResult(
                'Unable to obtain domain data',
                ['code' => $codeRes, 'msg' => $msg, 'data' => $domainData],
                ['xml' => (string)$xmlResponse]
            );
        }

        $ns = [];
        foreach ($domainData['infData']['ns']['hostObj'] ?? [] as $i => $nameserver) {
            $ns['ns' . ($i + 1)] = [
                'host' => $nameserver,
            ];
        }

        $contacts = $this->_allContactInfo(
            $domainData['infData']['registrant'],
            $domainData['infData']['contact@billing'] ?? null,
            $domainData['infData']['contact@admin'] ?? null,
            $domainData['infData']['contact@tech'] ?? null
        );

        $statusArray = $xmlResponse->getElementsByTagName("status");
        $currentStatuses = [];
        foreach ($statusArray as $nn) {
            $status = $nn->getAttribute("s");
            if ($status == 'ok') {
                $status = 'Active';
            }
            $currentStatuses[] = $status;
        }

        $lockStatus = false;
        // $renewStatus = false;

        $arrSearch = array_search("clientUpdateProhibited", $currentStatuses);
        if ($arrSearch !== false) {
            if (array_key_exists($arrSearch, $currentStatuses) == 1 || array_key_exists(array_search("clientTransferProhibited", $currentStatuses), $currentStatuses) == 1) {
                $lockStatus = true;
            }
        }

        $info = DomainResult::create([
            'id' => $domainData['infData']['roid'],
            'domain' => $domainName,
            'statuses' => $currentStatuses,
            'locked' => $lockStatus,
            // 'renew' => $renewStatus,
            'registrant' => $contacts['registrant'],
            'billing' => $contacts['billing'] ?? null,
            'admin' => $contacts['administrative'] ?? null,
            'tech' => $contacts['technical'] ?? null,
            'ns' => $ns,
            'created_at' => Utils::formatDate($domainData['infData']['crDate']),
            'updated_at' => Utils::formatDate($domainData['infData']['upDate'] ?? $domainData['infData']['crDate']),
            'expires_at' => Utils::formatDate($domainData['infData']['exDate']),
        ])->setMessage($msg);

        return $info;
    }

    /**
     * Assert the given Response frame indicates success.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function checkResponse(Response $xmlResponse, ?string $failureMessage = null, array $data = []): void
    {
        /** @var \AfriCC\EPP\DOM\DOMElement $result */
        $result = $xmlResponse->getElementsByTagName('result')->item(0);
        $responseCode = $result->getAttribute('code');
        $responseMessage = $xmlResponse->getElementsByTagName('msg')->item(0)->nodeValue;

        $errorMessage = sprintf('%s [%s]', $failureMessage ?: 'Domain registry error', $responseCode);

        if ($responseMessage) {
            $errorMessage = sprintf('%s: %s', $errorMessage, $responseMessage);
        }

        if (!$this->eppSuccess($responseCode)) {
            $this->errorResult(
                $errorMessage,
                array_merge(['code' => $responseCode, 'msg' => $responseMessage], $data),
                ['xml' => (string)$xmlResponse]
            );
        }
    }

    /**
     * @link https://www.rfc-editor.org/rfc/rfc5730#page-40
     */
    private function eppSuccess($code): bool
    {
        if ($code >= 1000 && $code < 2000) {
            return true;
        }

        return false;
    }

    /**
     * @return \Upmind\ProvisionProviders\DomainNames\Data\ContactResult
     *
     * @throws \Exception
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function updateContact(string $sld, string $tld, \Upmind\ProvisionProviders\DomainNames\Data\ContactParams $contact, string $type)
    {
        $domainName = Utils::getDomain($sld, $tld);
        $domain = $this->_getDomain($domainName)->toArray();

        if (!isset($domain['registrant']['id'])) {
            $this->errorResult(
                'Unable to determine domain registrant',
                ['domain_info' => $domain],
            );
        }
        $id = $domain['registrant']['id'];

        $client = $this->getClient();
        $infoFrame = new \AfriCC\EPP\Frame\Command\Update\Contact();
        $infoFrame->setId($id);

        $mode = 'chg';
        $infoFrame->appendCity('contact:chg/contact:postalInfo[@type=\'%s\']/contact:addr/contact:city', $contact->city);
        $infoFrame->appendEmail('contact:chg/contact:email', $contact->email);
        $infoFrame->appendCountryCode('contact:chg/contact:postalInfo[@type=\'%s\']/contact:addr/contact:cc', Utils::normalizeCountryCode($contact->country_code));
        $infoFrame->appendName('contact:chg/contact:postalInfo[@type=\'%s\']/contact:name', $contact->name ?? '');
        if (!$contact->name) {
            $infoFrame->appendOrganization('contact:chg/contact:postalInfo[@type=\'%s\']/contact:org', $contact->organisation);
        } else {
            $infoFrame->appendOrganization('contact:chg/contact:postalInfo[@type=\'%s\']/contact:org', $contact->organisation ?? '');
        }
        $infoFrame->appendPostalCode('contact:chg/contact:postalInfo[@type=\'%s\']/contact:addr/contact:pc', $contact->postcode);
        $infoFrame->appendProvince('contact:chg/contact:postalInfo[@type=\'%s\']/contact:addr/contact:sp', Utils::stateNameToCode($contact->country_code, $contact->state));
        $infoFrame->appendVoice('contact:chg/contact:voice', Utils::internationalPhoneToEpp($contact->phone));
        $infoFrame->appendStreet('contact:chg/contact:postalInfo[@type=\'%s\']/contact:addr/contact:street[]', $contact->address1);

        $xmlResponse = $client->request($infoFrame);
        $this->checkResponse($xmlResponse);

        return ContactResult::create([
            'contact_id' => $id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'organisation' => $contact->organisation,
            'address1' => $contact->address1,
            'city' => $contact->city,
            'postcode' => $contact->postcode,
            'country_code' => $contact->country_code,
            'state' => Utils::stateNameToCode($contact->country_code, $contact->state),
        ])->setMessage('Contact details updated');
    }

    /**
     * @throws \Exception
     */
    private function addNameserverHost(string $nameserver): void
    {
        if (!$this->checkNameserverExists($nameserver)) {
            $createFrame = new \AfriCC\EPP\Frame\Command\Create\Host();
            $createFrame->setHost($nameserver);

            $xmlResponse = $this->getClient()->request($createFrame);
            $this->checkResponse($xmlResponse, 'Failed to create nameserver', ['nameserver' => $nameserver]);
        }
    }

    /**
     * @throws \Exception
     */
    private function checkNameserverExists(string $nameServer): bool
    {
        $client = $this->getClient();

        $infoFrame = new \AfriCC\EPP\Frame\Command\Info\Host();

        $infoFrame->setHost($nameServer);
        $xmlResponse = $client->request($infoFrame);

        $codeRes = (string)$xmlResponse->getElementsByTagName('result')->item(0)->getAttribute('code');
        return $this->eppSuccess($codeRes)
         || $codeRes === '2302' /** "Object exists" @link https://www.rfc-editor.org/rfc/rfc5730#page-43 */;
    }

    /**
     * @return string Contact id
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     */
    private function createContact(ContactParams $contact): string
    {
        $client = $this->getClient();
        $infoFrame = new \AfriCC\EPP\Frame\Command\Create\Contact();
        $id = $this->generateHandle();
        $infoFrame->setId($id);
        $infoFrame->setCity($contact->city);
        $infoFrame->setName($contact->name ?? $contact->organisation);
        $infoFrame->setCountryCode(Utils::normalizeCountryCode($contact->country_code));
        $infoFrame->setEmail($contact->email);
        if ($contact->organisation || !$contact->name) {
            $infoFrame->setOrganization($contact->organisation ?? $contact->name);
        }
        $infoFrame->setOrganization($contact->organisation ?? $contact->name);
        $infoFrame->setPostalCode($contact->postcode);
        $infoFrame->addStreet($contact->address1);
        $infoFrame->setVoice(Utils::internationalPhoneToEpp($contact->phone));
        $infoFrame->setProvince(Utils::stateNameToCode($contact->country_code, $contact->state));

        $xmlResponse = $client->request($infoFrame);
        $this->checkResponse($xmlResponse);

        return $id;
    }

    /**
     * @return string
     */
    public function generateHandle()
    {
        $stamp = time();
        $shuffled = str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
        $randStr = substr($shuffled, mt_rand(0, 45), 5);
        $handle = "$stamp$randStr";
        return $handle;
    }
}
