<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppCreateContactResponse;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppPollRequest;
use Psr\Log\LoggerInterface;

class NominetConnection extends eppConnection
{
    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    public function __construct(bool $logging = false, string $settingsFile = null)
    {
        parent::__construct($logging, $settingsFile);

        $this->addExtension('std-unrenew-1.0', 'http://www.nominet.org.uk/epp/xml/std-unrenew-1.0');
        $this->addExtension('std-release-1.0', 'http://www.nominet.org.uk/epp/xml/std-release-1.0');
        $this->addExtension('std-handshake-1.0', 'http://www.nominet.org.uk/epp/xml/std-handshake-1.0');
        $this->addExtension('contact-nom-ext-1.0', 'http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0');
        $this->addExtension('std-notifications-1.2', 'http://www.nominet.org.uk/epp/xml/std-notifications-1.2');

        $this->addCommandResponse(eppCreateContactRequest::class, eppCreateContactResponse::class);
        $this->addCommandResponse(eppInfoContactRequest::class, eppInfoContactResponse::class);
        $this->addCommandResponse(eppPollRequest::class, eppPollResponse::class);
        $this->addCommandResponse(eppReleaseRequest::class, eppReleaseResponse::class);
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

            $this->logger->debug(sprintf("Nominet [%s]:\n %s", $action, trim($message)));
        }

        parent::writeLog($text, $action);
    }
}
