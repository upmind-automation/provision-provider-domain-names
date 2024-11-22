<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CoccaEpp;

use AfriCC\EPP\Client as EPPClient;
use AfriCC\EPP\FrameInterface;
use Exception;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable as IlluminateStringable;
use Psr\Log\LoggerInterface;
use stdClass;
use Stringable;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

/**
 * An extensin of the COZA EPP Client which supports a PSR-3 compliant logger.
 */
class Client extends EPPClient
{
    /**
     * @var bool
     */
    protected $loggedIn = false;

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    public function __construct(
        string $username,
        string $password,
        string $host,
        ?int $port = null,
        ?string $certPath = null,
        ?LoggerInterface $logger = null,
        array $additionalConfig = []
    ) {
        $this->username = $username;
        $this->password = $password;
        $this->host = $host;
        $this->logger = $logger;

        parent::__construct(array_merge([
            'username' => $username,
            'password' => $password,
            'host' => $host,
            'port' => $port ?? 700,
            'ssl' => isset($certPath),
            'local_cert' => $certPath,
            'debug' => isset($logger),
            'services' => [
                'urn:ietf:params:xml:ns:obj1',
                'urn:ietf:params:xml:ns:obj2',
                'urn:ietf:params:xml:ns:obj3',
            ],
            'serviceExtensions' => [
                'http://custom/obj1ext-1.0'
            ],
        ], $additionalConfig));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function sendFrame(FrameInterface $frame)
    {
        try {
            return parent::sendFrame($frame);
        } catch (Exception $e) {
            $this->error(
                'Unexpected Registry Network Error',
                $e,
                ['frame' => get_class($frame)],
                ['frame_content' => $frame->__toString()]
            );
        }
    }

    /**
     * @param string|Stringable|IlluminateStringable $message
     *
     * @return void
     */
    protected function log($message, $color = '0;32')
    {
        if (isset($this->logger)) {
            if (is_string($message)) {
                // remove binary header
                $header = mb_substr($message, 0, 4);
                if (false === mb_detect_encoding($header, null, true)) {
                    $message = mb_substr($message, 4, mb_strlen($message) - 4);
                }

                // and another try in-case the binary header appared to be valid utf8 or ascii or !== 4 bytes
                if (preg_match('/(.{0,16})<\\?xml version/', $message, $matches)) {
                    $message = Str::replaceFirst($matches[1], '', $message);
                }
            }

            if (!empty($message)) {
                $this->logger->debug(
                    sprintf(
                        'CoCCA [%s]: %s',
                        $color === '1;31' ? 'SEND' : 'RECV',
                        $this->replaceSensitive($this->prettifyXml($message))
                    ),
                    [
                        'host' => $this->host,
                        'username' => $this->username,
                    ]
                );
            }
        }
    }

    protected function generateClientTransactionId()
    {
        return mt_rand() . mt_rand();
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function login($newPassword = false)
    {
        try {
            parent::login();
            $this->loggedIn = true;
        } catch (Exception $e) {
            $this->error(
                sprintf(
                    'Registry Auth Error: %s',
                    trim(Str::replaceFirst('Authentication error;', '', $e->getMessage()))
                ),
                $e
            );
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function connect()
    {
        try {
            return parent::connect();
        } catch (Exception $e) {
            if (Str::contains($e->getMessage(), ['Timeout', 'timeout', 'timed out'])) {
                $this->error(
                    sprintf('Registry Connection Error: %s', $e->getMessage()),
                    $e
                );
            }

            $this->error('Unknown registry connection error', $e, [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
        }
    }

    /**
     * @inheritDoc
     *
     * @return bool
     */
    public function close()
    {
        if ($this->loggedIn) {
            $this->loggedIn = false;
            return parent::close();
        }

        if (is_resource($this->socket)) {
            return fclose($this->socket);
        }

        return false;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     *
     * @return no-return
     */
    protected function error(string $message, Throwable $previous, array $data = [], array $debug = [])
    {
        throw (new ProvisionFunctionError($this->replaceSensitive($message), 0, $previous))
            ->withData($this->replaceSensitive($data))
            ->withDebug($this->replaceSensitive($debug));
    }

    protected function replaceSensitive($data)
    {
        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        }

        if ($data instanceof stdClass) {
            $data = (array)$data;
        }

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->replaceSensitive($v);
            }
            return $data;
        }

        if (is_string($data)) {
            $data = str_replace(
                [$this->username, $this->password],
                ['[USERNAME]', '[PASSWORD]'],
                $data
            );
        }

        return $data;
    }

    /**
     * @param string|mixed $xml
     *
     * @return string|mixed
     */
    protected function prettifyXml($xml)
    {
        try {
            $dom = new \DOMDocument('1.0');
            $dom->formatOutput = true;
            $dom->loadXml($xml);

            return $dom->saveXML() ?: $xml;
        } catch (Throwable $e) {
            return $xml;
        }
    }
}
