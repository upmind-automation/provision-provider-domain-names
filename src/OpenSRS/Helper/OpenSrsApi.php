<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenSRS\Helper;

use GuzzleHttp\Client;
use RuntimeException;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\DomainContactInfo;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Data\OpenSrsConfiguration;

/**
 * ⠄⠄⠄⠄⠄⠄⣠⣤⣶⣶⣿⣶⣶⣤⣀⠄⣀⣤⣴⣶⣶⣶⣦⣀⠄⠄⠄⠄⠄⠄
 * ⠄⠄⠄⢀⣤⣿⠡⢟⡿⠿⣛⣛⣛⠿⢿⡆⢻⣿⣿⣿⣿⣯⣃⣸⣧⡀⠄⠄⠄⠄
 * ⠄⠄⢀⣾⣿⣿⣋⣵⣾⣿⣿⣿⣿⣿⣷⣶⡄⣩⣴⣶⣶⣶⣶⣶⣭⣉⣀⠄⠄⠄
 * ⠄⢀⣿⡟⣻⣿⣿⣿⣿⠟⢋⣭⣴⣶⣶⣶⣦⣮⡙⠟⢛⣭⣭⣶⣶⣶⣮⣭⣄⠄
 * ⣴⣸⣿⠑⣛⣿⠟⢩⣶⣿⣿⣿⣿⣿⡏⡋⠉⣿⣿⡌⣿⣿⣿⣿⣿⠋⡋⠛⣿⣧
 * ⢿⣿⣿⣿⣿⣿⣶⣶⣭⣝⡻⠿⣿⣿⣷⣧⣵⠿⢟⡑⠿⠿⠿⠿⠿⠶⠭⠶⠟⠃
 * ⣬⣿⣿⣿⣿⣿⣿⣿⣷⣬⣙⣛⣒⠠⢤⣤⡔⢚⣛⣴⣿⣿⣿⣿⣿⣿⣿⡿⠛⠄
 * ⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⡿⠿⣋⣱⣾⣿⣿⣿⣎⡙⢛⣋⣉⣉⣅⠄⠄⠄
 * ⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⢏⣭⡝⢿⣿⣿⣿⣦⠄⠄
 * ⣿⣿⣿⣿⣿⣿⠿⣛⣩⣭⣭⣭⣛⣛⠿⠿⢿⣿⣿⡏⣾⣿⡇⢸⣿⡿⠿⢛⣃⠄
 * ⣿⣿⣿⣿⣿⡏⢾⣿⣯⣭⣍⣛⣛⣛⡻⠶⠶⣮⣭⢡⣿⣿⢇⣭⣵⣶⠾⠿⠋⠄
 * ⣿⣿⣿⣿⣟⢿⣦⣤⣭⣭⣭⣝⣛⡻⠿⠿⠿⠶⠶⢸⣿⣿⢠⣤⣤⣶⠾⠛⠄⠄
 * ⠿⢿⣿⣿⣿⣷⣾⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⡇⣾⣿⡿⠰⠖⠄⠄⠄⠄⠄⠄
 * ⣭⣕⠒⠲⣭⣭⣝⣛⠛⠛⠛⠛⠛⠛⠛⢛⣛⣭⠄⣿⡟⢣⣴⣾⠟⢂⣤⡀⠄⠄
 * ⣿⣿⣿⣿⣶⣶⣮⣭⣭⣭⣍⣛⣛⣉⣭⣭⣭⣶⢸⣿⣿⣿⣯⣴⠞⣛⣭⣶⣷⠄
 *
 * Class Request
 * @package Upmind\ProvisionProviders\DomainNames\OpenSRS\Helper
 */
class OpenSrsApi
{
    /**
     * Allowed contact types
     */
    public const ALLOWED_CONTACT_TYPES = ['owner', 'tech', 'admin', 'billing'];

    /**
     * Contact types
     */
    public const CONTACT_TYPE_REGISTRANT = 'owner';
    public const CONTACT_TYPE_ADMIN = 'admin';
    public const CONTACT_TYPE_TECH = 'tech';
    public const CONTACT_TYPE_BILLING = 'billing';

