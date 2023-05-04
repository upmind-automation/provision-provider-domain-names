<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNic\EppExtension;

use Metaregistrar\EPP\eppCreateContactResponse;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppPollRequest;
use Metaregistrar\EPP\eppConnection as BaseEppConnection;
use Psr\Log\LoggerInterface;

/**
 * Class EppConnection
 * @package Upmind\ProvisionProviders\DomainNames\CentralNic\EppExtension
 */
class EppConnection extends BaseEppConnection
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * EppConnection constructor.
     * @param bool $logging
     * @param string|null $settingsFile
     */
    public function __construct(bool $logging = false, string $settingsFile = null)
    {
        // Call parent's constructor
        parent::__construct($logging, $settingsFile);
    }

    /**
     * Set a PSR-3 logger.
     */
    public function setPsrLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
        if (isset($logger)) {
            $this->logFile = '/dev/null';
        }
    }
}
