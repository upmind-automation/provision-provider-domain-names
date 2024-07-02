<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\HRS;

use GuzzleHttp\Client;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\HRS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\HRS\Helper\HrsApi;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Provider as OpenSRSProvider;

class Provider extends OpenSRSProvider
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var HrsApi|null
     */
    protected $apiClient;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('HRS')
            ->setDescription('Register, transfer, renew and manage HRS domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/hrs-logo@2x.png');
    }

    protected function api(): HrsApi
    {
        if (isset($this->apiClient)) {
            return $this->apiClient;
        }

        $client = new Client([
            'connect_timeout' => 10,
            'timeout' => 60,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->apiClient = new HrsApi($client, $this->configuration);
    }
}
