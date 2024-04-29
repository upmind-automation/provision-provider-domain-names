<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\InternetX;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use GuzzleHttp\Exception\RequestException;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
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
use Upmind\ProvisionProviders\DomainNames\InternetX\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\InternetX\Helper\InternetXApi;

/**
 * InternetX provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    /**
     * @var InternetXApi
     */
    protected InternetXApi $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('InternetX')
            ->setDescription('Register, transfer, and manage InternetX domain names')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/internetx-logo@2x.png');
    }

    public function poll(PollParams $params): PollResult
    {
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;

        try {
            $poll = $this->api()->poll(intval($params->limit), $since);
            return PollResult::create($poll);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        throw $this->errorResult('Operation not supported');
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $this->checkRegisterParams($params);

        $contacts = [
            'registrant' => $params->registrant,
            'admin' => $params->admin,
            'tech' => $params->tech,
        ];

        try {
            $contactsId = $this->getContactsId($contacts);

            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contactsId,
                $params->nameservers->pluckHosts(),
            );

            throw $this->errorResult("Domain registration was started successfully.", $params);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @return array<string,int>|string[]
     */
    private function getContactsId(array $params): array
    {
        if (Arr::has($params, 'tech.id')) {
            $techId = $params['tech']['id'];

            if (!$this->api()->getContactInfo((int)$techId)) {
                throw $this->errorResult("Invalid tech ID provided!", $params);
            }
        } else if (Arr::has($params, 'tech.register')) {
            $techId = $this->api()->createContact($params['tech']['register']);
        }

        if (Arr::has($params, 'registrant.id')) {
            $registrantId = $params['registrant']['id'];

            if (!$this->api()->getContactInfo((int)$registrantId)) {
                throw $this->errorResult("Invalid registrant ID provided!", $params);
            }
        } else if (Arr::has($params, 'registrant.register')) {
            $registrantId = $this->api()->createContact($params['registrant']['register']);
        }

        if (Arr::has($params, 'admin.id')) {
            $adminId = $params['admin']['id'];

            if (!$this->api()->getContactInfo((int)$adminId)) {
                throw $this->errorResult("Invalid admin ID provided!", $params);
            }
        } else if (Arr::has($params, 'admin.register')) {
            $adminId = $this->api()->createContact($params['admin']['register']);
        }

        return array(
            InternetXApi::CONTACT_TYPE_REGISTRANT => $registrantId ?? null,
            InternetXApi::CONTACT_TYPE_ADMIN => $adminId ?? null,
            InternetXApi::CONTACT_TYPE_TECH => $techId ?? null,
        );
    }

    private function checkRegisterParams(RegisterDomainParams $params): void
    {
        if (!Arr::has($params, 'registrant.register')) {
            throw $this->errorResult('Registrant contact data is required!');
        }

        if (!Arr::has($params, 'tech.register')) {
            throw $this->errorResult('Tech contact data is required!');
        }

        if (!Arr::has($params, 'admin.register')) {
            throw $this->errorResult('Admin contact data is required!');
        }
    }

    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '0000';

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        $contacts = [
            'registrant' => $params->registrant,
            'admin' => $params->admin,
            'tech' => $params->tech,
        ];

        try {
            $contactsId = $this->getContactsId($contacts);

            $transacId = $this->api()->initiateTransfer(
                $domainName,
                $eppCode,
                $contactsId,
                intval($params->renew_years),
            );

            throw $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), [
                'transaction_id' => $transacId
            ]);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->renew($domainName, intval($params->renew_years));
            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $contact = $this->api()->updateRegistrantContact($domainName, $params->contact);

            return ContactResult::create($contact);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $result = $this->api()->updateNameservers(
                $domainName,
                $params->pluckHosts(),
            );

            return $result
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            $currentLockStatus = $this->api()->getRegistrarLockStatus($domainName);
            if (!$lock && !$currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->api()->setRegistrarLock($domainName, $lock);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        throw $this->errorResult('Operation not supported');

        $domainName = Utils::getDomain($params->sld, $params->tld);

        $autoRenew = !!$params->auto_renew;

        try {
            $this->api()->setRenewalMode($domainName, $autoRenew);
            return $this->_getInfo($domainName, 'Auto-renew mode updated');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $eppCode = $this->api()->getDomainEppCode($domainName);

            if (!$eppCode) {
                return $this->errorResult('Unable to obtain EPP code for this domain!');
            }

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @throws Throwable
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof RequestException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();

                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);


                $errorMessage = $responseData['messages'][0]['text'] ?? null;

                throw $this->errorResult(
                    sprintf('Provider API Error: %s', $errorMessage),
                    ['response_data' => $responseData],
                    [],
                    $e
                );
            }
        }
        throw $e;
    }

    protected function api(): InternetXApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $credentials = base64_encode("{$this->configuration->username}:{$this->configuration->password}");

        $client = new Client([
            'base_uri' => $this->resolveAPIURL(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/InternetXApi',
                'Authorization' => ['Basic ' . $credentials],
                'X-Domainrobot-Context' => $this->resolveAPIContext(),
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
        ]);

        return $this->api = new InternetXApi($client, $this->configuration);
    }

    public function resolveAPIURL(): string
    {
        return $this->configuration->sandbox
            ? 'https://api.demo.autodns.com'
            : 'https://api.autodns.com';
    }

    public function resolveAPIContext(): int
    {
        return $this->configuration->sandbox
            ? 1
            : 4;
    }
}
