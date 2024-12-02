<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Norid\EppExtension;

use Metaregistrar\EPP\eppConnection as BaseEppConnection;
use Psr\Log\LoggerInterface;

/**
 * Class EppConnection
 * @package Upmind\ProvisionProviders\DomainNames\Norid\EppExtension
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

        parent::enableDnssec();

        // Define supported EPP services
        parent::setServices(array(
            'urn:ietf:params:xml:ns:domain-1.0' => 'domain',
            'urn:ietf:params:xml:ns:contact-1.0' => 'contact',
            'urn:ietf:params:xml:ns:host-1.0' => 'host'
        ));

        parent::useExtension('authInfo-1.1');

        // Add registry-specific EPP extensions
        parent::useExtension('no-ext-epp-1.0');
        parent::useExtension('no-ext-result-1.0');
        parent::useExtension('no-ext-domain-1.1');
        parent::useExtension('no-ext-contact-1.0');
        parent::useExtension('no-ext-host-1.0');
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

            $this->logger->debug(sprintf("Norid [%s]:\n %s", $action, trim($message)));
        }

        parent::writeLog($text, $action);
    }
}
