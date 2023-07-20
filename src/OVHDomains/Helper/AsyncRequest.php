<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OVHDomains\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Promise;
use Ovh\Api as OVHClient;

/**
 * Class AsyncRequest
 * @package Upmind\ProvisionProviders\DomainNames\OVHDomains\Helper
 */
class AsyncRequest extends OVHClient
{
    private ?Client $http_client;
    private ?string $application_secret;
    private ?string $application_key;
    private ?string $consumer_key;
    private ?int $time_delta;

    public function __construct(
        $application_key,
        $application_secret,
        $api_endpoint,
        $consumer_key = null,
        Client $http_client = null)
    {
        Parent::__construct(
            $application_key,
            $application_secret,
            $api_endpoint,
            $consumer_key
        );

        $this->application_key = $application_key;
        $this->application_secret = $application_secret;
        $this->consumer_key = $consumer_key;

        $this->http_client = $this->getHttpClient();
    }

    /**
     * @throws \JsonException
     */
    protected function rawCallAsync($method, $path): PromiseInterface
    {
        $url = $this->getTarget($path);
        $request = new Request($method, $url);

        $headers = [];

        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['X-Ovh-Application'] = $this->application_key ?? '';

        if (!isset($this->time_delta)) {
            $this->calculateTimeDelta();
        }

        $now = time() + $this->time_delta;

        $headers['X-Ovh-Timestamp'] = $now;

        if ($this->consumer_key != null) {
            $toSign = $this->application_secret . '+' . $this->consumer_key . '+' . $method
                . '+' . $url. '++' . $now;
            $signature = '$1$' . sha1($toSign);
            $headers['X-Ovh-Consumer'] = $this->consumer_key;
            $headers['X-Ovh-Signature'] = $signature;
        }

        return $this->http_client->sendAsync($request, ['headers' => $headers]);
    }

    /**
     * @throws \JsonException
     */
    public function getAsync($path): Promise
    {
        return $this->rawCallAsync('GET', $path)
            ->then(function (Response $response): ?array {
                $responseBody = trim($response->getBody()->__toString());

                if ($responseBody === '') {
                    return null;
                }

                return $this->parseResponseData($responseBody);
            });
    }

    private function parseResponseData(string $result): array
    {
        $parsedResult = json_decode($result, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $result,
                ]);
        }

        return $parsedResult;
    }

    /**
     * Calculate time delta between local machine and API's server
     *
     * @throws ClientException if http request is an error
     */
    private function calculateTimeDelta()
    {
        if (!isset($this->time_delta)) {
            $response = $this->rawCall(
                'GET',
                '/auth/time',
                null,
                false
            );
            $serverTimestamp = (int)(string)$response->getBody();
            $this->time_delta = $serverTimestamp - (int)\time();
        }

        return $this->time_delta;
    }
}
