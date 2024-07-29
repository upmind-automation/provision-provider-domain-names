<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EuroDNS;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
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
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\EuroDNS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\EuroDNS\Helper\EuroDNSApi;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

/**
 * Euro DNS module By Ahaladh Punathil
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    protected EuroDNSApi|null $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     *Description about provision module provider
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('EuroDNS')
            ->setLogoUrl('https://www.eurodns.com/assets/images/logos-companies/eurodns-logo-blue.svg')
            ->setDescription('Register, transfer, renew and manage EuroDNS domains');
    }

    /**
     * Default poll function
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        // Parse 'after_date' parameter into Carbon datetime, set to null if not provided
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;

        // Call the API to get poll messages based on parameters
        $poll = $this->api()->getPollMessages(intval($params->limit), $since);

        // Check if there is an error in the poll response
        if(isset($poll['error'])) {
            // Throw an exception with the error message
            $this->errorResult(sprintf((string)$poll['msg']), ['response' => $poll]);
        }

        // Create a PollResult object from the poll response
        return PollResult::create($poll);
    }

    /**
     * Check the availability of multiple domains.
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        // Normalize second-level domain (SLD)
        $sld = Utils::normalizeSld($params->sld);

        // Create an array of full domain names by combining SLD with each TLD
        $domains = array_map(
            fn ($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        // Call the API to check the availability of the specified domains
        $domainsResponse = $this->api()->checkDomains($domains);

        // Check if there is an error in the domains response
        if(isset($domainsResponse['error'])) {
            // Throw an exception with the error message
            $this->errorResult(sprintf((string)$domainsResponse['msg']), ['response' => $domainsResponse]);
        }

        // Create a DacResult object with the domain availability information
        return DacResult::create([
            'domains' => $domainsResponse,
        ]);
    }

    /**
     * Function to connect to API class file in Helper
     */
    protected function api(): EuroDNSApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        return $this->api = new EuroDNSApi($this->configuration, $this->getLogger());
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        // Construct the full domain name from the second-level domain (SLD) and top-level domain (TLD)
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Validate and check registration parameters
        $this->checkRegisterParams($params);

        // Call the API to register the domain
        $registerDomain = $this->api()->register($params);

        // Check if there is no error during domain registration
        if(!$registerDomain['error']) {
            //sometime   take  time to get domain info of newly added domain .So, we put a delay in the process
            sleep(3);

            // Retrieve and return domain information after successful registration
            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        }

        // Throw an exception with the error message if domain registration fails
        $this->errorResult(sprintf($registerDomain['msg']), ['response' => $registerDomain]);
    }

    /**
     * Function to check  all the contact details are given while registration
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function checkRegisterParams($params): void
    {
        if (!Arr::has($params, 'registrant.register')) {
            $this->errorResult('Registrant contact data is required!');
        }

        if (!Arr::has($params, 'tech.register')) {
            $this->errorResult('Tech contact data is required!');
        }

        if (!Arr::has($params, 'admin.register')) {
            $this->errorResult('Admin contact data is required!');
        }

        if (!Arr::has($params, 'billing.register')) {
            $this->errorResult('Billing contact data is required!');
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        // Construct the full domain name from the second-level domain (SLD) and top-level domain (TLD)
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Validate and check transfer parameters
        $this->checkRegisterParams($params);

        // Call the API to initiate the domain transfer
        $registerDomain = $this->api()->initiateTransfer($domainName, $params);

        // Check if there is no error during domain transfer initiation
        if (!$registerDomain['error']) {
            // Throw an exception indicating that the transfer for the domain was successfully created
            return $this->_getInfo($domainName, sprintf('Transfer for %s domain successfully created! Scheduled for transfer!', $domainName));
        }

        // Throw an exception with the error message if domain transfer initiation fails
        $this->errorResult(sprintf($registerDomain['msg']), ['response' => $registerDomain]);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        // Construct the full domain name from the second-level domain (SLD) and top-level domain (TLD)
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Convert renewal years to an integer
        $period = intval($params->renew_years);

        // Call the API to renew the domain
        $renew = $this->api()->renew($domainName, $period);

        // Check if there is no error during domain renewal
        if (!$renew['error']) {
            // If renewal is successful, return domain information
            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        }

        // Throw an exception with the error message if domain renewal fails
        $this->errorResult(sprintf($renew['msg']), ['response' => $renew]);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        // Construct the full domain name from the second-level domain (SLD) and top-level domain (TLD)
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Call the private method to get detailed information about the domain
        return $this->_getInfo($domainName, 'Domain data obtained');
    }

    /**
     * Private method to fetch detailed information about a domain.
     *
     * @param string $domainName - The full domain name.
     * @param string $message    - Message indicating the purpose or result of the operation.
     *
     * @return DomainResult - An instance of DomainResult containing the domain information.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError - If an error occurs during the API call.
     */

    private function _getInfo(string $domainName, string $message): DomainResult
    {
        // Call the API to get detailed information about the domain
        $domainInfo = $this->api()->getDomainInfo($domainName);

        // Check if the API response contains an error
        if(isset($domainInfo['error'])) {
            // Throw an exception with the error message
            $this->errorResult($domainInfo['msg'], ['response' => $domainInfo]);
        }
        // Remove sensitive information (e.g., authCode) before creating the DomainResult
        unset($domainInfo['authCode']);

        // Create a DomainResult instance and set the message
        return DomainResult::create($domainInfo)->setMessage($message);
    }

    /**
     * @inheritDoc
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        // Generate the full domain name
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Call the API to update registrant contact details
        $updateContact = $this->api()->updateRegistrantContactDetails($domainName, $params);

        // Check if the API response indicates success
        if (!$updateContact['error']) {
            // Create a ContactResult instance with the success message
            return ContactResult::create($updateContact['msg']);
        }

        // Throw an exception with the error message if the update fails
        $this->errorResult(sprintf($updateContact['msg']), ['response' => $updateContact]);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        // Normalize second-level domain (SLD) and top-level domain (TLD)
        $sld = Utils::normalizeSld($params->sld);
        $tld = Utils::normalizeTld($params->tld);

        // Generate the full domain name
        $domainName = Utils::getDomain($sld, $tld);

        // Extract nameservers from the parameters
        $nameServers = $params->pluckHosts();

        // Call the API to update nameservers
        $updateNS = $this->api()->updateNameservers($domainName, $nameServers, $params);

        // Check if the API response indicates success
        if (!$updateNS['error']) {
            // Create a NameserversResult instance with the updated nameservers and success message

            return NameserversResult::create()
                                    ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        }

        // Throw an exception with the error message if the update fails
        $this->errorResult(sprintf($updateNS['msg']), ['response' => $updateNS]);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        // Generate the full domain name
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Determine if the lock should be enabled or disabled
        $lock = !!$params->lock;

        $domainResult = $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));

        if ($lock == $domainResult->locked) {
            return $domainResult->setMessage(
                sprintf('Domain %s is already %s', $domainName, $lock ? 'locked' : 'unlocked')
            );
        }

        // Perform the appropriate action based on the lock status
        if ($lock) {
            // Call the API to enable the registrar lock
            $responseLock = $this->api()->setRegistrarLock($domainName, $lock);
        } else {
            // Call the API to disable the registrar lock
            $responseLock = $this->api()->setRegistrarUnLock($domainName, $lock);
        }

        // Check if the API response indicates success
        if (!$responseLock['error']) {
            // Create a DomainResult instance with a success message
            return $domainResult->setLocked($lock);
        }

        // Throw an exception with the error message if the action fails
        $this->errorResult($responseLock['msg'], ['response' => $responseLock]);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        // Generate the full domain name
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Determine if auto-renewal should be enabled or disabled
        $autoRenew = !!$params->auto_renew;

        // Call the API to set the renewal mode (auto-renew)
        $setAuto = $this->api()->setRenewalMode($domainName, $autoRenew);

        // Check if the API response indicates success
        if (!$setAuto['error']) {
            // Create a DomainResult instance with a success message
            return $this->_getInfo($domainName, sprintf('Auto-renew mode  for %s domain was updated!', $domainName));
        }

        // Throw an exception with the error message if the action fails
        $this->errorResult(sprintf($setAuto['msg']), ['response' => $setAuto]);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        // Generate the full domain name
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Call the API to get the EPP code for the domain
        $eppCode = $this->api()->getDomainEppCode($domainName);

        // Check if the API response indicates success
        if (!$eppCode['error']) {
            // Create an EppCodeResult instance with the obtained EPP code and a success message
            return EppCodeResult::create([
                'epp_code' => $eppCode['authCode'],
            ])->setMessage('EPP/Auth code obtained');
        }

        // Throw an exception with the error message if the action fails
        $this->errorResult(sprintf($eppCode['msg']), ['response' => $eppCode]);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Not Available on this module');
    }
}
