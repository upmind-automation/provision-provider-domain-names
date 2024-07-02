<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Hexonet\Data\Configuration;

/**
 * Hexonet Domain Availability Check (DAC) helper.
 */
class Dac
{
    /**
     * Hexonet domain search endpoint.
     *
     * @var string
     */
    protected $endpoint;

    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var Client|null
     */
    protected $client;

    public function __construct(Configuration $configuration, ?Client $client = null)
    {
        $this->configuration = $configuration;
        $this->client = $client;
        $this->endpoint = $configuration->sandbox
            ? 'https://api-ote.ispapi.net/api/call.cgi'
            : 'https://api.ispapi.net/api/call.cgi';
    }

    /**
     * Perform an availability search for the given SLD and TLDs.
     *
     * @param string $sld Second-level domain e.g., harrydev
     * @param string[] $tlds Top-level domains e.g., ['.com', '.net']
     * @param string $language Language code
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If DAC search fails
     *
     * @return DacResult
     */
    public function search(string $sld, array $tlds, string $language = 'en'): DacResult
    {
        $postData = [
            'command' => 'CheckDomains',
            's_entity' => $this->configuration->sandbox ? '1234' : '54cd',
            's_login' => $this->configuration->username,
            's_pw' => $this->configuration->password,
            'x-idn-language' => $language
        ];

        $domains = [];

        foreach (array_values($tlds) as $i => $tld) {
            if (!$domain = self::getDomain($sld, $tld)) {
                continue;
            }

            $domains[$i] = $postData['domain' . $i] = $domain;
        }

        if (empty($domains)) {
            return DacResult::create([
                'domains' => [] // return empty
            ]);
        }

        $response = $this->client()->post($this->endpoint, [
            RequestOptions::FORM_PARAMS => $postData,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => 15,
            RequestOptions::CONNECT_TIMEOUT => 5,
        ]);

        return $this->processResponse($response, $domains);
    }

    /**
     * Process a DAC search response and return the result.
     *
     * @param string[] $domains
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If DAC search response is invalid
     */
    protected function processResponse(ResponseInterface $response, array $domains): DacResult
    {
        if ($response->getStatusCode() !== 200) {
            throw (new ProvisionFunctionError(
                sprintf('DAC response %s: %s', $response->getStatusCode(), $response->getReasonPhrase())
            ))->withData([
                'response_body' => Str::limit($response->getBody()->__toString(), 500),
            ]);
        }

        $lines = explode("\n", $response->getBody()->__toString());
        /** @var \Illuminate\Support\Collection $arrayDotDataCollection */
        $arrayDotDataCollection = collect($lines);

        $arrayDotData = $arrayDotDataCollection
            ->reduce(function (array $data, string $line) {
                /** @var string $line DAC result line e.g., PROPERTY[DOMAINCHECK][2]=210 Available */
                if (!$line = trim($line)) {
                    // skip this line
                    return $data;
                }

                // ToDo: Evaluate if this is the best approach.
                parse_str($line, $line);

                // e.g., ['PROPERTY.DOMAINCHECK.2' => '210 Available']
                return array_merge($data, Arr::dot($line));
            }, []);

        $data = $this->undot($arrayDotData);

        try {
            $this->checkCode($data['CODE'] ?? $data['code']);
        } catch (RuntimeException $e) {
            throw (new ProvisionFunctionError($e->getMessage(), 0, null))
                ->withData([
                    'response_body' => Str::limit($response->getBody()->__toString(), 500),
                ]);
        }

        $dacDomains = [];

        foreach ($domains as $i => $domain) {
            if (!$check = $data['PROPERTY']['DOMAINCHECK'][$i] ?? null) {
                continue;
            }

            [$sld, $tld] = explode('.', $domain, 2);
            $tld = '.' . $tld;

            [$availabilityCode, $description] = explode(' ', $check, 2);

            if ($reason = $data['PROPERTY']['REASON'][$i] ?? null) {
                $description .= '; ' . $reason;
            }

            $dacDomain = DacDomain::create([
                'domain' => $domain,
                'tld' => $tld,
                'can_register' => $availabilityCode === '210',
                'can_transfer' => $availabilityCode === '211',
                'is_premium' => Str::endsWith($check, '[PREMIUM]'),
                'description' => $description,
            ]);

            $dacDomains[] = $dacDomain;
        }

        return DacResult::create([
            'domains' => $dacDomains
        ]);
    }

    /**
     * @throws \RuntimeException
     */
    protected function checkCode(string $code): void
    {
        if ($code === '200') {
            return;
        }

        $failureCodes = [
            '420' => 'Command failed due to server error. Server closing connection',
            '421' => 'Command failed due to server error. Client should try again',
            '423' => 'Command failed due to server error. Client should try again (Could not get session)',
            '425' => 'Service temporarily locked; usage exceeded',
            '500' => 'Invalid command name',
            '503' => 'Invalid attribute name',
            '504' => 'Missing required attribute',
            '505' => 'Invalid attribute value syntax',
            '507' => 'Invalid command format',
            '520' => 'Server closing connection. Client should try opening new connection',
            '521' => 'Too many sessions open. Server closing connection',
            '530' => 'Authentication failed',
            '531' => 'Authorization failed',
            '541' => 'Invalid attribute value',
            '547' => 'Invalid command sequence',
            '549' => 'Command failed',
            '552' => 'Object status does not allow for operation',
        ];

        $reason = $failureCodes[$code] ?? 'Something seriously broke';

        throw new RuntimeException(sprintf('DAC Error [%s]: %s', $code, $reason));
    }

    protected function client(): Client
    {
        if (!isset($this->client)) {
            $this->client = new Client();
        }

        return $this->client;
    }

    /**
     * Get a full ascii domain name from the given sld and tld.
     */
    public static function getDomain(string $sld, string $tld): ?string
    {
        return idn_to_ascii(
            sprintf('%s.%s', trim($sld, '-.'), trim($tld, '.')),
            IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46
        ) ?: null;
    }

    /**
     * Un-dot an array back to multi-asoc.
     *
     * @param string[] $array Dot-notated array
     *
     * @return array Multi-assoc array
     */
    protected function undot(array $array): array
    {
        $return = [];

        foreach ($array as $key => $value) {
            data_fill($return, $key, $value);
        }

        return $return;
    }
}
