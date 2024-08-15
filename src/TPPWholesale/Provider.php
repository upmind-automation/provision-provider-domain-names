<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TPPWholesale;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
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
use Upmind\ProvisionProviders\DomainNames\TPPWholesale\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\TPPWholesale\Helper\TPPWholesaleApi;

/**
 * TPPWholesale provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;

    protected TPPWholesaleApi|null $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('TPP Wholesale')
            ->setDescription('Register, transfer, and manage NRG Console domain names')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/tpp-wholesale-logo.png');
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $domains = array_map(
            fn($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        try {
            $dacDomains = $this->api()->checkMultipleDomains($domains);

            return DacResult::create([
                'domains' => $dacDomains,
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        try {
            $domainName = Utils::getDomain($params->sld, $params->tld);

            $checkResult = $this->api()->checkMultipleDomains([$domainName]);

            if (count($checkResult) < 1) {
                $this->errorResult('Empty domain availability check result');
            }

            if (!$checkResult[0]->can_register) {
                $this->errorResult($checkResult[0]->description);
            }

            $contacts = $this->getRegisterParams($params);

            $orderID = $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contacts,
                $params->nameservers->pluckHosts(),
                $params->additional_fields,
            );
            try {
                return $this->getInfoDomainResult($domainName, 'Domain registered');
            } catch (Throwable $e) {
                return $this->getOrderDomainResult($domainName, (int)$orderID)
                    ->setNs($params->nameservers)
                    ->setCreatedAt(null)
                    ->setUpdatedAt(null)
                    ->setExpiresAt(null);
            }
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getRegisterParams(RegisterDomainParams $params): array
    {
        if (Arr::has($params, 'registrant.id')) {
            $registrantID = $params->registrant->id;
        } else {
            if (!Arr::has($params, 'registrant.register')) {
                $this->errorResult('Registrant contact data is required!');
            }

            $registrantID = $this->api()->createContact(
                $params->registrant->register,
            );
        }

        if (Arr::has($params, 'admin.id')) {
            $adminID = $params->admin->id;
        } else {
            if (!Arr::has($params, 'admin.register')) {
                $this->errorResult('Admin contact data is required!');
            }

            $adminID = $this->api()->createContact(
                $params->admin->register,
            );
        }

        if (Arr::has($params, 'tech.id')) {
            $techID = $params->tech->id;
        } else {
            if (!Arr::has($params, 'tech.register')) {
                $this->errorResult('Tech contact data is required!');
            }

            $techID = $this->api()->createContact(
                $params->tech->register,
            );
        }

        if (Arr::has($params, 'billing.id')) {
            $billingID = $params->billing->id;
        } else {
            if (!Arr::has($params, 'billing.register')) {
                $this->errorResult('Billing contact data is required!');
            }

            $billingID = $this->api()->createContact(
                $params->billing->register,
            );
        }

        return [
            TPPWholesaleApi::CONTACT_TYPE_REGISTRANT => $registrantID,
            TPPWholesaleApi::CONTACT_TYPE_ADMIN => $adminID,
            TPPWholesaleApi::CONTACT_TYPE_TECH => $techID,
            TPPWholesaleApi::CONTACT_TYPE_BILLING => $billingID,
        ];
    }

    /**
     * @return array<string,int>|string[]
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getContactsId(array $params): array
    {
        if (Arr::has($params, 'registrant.id')) {
            $registrantID = $params['registrant']['id'];
        } else {
            if (!Arr::has($params, 'registrant.register')) {
                $this->errorResult('Registrant contact data is required!');
            }
            $registrantID = $this->api()->createContact($params['registrant']['register']);
        }

        if (Arr::has($params, 'tech.id')) {
            $techID = $params['tech']['id'];
        } else {
            if (!Arr::has($params, 'tech.register')) {
                $this->errorResult('Tech contact data is required!');
            }
            $techID = $this->api()->createContact($params['tech']['register']);
        }

        if (Arr::has($params, 'admin.id')) {
            $adminID = $params['admin']['id'];
        } else {
            if (!Arr::has($params, 'admin.register')) {
                $this->errorResult('Admin contact data is required!');
            }
            $adminID = $this->api()->createContact($params['admin']['register']);
        }

        if (Arr::has($params, 'billing.id')) {
            $billingID = $params['billing']['id'];
        } else {
            if (!Arr::has($params, 'billing.register')) {
                $this->errorResult('Billing contact data is required!');
            }

            $billingID = $this->api()->createContact($params['billing']['register']);
        }

        return [
            TPPWholesaleApi::CONTACT_TYPE_REGISTRANT => $registrantID,
            TPPWholesaleApi::CONTACT_TYPE_ADMIN => $adminID,
            TPPWholesaleApi::CONTACT_TYPE_TECH => $techID,
            TPPWholesaleApi::CONTACT_TYPE_BILLING => $billingID,
        ];
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '0000';

        try {
            return $this->getInfoDomainResult($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // initiate transfer ...
        }

        $contacts = [
            'registrant' => $params->registrant,
            'admin' => $params->admin,
            'tech' => $params->tech,
            'billing' => $params->billing,
        ];

        try {
            $contactsId = $this->getContactsId($contacts);

            $transacId = $this->api()->initiateTransfer(
                $domainName,
                $eppCode,
                $contactsId,
                intval($params->renew_years),
            );

            $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), [
                'transaction_id' => $transacId
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $period = intval($params->renew_years);

        try {
            $this->api()->renew($domainName, $period);
            return $this->getInfoDomainResult($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->getInfoDomainResult($domainName, 'Domain data obtained', true);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getInfoDomainResult(
        string $domainName,
        string $message = 'Domain info obtained successfully',
        bool $orReturnOrderStatus = false
    ): DomainResult {
        try {
            $domainInfo = $this->api()->getDomainInfo($domainName);

            return DomainResult::create($domainInfo)->setMessage($message);
        } catch (Throwable $e) {
            if (!$orReturnOrderStatus) {
                $this->handleException($e);
            }

            try {
                $orderData = $this->api()->getDomainOrderInfo($domainName, null);
            } catch (Throwable $e2) {
                // throw original error...
                $this->handleException($e);
            }

            $message = sprintf(
                'Domain Inactive - %s %s: %s',
                $orderData['type'],
                $orderData['status'],
                $orderData['description']
            );
            $this->errorResult($message, $orderData, [], $e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getOrderDomainResult(string $domainName, ?int $orderId = null): DomainResult
    {
        $orderData = $this->api()->getDomainOrderInfo($domainName, $orderId);

        $message = sprintf('Domain %s %s: %s', $orderData['type'], $orderData['status'], $orderData['description']);

        return DomainResult::create()
            ->setMessage($message)
            ->setId((string)$orderId ?: 'unknown')
            ->setDomain($domainName)
            ->setStatuses([$orderData['status']]);
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        if (Str::endsWith($domainName, '.nz')) {
            $this->errorResult('Operation not supported for .nz domains');
        }

        try {
            $this->api()->updateRegistrantContact($domainName, $params->contact);

            return ContactResult::create()
                ->setMessage(sprintf('Registrant contact for %s domain was updated!', $domainName))
                ->setName($params->contact->name)
                ->setOrganisation($params->contact->organisation)
                ->setEmail($params->contact->email)
                ->setPhone($params->contact->phone)
                ->setAddress1($params->contact->address1)
                ->setCity($params->contact->city)
                ->setState($params->contact->state)
                ->setPostcode($params->contact->postcode)
                ->setCountryCode($params->contact->country_code);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $domainInfo = $this->getInfoDomainResult($domainName);

            if ($domainInfo->locked) {
                $this->errorResult('Domain must be unlocked first');
            }

            $newHosts = $params->pluckHosts();
            $existingHosts = $domainInfo->ns->pluckHosts();

            sort($newHosts);
            sort($existingHosts);

            if ($newHosts === $existingHosts) {
                return NameserversResult::create($domainInfo->ns->toArray())
                    ->setMessage('These nameservers are already set');
            }

            $this->api()->updateNameservers(
                $domainName,
                $params->pluckHosts(),
            );

            $nameserverResultData = [];
            for ($i = 1; $i <= 5; $i++) {
                $ns = $params->{"ns$i"};
                if ($ns) {
                    $nameserverResultData["ns$i"] = $ns;
                }
            }

            return NameserversResult::create($nameserverResultData)
                ->setMessage(sprintf('Nameservers for %s domain were updated!', $domainName));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            $currentLockStatus = $this->api()->getRegistrarLockStatus($domainName);
            if (!$lock && !$currentLockStatus) {
                return $this->getInfoDomainResult($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $currentLockStatus) {
                return $this->getInfoDomainResult($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->api()->setRegistrarLock($domainName, $lock);

            return $this->getInfoDomainResult($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $eppCode = $this->api()->getDomainEppCode($domainName);

            if (!$eppCode) {
                $this->errorResult('Unable to obtain EPP code for this domain!');
            }

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @return no-return
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleException(Throwable $e): void
    {
        if (($e instanceof RequestException) && $e->hasResponse()) {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = $e->getResponse();

            $responseBody = $response->getBody()->__toString();
            $responseData = json_decode($responseBody, true);

            $errorMessage = $responseData['messages'][0]['text'] ?? null;

            $this->errorResult(
                sprintf('Provider API Error: %s', $errorMessage),
                ['response_data' => $responseData],
                [],
                $e
            );
        }

        if ($e instanceof TransferException) {
            $this->errorResult('Provider API Connection Error', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ], [], $e);
        }

        throw $e;
    }

    protected function api(): TPPWholesaleApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->resolveAPIURL(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/TPPWholesaleApi',
            ],
            'connect_timeout' => 10,
            'timeout' => 30,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->api = new TPPWholesaleApi($client, $this->configuration);
    }

    public function resolveAPIURL(): string
    {
        return 'https://' . ($this->configuration->api_hostname ?: 'theconsole.tppwholesale.com.au');
    }
}