    /**
     * For XML builder
     */
    private const OPS_VERSION = '0.9';
    private const XML_INDENT = ' ';
    private const CRLF = "\n";

    protected Client $client;
    protected OpenSrsConfiguration $configuration;

    public function __construct(Client $client, OpenSrsConfiguration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * @param string|null $name
     * @return array
     */
    public static function getNameParts(?string $name): array
    {
        $nameParts = explode(" ", $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(" ", $nameParts);

        // OpenSRS doesn't tolerate empty `last_name` param, so... here's a workaround
        if (empty($lastName)) {
            $lastName = $firstName;
        }

        return compact('firstName', 'lastName');
    }

    /**
     * @param string $type
     * @param array $rawContactData
     */
    private static function validateContactType(string $type, array $rawContactData): void
    {
        if (!in_array(strtolower($type), self::ALLOWED_CONTACT_TYPES) || !isset($rawContactData[$type])) {
            throw new RuntimeException(sprintf('Invalid contact type %s used!', $type));
        }
    }

    /**
     * @param array $nameServers
     * @return array
     */
    public static function parseNameservers(array $nameServers): array
    {
        $result = [];

        if (count($nameServers) > 0) {
            foreach ($nameServers as $i => $ns) {
                $result['ns' . ($i + 1)] = [
                    'host' => $ns['name'],
                    'ip' => $ns['ipaddress'] // No IP address available
                ];
            }
        }

        return $result;
    }

    /**
     * @param array $rawContactData
     * @param   string  $type   Contact Type (owner, tech, admin, billing)
     * @return DomainContactInfo
     */
    public static function parseContact(array $rawContactData, string $type): DomainContactInfo
    {
        // Check if our contact type is valid
        self::validateContactType($type, $rawContactData);

        $rawContactData = $rawContactData[$type];

        return DomainContactInfo::create([
            'contact_id' => $type,
            'name' => sprintf('%s %s', (string) $rawContactData['first_name'], (string) $rawContactData['last_name']),
            'email' => (string) $rawContactData['email'],
            'phone' => (string) $rawContactData['phone'],
            'organisation' => (string) $rawContactData['org_name'],
            'address1' => (string) $rawContactData['address1'],
            'city' => (string) $rawContactData['city'],
            'state' => $rawContactData['state'],
            'postcode' => (string) $rawContactData['postal_code'],
            'country_code' => (string) $rawContactData['country'],
            'type' => null,
        ]);
    }

    /**
     * Get the correct endpoint, depending on the environment
     *
     * @param bool $sandbox
     * @return string
     */
    private static function getApiEndpoint(bool $sandbox = false): string
    {
        return $sandbox ? 'https://horizon.opensrs.net:55443' : 'https://rr-n1-tor.opensrs.net:55443';
    }

    /**
     * Send request and return the response.
     *
     * @param array $params
     * @return array
     *
     * @throws ProvisionFunctionError
     */
    public function makeRequest(array $params): array
    {
        // Request Template
        $xmlDataBlock = self::array2xml($params);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no" ?>' . self::CRLF .
            '<!DOCTYPE OPS_envelope SYSTEM "ops.dtd">' . self::CRLF .
            '<OPS_envelope>' . self::CRLF .
            self::XML_INDENT . '<header>' . self::CRLF .
            self::XML_INDENT . self::XML_INDENT . '<version>' . self::OPS_VERSION . '</version>' . self::CRLF .
            self::XML_INDENT . '</header>' . self::CRLF .
            self::XML_INDENT . '<body>' . self::CRLF .
            $xmlDataBlock . self::CRLF .
            self::XML_INDENT . '</body>' . self::CRLF .
            '</OPS_envelope>';

        $response = $this->client->request('POST', self::getApiEndpoint(!!$this->configuration->debug), [
            'body' => $xml,
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/OpenSRS',
                'Content-Type' => 'text/xml',
                'X-Username' => $this->configuration->username,
                'X-Signature' => md5(md5($xml . $this->configuration->key) . $this->configuration->key),
                'Content-Length' => strlen($xml)
            ],
        ]);

        $result = $response->getBody()->getContents();

        // Init cUrl
        if (empty($result)) {
            $response->getBody()->close();

            // Something bad happened...
            throw new RuntimeException('Problem while sending OpenSRS request.');
        }

        $response->getBody()->close();

        $responseData = self::parseResponseData($result);

        // Return the result
        return $responseData;
    }

    /**
     * Taken from https://github.com/OpenSRS/osrs-toolkit-php
     *
     * @param array $data
     * @return string
     */
    private static function array2xml(array $data): string
    {
        return str_repeat(self::XML_INDENT, 2) . '<data_block>' . self::convertData($data, 3) . self::CRLF . str_repeat(self::XML_INDENT, 2) . '</data_block>';
    }

    /**
     * Taken from https://github.com/OpenSRS/osrs-toolkit-php
     * Minor modifications done
     *
     * @param $array
     * @param int $indent
     * @return string
     */
    private static function convertData(&$array, $indent = 0): string
    {
        $string = '';
        $spacer = str_repeat(self::XML_INDENT, $indent);

        if (is_array($array)) {
            if (self::isAssoc($array)) {        # HASH REFERENCE
                $string .= self::CRLF . $spacer . '<dt_assoc>';
                $end = '</dt_assoc>';
            } else {                # ARRAY REFERENCE
                $string .= self::CRLF . $spacer . '<dt_array>';
                $end = '</dt_array>';
            }

            foreach ($array as $k => $v) {
                ++$indent;
                /* don't encode some types of stuff */
                if ((gettype($v) == 'resource') || (gettype($v) == 'user function') || (gettype($v) == 'unknown type')) {
                    continue;
                }

                $string .= self::XML_INDENT . $spacer . '<item key="' . $k . '"';
                if (gettype($v) == 'object' && get_class($v)) {
                    $string .= ' class="' . get_class($v) . '"';
                }

                $string .= '>';
                if (is_array($v) || is_object($v)) {
                    $string .= self::convertData($v, $indent + 1);
                    $string .= self::XML_INDENT . $spacer . '</item>';
                } else {
                    $string .= self::quoteXmlChars($v) . '</item>';
                }

                --$indent;
            }
            $string .= self::XML_INDENT . $spacer . $end;
        } else {
            $string .= self::XML_INDENT . $spacer . '<dt_scalar>' . self::quoteXmlChars($array) . '</dt_scalar>';
        }

        return $string;
    }

    /**
     * Taken from https://github.com/OpenSRS/osrs-toolkit-php
     *
     * Quotes special XML characters.
     *
     * @param   string      string to quote
     * @return string quoted string
     */
    private static function quoteXmlChars($string): string
    {
        $search = ['&', '<', '>', "'", '"'];
        $replace = ['&amp;', '&lt;', '&gt;', '&apos;', '&quot;'];
        $string = str_replace($search, $replace, $string);
        $string = utf8_encode($string);

        return $string;
    }

    /**
     * Taken from https://github.com/OpenSRS/osrs-toolkit-php
     *
     * Determines if an array is associative or not, since PHP
     * doesn't really distinguish between the two, but Perl/OPS does.
     *
     * @param   array       array to check
     * @return bool true if the array is associative
     */
    private static function isAssoc(array &$array): bool
    {
        /*
         * Empty array should default to associative
         * SRS was having issues with empty attribute arrays
         */
        if (empty($array)) {
            return true;
        }

        if (is_array($array)) {
            foreach ($array as $k => $v) {
                if (!is_int($k)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse and process the XML Response
     *
     * @param string $result
     * @return array
     *
     * @throws ProvisionFunctionError
     */
    private static function parseResponseData(string $result): array
    {
        $data = self::xml2php($result);

        // Check the XML for errors
        if (!isset($data['is_success'])) {
            throw static::errorResult('Registrar API Response Error', ['response' => $result, 'data' => $data]);
        }

        if ((int)$data['is_success'] === 0 && !in_array($data['response_code'], [200, 212])) {
            throw static::errorResult('Registrar API Error: ' . $data['response_text'], $data);
        }

        return $data;
    }

    /**
     * Throws a ProvisionFunctionError to interrupt execution and generate an
     * error result.
     *
     * @param string $message Error result message
     * @param array $data Error data
     * @param array $debug Error debug
     * @param Throwable|null $previous Encountered exception
     *
     * @throws ProvisionFunctionError
     *
     * @return no-return
     */
    public static function errorResult($message, $data = [], $debug = [], ?Throwable $previous = null): void
    {
        throw (new ProvisionFunctionError($message, 0, $previous))
            ->withData($data)
            ->withDebug($debug);
    }

    /**
     * Method is taken from https://github.com/OpenSRS/osrs-toolkit-php
     * Minor modifications done
     *
     * @param string $msg
     * @return array|null
     */
    public static function xml2php(string $msg): ?array
    {
        $data = null;

        $xp = xml_parser_create();
        xml_parser_set_option($xp, XML_OPTION_CASE_FOLDING, false);
        xml_parser_set_option($xp, XML_OPTION_SKIP_WHITE, true);
        xml_parser_set_option($xp, XML_OPTION_TARGET_ENCODING, 'ISO-8859-1');

        if (!xml_parse_into_struct($xp, $msg, $vals, $index)) {
            $error = sprintf(
                'XML error: %s at line %d',
                xml_error_string(xml_get_error_code($xp)),
                xml_get_current_line_number($xp)
            );
            xml_parser_free($xp);
        } elseif (empty($vals)) {
            $error = 'Unable to parse XML values';
        }

        if (isset($error)) {
            static::errorResult('Unexpected Registrar API Error', ['error' => $error, 'response' => $msg]);
        }

        xml_parser_free($xp);
        $temp = $depth = [];

        foreach ($vals as $value) {
            switch ($value['tag']) {
                case 'OPS_envelope':
                case 'header':
                case 'body':
                case 'data_block':
                    break;
                case 'version':
                case 'msg_id':
                case 'msg_type':
                    $key = '_OPS_' . $value['tag'];
                    $temp[$key] = $value['value'];
                    break;
                case 'item':
                    // Not every Item has attributes
                    if (isset($value['attributes'])) {
                        $key = $value['attributes']['key'];
                    } else {
                        $key = '';
                    }

                    switch ($value['type']) {
                        case 'open':
                            array_push($depth, $key);
                            break;
                        case 'complete':
                            array_push($depth, $key);
                            $p = implode('::', $depth);

                            // enn_change - make sure that   $value['value']   is defined
                            if (isset($value['value'])) {
                                $temp[$p] = $value['value'];
                            } else {
                                $temp[$p] = '';
                            }

                            array_pop($depth);
                            break;
                        case 'close':
                            array_pop($depth);
                            break;
                    }
                    break;
                case 'dt_assoc':
                case 'dt_array':
                    break;
            }
        }

        foreach ($temp as $key => $value) {
            $levels = explode('::', $key);
            $num_levels = count($levels);

            if ($num_levels == 1) {
                $data[$levels[0]] = $value;
            } else {
                $pointer = &$data;
                for ($i = 0; $i < $num_levels; ++$i) {
                    if (!isset($pointer[$levels[$i]])) {
                        $pointer[$levels[$i]] = [];
                    }
                    $pointer = &$pointer[$levels[$i]];
                }
                $pointer = $value;
            }
        }

        return $data;
    }

    /**
     * @param array $xmlErrors
     * @return string
     */
    private static function formatOpenSrsErrorMessage(array $xmlErrors): string
    {
        return sprintf('OpenSRS API Error: %s', implode(', ', $xmlErrors));
    }
}
