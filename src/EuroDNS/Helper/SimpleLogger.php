<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EuroDNS\Helper;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class SimpleLogger implements LoggerInterface
{
    /** @var string The path to the log file */
    private $logPath;

    /**
     * Constructor.
     *
     * @param string $logPath The path to the log file.
     */
    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    /**
     * Log a message with the given level.
     *
     * @param string $level The log level.
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function log($level, $message, array $context = [])
    {
        // Format the log message
        $formattedMessage = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            json_encode($context)
        );

        // Write the formatted message to the log file
        file_put_contents($this->logPath, $formattedMessage, FILE_APPEND);
    }

    /**
     * Log a message at the EMERGENCY level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function emergency($message, array $context = [])
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Log a message at the ALERT level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function alert($message, array $context = [])
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Log a message at the CRITICAL level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function critical($message, array $context = [])
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Log a message at the ERROR level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function error($message, array $context = [])
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Log a message at the WARNING level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function warning($message, array $context = [])
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Log a message at the NOTICE level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function notice($message, array $context = [])
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Log a message at the INFO level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function info($message, array $context = [])
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Log a message at the DEBUG level.
     *
     * @param mixed $message The log message.
     * @param array $context Additional context data.
     */
    public function debug($message, array $context = [])
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
