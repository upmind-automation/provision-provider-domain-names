<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Enom;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Throwable;
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
use Upmind\ProvisionProviders\DomainNames\Enom\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Enom\Helper\EnomApi;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

/**
 * Like.A.Boss.
⠀⠀⠀⠀⠀⠀⢀⣠⡶⠖⠛⠉⠉⠉⠉⠉⠛⠲⣦⣄⠀⠀⠀
⠀⠀⠀⠀⣤⠖⠋⠁⠀⠀⠀⠀⢀⣴⣿⠛⠙⠛⢷⣤⣈⢿⠀⠀
⠀⠀⣴⠋⠀⠀⠀⠀⣀⣤⣶⠶⠚⠛⠁⠀⠀⠀⠀⠀⠀⠀⣿⠀
⢀⡟⣠⣶⠖⠛⠉⢁⣠⣴⣶⢶⡄⠀⠺⣯⣭⣭⣭⣿⠿⠗⢸⡆
⣾⠀⠀⠀⣴⣞⣉⣈⣿⡿⠛⠁⠀⠀⠀⠀⣻⣦⠶⠛⠉⠙⢿⡇
⣿⠀⠀⠀⠀⠀⠀⠀⠀⠀⢀⣠⣤⠶⠛⠉⠀⠀⠀⠀⠀⡶⢻⠁
⣿⠀⠀⠀⠀⠀⠛⠛⠛⠉⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢰⡇⣿⠀
⠘⣆⠀⠀⠀⠀⠀⠀⠀⢀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢠⡟⣼⠃⠀
⠀⠹⣄⠀⠀⠀⠀⠀⠀⠀⠛⣦⣀⠀⠀⠀⠀⣠⡶⠋⣼⠃⠀⠀
⠀⠀⠈⠛⣦⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⣠⡾⠋⠀⠀⠀⠀
⠀⠀⠀⠀⠀⠈⠉⠛⠛⠶⣤⣿⣿⣴⣶⠛⠉⠀⠀⠀⠀⠀⠀⠀
⠀⠀⠀⠀⠀⠀⠀⠀⠀⣰⠋⢸⠀⠙⢷⡀⠀⠀⠀⠀⠀⠀⠀⠀
⠀⠀⠀⠀⠀⠀⠀⠀⣾⠁⠀⢸⠀⠀⠀⠈⢷⡀⠀⠀⠀⠀⠀⠀
⠀⠀⠀⠀⠀⠀⢠⡟⠀⠀⠀⢸⡆⠀⠀⠀⠀⠘⢶⡀⠀⠀⠀⠀
⠀⠀⠀⠀⠀⣾⠃⠀⠀⠀⠀⠀⣇⠀⠀⠀⠀⠀⠀⠻⡄⠀⠀⠀
⠀⠀⠀⢀⡿⠀⠀⠀⠀⠀⠀⣀⣿⣀⣀⣀⣀⣀⣀⡀⢹⣦⣤⠄
⢀⣤⣶⣿⣿⣷⣶⠟⠛⠉⠀⠀⢸⡄⠀⠀⠉⠙⠛⠿⣿⣿⣦⢻
⠀⣸⠃⢿⠀⠀⠀⠀⠀⠀⠀⠀⠀⡇⠀⠀⠀⠀⠀⠀⠘⣿⠀⠀
 */

/**
 * Class Provider
 * @package Upmind\ProvisionProviders\DomainNames\Enom
 */
