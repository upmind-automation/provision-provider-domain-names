<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper;

use CNIC\CNR\Logger as BaseCNRLogger;
use Psr\Log\LoggerInterface;

/**
 * A custom PSR-3 logger for CentralNicReseller debugging.
 */
class CentralNicResellerLogger extends BaseCNRLogger
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function log($post, $r, $error = null): void
    {
        // Compile the Message
        $message = implode("\n", [
            '[REQUEST]',
            $r->getCommandPlain(),
            '[RAW REQUEST]',
            $post,
            $error ? "\n[ERROR]\n" . $error : '',
            $r->getPlain()
        ]);

        // Log the message
        $this->logger->debug("CentralNicReseller API: \n" . $message);
    }
}
