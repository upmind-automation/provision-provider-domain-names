<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Auda\EppExtension;

use Metaregistrar\EPP\eppCreateDomainResponse;
use Metaregistrar\EPP\eppConnection as BaseEppConnection;
use Psr\Log\LoggerInterface;
use Upmind\ProvisionProviders\DomainNames\Auda\EppExtension\Requests\EppCreateDomainRequest;

/**
 * Class EppConnection
 * @package Upmind\ProvisionProviders\DomainNames\Auda\EppExtension
 */
class EppConnection extends BaseEppConnection
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * EppConnection constructor.
     * @param bool $logging
     * @param string|null $settingsFile
     */
    public function __construct(bool $logging = false, string $settingsFile = null)
    {
        // Call parent's constructor
        parent::__construct($logging, $settingsFile);
        parent::addCommandResponse(EppCreateDomainRequest::class, eppCreateDomainResponse::class);
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

    /**
     * Writes a log message to the log file or PSR-3 logger.
     *
     * @inheritdoc
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

            $this->logger->debug(sprintf("Auda [%s]:\n %s", $action, trim($message)));
        }

        parent::writeLog($text, $action);
    }
}
