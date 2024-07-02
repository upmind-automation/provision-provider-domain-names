<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\Helper;

use CNIC\HEXONET\Logger as BaseHexonetLogger;
use Psr\Log\LoggerInterface;

/**
 * A custom PSR-3 logger for Hexonet debugging.
 *
 *     /\_/\           ___
 *    = o_o =_______    \ \
 *     __^      __(  \.__) )
 * (@)<_____>__(_____)____/
 *
 * Class HexonetLogger
 * @package Upmind\ProvisionProviders\DomainNames\Hexonet\Helper
 */
class HexonetLogger extends BaseHexonetLogger
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $post
     * @param \CNIC\HEXONET\Response $r
     * @param string|null  $error
     */
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
        $this->logger->debug("Hexonet API: \n" . $message);
    }
}
