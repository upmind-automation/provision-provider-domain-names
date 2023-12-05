<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\HRS\Helper;

use GuzzleHttp\Client;
use Upmind\ProvisionProviders\DomainNames\HRS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Helper\OpenSrsApi;

class HrsApi extends OpenSrsApi
{
    /**
     * @var Configuration
     */
    protected $configuration;

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    protected function getApiEndpoint(): string
    {
        return sprintf('https://%s:%s', $this->configuration->hostname, $this->configuration->port ?: 55443);
    }
}
