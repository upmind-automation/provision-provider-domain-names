<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNicReseller\EppExtension;

use Metaregistrar\EPP\eppConnection as BaseEppConnection;
use Psr\Log\LoggerInterface;

class EppConnection extends BaseEppConnection
{
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @throws \Metaregistrar\EPP\eppException
     */
    public function __construct(bool $logging = true, string $settingsFile = null)
    {
        // Call parent's constructor
        parent::__construct($logging, $settingsFile);

        $this->useExtension('keysys-1.0');
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

            $this->logger->debug(sprintf("CentralNic Reseller [%s]:\n %s", $action, trim($message)));
        }

        parent::writeLog($text, $action);
    }
}