class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var EnomApi
     */
    protected $api;

    /**
     * Max count of name servers that we can expect in a request
     */
    private const MAX_CUSTOM_NAMESERVERS = 5;

    /**
     * @param Configuration $configuration
     */
    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return AboutData
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Enom')
            ->setDescription('Register, transfer, renew and manage Enom domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/enom-logo@2x.png');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        throw $this->errorResult('Operation not supported');

        // Get Domains
        $domains = [];

        $max = 30;
        $start = 0;

        foreach (Arr::get($params, 'domains') as $domain) {
            $domains[] = Utils::getDomain($domain['sld'], $domain['tld']);

            $start++;

            // Allow up to 30 domains in one check
            if ($start == $max) {
                break;
            }
        }

        // Enom V1 only. For using V2 we will need to issue multiple requests for each domain name.
        $domainList = rtrim(implode(",", $domains), ',');

        try {
            $domainsCheck = $this->api()->checkMultipleDomains($domainList);

            return $this->okResult("Domain Check Results", $domainsCheck);
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    public function poll(PollParams $params): PollResult
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @param RegisterDomainParams $params
     * @return DomainResult
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        // Get Params
        $sld = $params->sld;
        $tld = $params->tld;
        $domain = Utils::getDomain($sld, $tld);

        try {
            // eNom doesn't have contact IDs, so we must have the `register` part for each contact.
            if (!Arr::has($params, 'registrant.register')) {
                return $this->errorResult('Registrant contact data is required!');
            }

            if (!Arr::has($params, 'tech.register')) {
                return $this->errorResult('Tech contact data is required!');
            }

            if (!Arr::has($params, 'admin.register')) {
                return $this->errorResult('Admin contact data is required!');
            }

            if (!Arr::has($params, 'billing.register')) {
                return $this->errorResult('Billing contact data is required!');
            }

            // Register the domain with the registrant contact data
            $nameServers = [];

            for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
                if (Arr::has($params, 'nameservers.ns' . $i)) {
                    $nameServers[] = Arr::get($params, 'nameservers.ns' . $i)->host;
                }
            }

            // In case of success, update the rest of the contact types (admin, tech, billing)
            $this->api()->register(
                $sld,
                $tld,
                intval($params->renew_years),
                $params->registrant->register,
                $nameServers, // use custom name servers by default
                false // allow future domain transfers by default
            );

            // TODO: eNom allows registering a domain only with the registrant contact data. In our case - we're passing all of the contact data, so we'll update it in the proper places after we have the domain registered.
            $this->updateContact($sld, $tld, $params->admin->register, EnomApi::CONTACT_TYPE_ADMIN);
            $this->updateContact($sld, $tld, $params->tech->register, EnomApi::CONTACT_TYPE_TECH);
            $this->updateContact($sld, $tld, $params->billing->register, EnomApi::CONTACT_TYPE_BILLING);

            // Return newly fetched data for the domain
            return $this->_getInfo($sld, $tld, sprintf('Domain %s was registered successfully!', $domain));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @param TransferParams $params
     * @return DomainResult
     */
    public function transfer(TransferParams $params): DomainResult
    {
        // Get the domain name
        $sld = $params->sld;
        $tld = $params->tld;

        $domain = Utils::getDomain($sld, $tld);
        // TODO: `renew_years` (period) is not needed here.
        //$period = Arr::get($params, 'renew_years', 1);
        // TODO: In development, `epp_code` is not needed as well, but required when sending the requests, so we may just send a random string
        $eppCode = $params->epp_code ?: '1234';

        try {
            // Check for previous order first
            $prevOrder = $this->api()->getDomainTransferOrders($sld, $tld);

            if (is_null($prevOrder)) {
                // Attempt to create a new transfer order.
                $transfer = $this->api()->initiateTransfer($sld, $tld, $eppCode);

                // Transfer is most probably still pending, so we'll return just a basic info with the transfer order id, the domain and the order creation date
                return DomainResult::create([
                    'id' => (string) $transfer['orderId'],
                    'domain' => $domain,
                    'ns' => [],
                    'statuses' => [],
                    'created_at' => Utils::formatDate($transfer['date']),
                    'updated_at' => Utils::formatDate($transfer['date']),
                    'expires_at' => Utils::formatDate($transfer['date'])
                ]);
            } else {
                $this->errorResult(sprintf('Transfer order(s) for %s already exists!', $domain), $prevOrder, $params);
            }
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @param RenewParams $params
     * @return DomainResult
     */
    public function renew(RenewParams $params): DomainResult
    {
        // Get the domain name
        $sld = $params->sld;
        $tld = $params->tld;

        $domain = Utils::getDomain($sld, $tld);
        $period = $params->renew_years;

        try {
            // Get Domain Info
            // TODO: Should we check for ID Protect renewal?
            $info = $this->_getInfo(
                $sld,
                $tld,
                sprintf('Renewal for %s domain was successful!', $domain)
            );

            $this->api()->renew($sld, $tld, $period, false);

            return $info->setExpiresAt(Carbon::parse($info->expires_at)->addYears($period));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @param DomainInfoParams $params
     * @return DomainResult
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        try {
            return $this->_getInfo($params->sld, $params->tld, 'Domain data obtained');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param string $message
     * @return DomainResult
     */
    private function _getInfo(string $sld, string $tld, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($sld, $tld);
        return DomainResult::create($domainInfo)->setMessage($message);
    }

    /**
     * @param UpdateNameserversParams $params
     * @return NameserversResult
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        // Get Domain Name and NameServers
        $domain = Utils::getDomain($params->sld, $params->tld);

        $nameServers = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameServer = Arr::get($params, 'ns' . $i);
                $nameServers[] = $nameServer->host;
            }
        }

        try {
            // Attempt to update domain name servers
            $nameservers = $this->api()->modifyNameServers(
                $params->sld,
                $params->tld,
                $nameServers
            );

            return NameserversResult::create($nameservers)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domain));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * Emails EPP code to the registrant's email address.
     *
     * @param EppParams $params
     * @return EppCodeResult
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $sld = $params->sld;
        $tld = $params->tld;

        try {
            // Check if the domain is locked
            $regLock = $this->api()->getRegLock($sld, $tld);

            // Don't show error, but attempt to unlock
            if ($regLock === true) {
                $this->api()->setRegLock($sld, $tld, false);
                //return $this->errorResult('Domain transfer is prohibited! Please, unlock it first!');
            }

            // Send EPP Code to the registrant
            $this->api()->getEppCode($sld, $tld);

            // Restore lock
            if ($regLock === true) {
                $this->api()->setRegLock($sld, $tld, true);
            }

            return EppCodeResult::create([
                'epp_code' => 'Sent to registrant\'s email!'
            ])->setMessage('EPP/Auth code obtained');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @param IpsTagParams $params
     * @return ResultData
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported', $params);
    }

    /**
     * @param UpdateDomainContactParams $params
     * @return ContactResult
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        return $this->updateContact($params->sld, $params->tld, $params->contact, EnomApi::CONTACT_TYPE_REGISTRANT);
    }

    /**
     * @param LockParams $params
     * @return ResultData
     */
    public function setLock(LockParams $params): DomainResult
    {
        // Get the domain name
        $sld = $params->sld;
        $tld = $params->tld;
        $lock = !!$params->lock;

        $domain = Utils::getDomain($sld, $tld);

        try {
            if (!$lock && !$this->api()->getRegLock($sld, $tld)) {
                return $this->_getInfo($sld, $tld, 'Domain already unlocked');
            }

            $this->api()->setRegLock($sld, $tld, $lock);

            return $this->_getInfo($sld, $tld, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @param AutoRenewParams $params
     * @return ResultData
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        // Get the domain name
        $sld = $params->sld;
        $tld = $params->tld;

        $domain = Utils::getDomain($sld, $tld);
        $autoRenew = !!$params->auto_renew;

        try {
            $this->api()->setRenewalMode($sld, $tld, $autoRenew);

            return $this->_getInfo($sld, $tld, 'Auto-renew mode updated');
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param ContactParams $params
     * @param string $type
     * @return ContactResult
     */
    private function updateContact(string $sld, string $tld, ContactParams $params, string $type): ContactResult
    {
        try {
            $this->api()->createUpdateDomainContact($sld, $tld, $params, $type);

            return ContactResult::create([
                'contact_id' => strtolower($type),
                'name' => $params->name,
                'email' => $params->email,
                'phone' => $params->phone,
                'organisation' => $params->organisation,
                'address1' => $params->address1,
                'city' => $params->city,
                'postcode' => $params->postcode,
                'country_code' => Utils::normalizeCountryCode($params->country_code),
            ]);
        } catch (\Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @throws ProvisionFunctionError
     *
     * @return no-return
     */
    protected function handleException(Throwable $e, $params = null): void
    {
        if (!$e instanceof ProvisionFunctionError) {
            $e = new ProvisionFunctionError('Unexpected Provider Error', $e->getCode(), $e);
        }

        throw $e->withDebug([
            'params' => $params,
        ]);
    }

    protected function api(): EnomApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->configuration->sandbox
                ? 'https://resellertest.enom.com/interface.asp'
                : 'https://reseller.enom.com/interface.asp',
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/Enom'
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(!!$this->configuration->debug),
        ]);

        return $this->api = new EnomApi($client, $this->configuration);
    }
}
