<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension;

use Metaregistrar\EPP\eppConnection as BaseEppConnection;
use Metaregistrar\EPP\eppTransferResponse;
use Psr\Log\LoggerInterface;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests\EppCheckTransferRequest;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests\EppQueryTransferListRequest;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests\EppTransferRequest;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses\EppCheckTransferResponse;
use Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses\EppQueryTransferListResponse;

/**
 *      ,_     _,
 *      |\\___//|
 *      |=6   6=|
 *      \=._Y_.=/
 *      )  `  (    ,
 *     /       \  ((
 *    |       |   ))
 *   /| |   | |\_//
 *  \| |._.| |/-`
 *  '"'   '"'
 *
 * Class EppConnection
 * @package Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension
 */
class EppConnection extends BaseEppConnection
{
    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    public function __construct(bool $logging = false, string $settingsFile = null)
    {
        // Call parent's constructor
        parent::__construct($logging, $settingsFile);

        // Add Extension for USERTRANSFER transfer action / CheckDomainTransfer command for Hexonet
        $this->addExtension('keyvalue', 'http://schema.ispapi.net/epp/xml/keyvalue-1.0');

        // Add response handler for our custom transfer request(s)
        $this->addCommandResponse(EppTransferRequest::class, eppTransferResponse::class);
        $this->addCommandResponse(EppCheckTransferRequest::class, EppCheckTransferResponse::class);
        $this->addCommandResponse(EppQueryTransferListRequest::class, EppQueryTransferListResponse::class);
    }

    /**
     * Set a PSR-3 logger.
     */
    public function setPsrLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->logging = isset($logger);
        if (isset($logger)) {
            $this->logFile = '/dev/null';
        }
    }

    /**
     * Writes a log message to the log file or PSR-3 logger.
     */
    public function writeLog($text, $action)
    {
        if ($this->logging && isset($this->logger)) {
            $message = $text;
            $message = $this->hideTextBetween($message, '<clID>', '</clID>');
            // Hide password in the logging
            $message = $this->hideTextBetween($message, '<pw>', '</pw>');
            $message = $this->hideTextBetween($message, '<pw><![CDATA[', ']]></pw>');
            // Hide new password in the logging
            $message = $this->hideTextBetween($message, '<newPW>', '</newPW>');
            $message = $this->hideTextBetween($message, '<newPW><![CDATA[', ']]></newPW>');
            // Hide domain password in the logging
            $message = $this->hideTextBetween($message, '<domain:pw>', '</domain:pw>');
            $message = $this->hideTextBetween($message, '<domain:pw><![CDATA[', ']]></domain:pw>');
            // Hide contact password in the logging
            $message = $this->hideTextBetween($message, '<contact:pw>', '</contact:pw>');
            $message = $this->hideTextBetween($message, '<contact:pw><![CDATA[', ']]></contact:pw>');

            $this->logger->debug(sprintf("Hexonet [%s]:\n %s", $action, trim($message)));
        }

        parent::writeLog($text, $action);
    }
}
