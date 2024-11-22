<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\EuroDNS\Helper;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\EuroDNS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;

/**
 * EuroDNS Domains API .
 */
class EuroDNSApi
{
    protected $urlSandbox = 'https://secure.tryout-eurodns.com:20015/v2/';
    protected $urlProduction = 'https://secure.api-eurodns.com:20015/v2/';
    protected $username;
    protected $password;
    protected $url;
    protected Configuration $configuration;
    private $error;
    // @phpstan-ignore-next-line
    private $errorCode;
    private $params;
    private $contactParams;
    // @phpstan-ignore-next-line
    private $pendingMessage;
    private $contactOrgId;
    private $contactBillingId;
    private $contactTechId;
    private $contactAdminId;
    private $nameServers;
    private $logger;

    /**
     * @var string|null
     *
     * ToDo: Evaluate if this property is necessary, as it is only written but never read.
     *
     * @phpstan-ignore-next-line
     */
    private $request;

    public function __construct(Configuration $configuration, LoggerInterface $logger = null)
    {
        $this->configuration = $configuration;
        $this->logger = $logger;
        $this->initializeCredentials();
    }
    /**
     * Function to initialize the eurodns credentials
     */
    private function initializeCredentials()
    {
        // Check the sandbox option is set or not
        if ($this->configuration->sandbox) {
            $this->url = $this->urlSandbox;
            $this->username = str_replace('_prod_', '_test_', $this->configuration->username);
            $this->password = 'MD5' . md5($this->configuration->password);
        } else {
            $this->url = $this->urlProduction;
            $this->username = $this->configuration->username;
            $this->password = 'MD5' . md5($this->configuration->password);
        }
    }

    /**
     * Function to check domain availability.
     *
     * @param string[] $domainList Array of domain names to check.
     *
     * @return array<string, bool|string>|array<DacDomain> Array of DacDomain objects representing domain availability, or error.
     *
     * @throws \Throwable
     */
    public function checkDomains(array $domainList): array
    {
        // Build the XML request for domain availability check

        $request = $this->buildDomainCheckRequest($domainList);

        // Set the request property for reference
        $this->request = $request;

        // Make the request to EuroDNS API
        $response = $this->connect($request);

        // Initialize the result array
        $result = [];

        // Check for errors in the request
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;

            return $result;
        }

        // Process the API response to extract domain availability information
        $results = $this->processResponse($response, 'check');

        // Initialize an array to store DacDomain objects
        $dacDomains = [];

        // Loop through the results and create DacDomain objects
        foreach ($results as $result) {
            $available = boolval($result['avail']);

            // Create a DacDomain object with relevant information
            $dacDomains[] = DacDomain::create([
                'domain' => $result['name'],
                'description' => $result['reason']['value'],
                'tld' => Str::start(Utils::getTld((string)$result['name']), '.'),
                'can_register' => $available,
                'can_transfer' => !$available,
                'is_premium' => false,
            ]);
        }

        // Return the array of DacDomain objects
        return $dacDomains;
    }

    /**
     * Function for registering a domain in the EuroDNS system.
     *
     * @param RegisterDomainParams $data
     *
     * @return array $result
     */
    public function register(RegisterDomainParams $data): array
    {
        // Set parameters for registration
        $this->params = $data;

        // Get the full domain name
        $domainName = Utils::getDomain($this->params['sld'], $this->params['tld']);

        // Build the XML request for domain registration
        $request = $this->generateDomainRegistrationRequest($domainName);

        // Make the request to EuroDNS API
        $response = $this->connect($request);

        // Initialize the result array
        $result = [];

        // Check for errors in the request
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            // Process the API response
            $result['error'] = false;
            $result['msg'] = $this->processResponse($response);
        }

        return $result;
    }

    /**
     * Function to retrieve domain details from EuroDNS.
     *
     * @param string $domainName The domain name for which details are to be retrieved.
     *
     * @return array An array containing domain details.
     */
    public function getDomainInfo(string $domainName): array
    {
        // Construct XML request for domain info
        $request = $this->generateDomainInfoRequest($domainName);

        // Set the request property for reference
        $this->request = $request;

        // Make the request to EuroDNS API
        $response = $this->connect($request);

        // Check for errors in the request
        if (!empty($this->error)) {
            return [
                'error' => true,
                'msg' => $this->error,
            ];
        } else {
            // Process the API response to extract domain details
            $processedData = $this->processResponse($response, 'info');

            $lockedStatus = ($processedData['locked'] == 'locked') ? true : false; // Locked status;
            $nameServerObj = NameserversResult::create((array) $processedData['ns']); // Nameservers information .Create the NameseversResult object

            $registrantContact = isset($processedData['registrant']) ? $this->parseContact($processedData['registrant']) : null; // Registrant contact details
            $billingContact = isset($processedData['billing']) ? $this->parseContact($processedData['billing']) : null; // Billing contact details
            $techContact = isset($processedData['tech']) ? $this->parseContact($processedData['tech']) : null; // Technical contact details
            $adminContact = isset($processedData['admin']) ? $this->parseContact($processedData['admin']) : null; // Administrative contact details

            // Extract and format dates
            $createdDate = $this->formatDate($processedData['created_at']);
            $updatedDate = $this->formatDate($processedData['updated_at']);
            $expiredDate = $this->formatDate($processedData['expires_at']);

            // Construct and return the array of domain details
            return [
                'id' => $processedData['id'] ?: 'n/a', // Domain ID
                'domain' => (string) $processedData['domain'], // Domain name
                'statuses' => [$processedData['statuses']], // domain status
                'locked' => $lockedStatus,
                'registrant' => $registrantContact,
                'billing' => $billingContact,
                'tech' => $techContact,
                'admin' => $adminContact,
                'ns' => $nameServerObj,
                'created_at' => $createdDate,
                'updated_at' => $updatedDate,
                'expires_at' => $expiredDate,
                'authCode' => isset($processedData['authCode']) ? $processedData['authCode'] : null, // Authorization code
            ];
        }
    }

    // Helper function to format dates
    private function formatDate(?string $date): string
    {
        return $date !== null && $date !== 'not available' ?
            Utils::formatDate((string) $date) :
            Utils::formatDate((string) Carbon::now());
    }

    /**
     * Function to update registrant contact details in EuroDNS.
     *
     * @param string $domainName The domain name for which the registrant contact details are to be updated.
     * @param UpdateDomainContactParams $updateDomainContactParams The parameters for updating the registrant contact.
     *
     * @return array An array containing the result of the update operation.
     *
     * @throws \Throwable
     */
    public function updateRegistrantContactDetails(string $domainName, UpdateDomainContactParams $updateDomainContactParams): array
    {
        // Set contact parameters and update parameters
        $this->contactParams = $updateDomainContactParams->contact;
        $this->params = $updateDomainContactParams;

        // Construct XML request for updating registrant contact details
        $request = $this->generateDomainUpdateRequest($domainName, 'update', true);

        // Make the request to EuroDNS API
        $response = $this->connect($request);

        // Initialize the result array
        $result = [];

        // Check for errors in the request
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            // Retrieve updated registrant contact details from the updated domain info
            $result['error'] = false;
            $domainInfo = $this->getDomainInfo($domainName);

            // Set the result message to the updated registrant contact details, if available
            $result['msg'] = isset($domainInfo['registrant']) ? $domainInfo['registrant'] : [];
        }

        // Return the result array
        return $result;
    }

    /**
     * Function to retrieve poll messages from EuroDNS.
     *
     * In EuroDNS, only one message is retrieved at a time. To get the next poll message,
     * the latest message needs to be acknowledged, and a request for the next poll message is made.
     *
     * @param int $limit The maximum number of poll messages to retrieve.
     * @param Carbon|null $since The timestamp indicating the starting point for retrieving poll messages.
     *
     * @return array An array containing count remaining and an array of retrieved poll notifications.
     */
    public function getPollMessages(int $limit, ?Carbon $since)
    {
        // Requesting the latest poll message
        $request = <<<XML
                    <?xml version="1.0" encoding="UTF-8"?>
                    <request xmlns:poll="http://www.eurodns.com/poll">
                        <poll:retrieve/>
                    </request>
                    XML;

        $messageArray = [];
        while ($limit > count($messageArray)) {
            // Connect to EuroDNS API to retrieve poll messages
            $response = $this->connect($request);

            // Check for errors in the request
            if (!empty($this->error)) {
                $result['error'] = true;
                $result['msg'] = $this->error;
                return $result;
            }

            // Process the API response to extract poll data
            $data = $this->processResponse($response, 'poll');
            $countRemaining = $data['pollCount'];

            // Break the loop if no more poll messages are available
            if ($countRemaining < 1) {
                break;
            }

            $messageDateTime = Carbon::parse($data['createDate']);

            // Skip messages older than the specified timestamp
            if (isset($since) && $messageDateTime->lessThan($since)) {
                continue;
            }

            // Map EuroDNS message type to domain notification type
            $type = "";
            switch ($data['type']) {
                case 'DOMAIN_REGISTRATION':
                    $type = DomainNotification::TYPE_TRANSFER_IN;
                    break;
                case 'DOMAIN_MODIFICATION':
                    $type = DomainNotification::TYPE_DATA_QUALITY;
                    break;
                case 'DOMAIN_DELETION':
                    $type = DomainNotification::TYPE_DELETED;
                    break;
                case 'DOMAIN_RENEWAL':
                    $type = DomainNotification::TYPE_RENEWED;
                    break;
                case 'DOMAIN_TRADE':
                    $type = DomainNotification::TYPE_TRANSFER_IN;
                    break;
                case 'DOMAIN_TRANSFER':
                    $type = DomainNotification::TYPE_TRANSFER_IN;
                    break;
                case 'DOMAIN_APPLICATION':
                    $type = DomainNotification::TYPE_DATA_QUALITY;
                    break;
            }

            // Create a domain notification object for each poll message
            $messageArray[] = DomainNotification::create()
                ->setId($data['id'])
                ->setType($type)
                ->setMessage($data['data'])
                ->setDomains([$data['domain']])
                ->setCreatedAt($messageDateTime);

            // Acknowledge the last poll message to get the next poll message
            $ackRequest = <<<XML
                        <?xml version="1.0" encoding="UTF-8"?>
                        <request xmlns:poll="http://www.eurodns.com/poll" xmlns:message="http://www.eurodns.com/message">
                            <poll:acknowledge>
                                <message:id>{$data['id']}</message:id>
                            </poll:acknowledge>
                        </request>
                        XML;

            // Connect to EuroDNS API to acknowledge the last poll message
            $response = $this->connect($ackRequest);
        }

        return [
            'count_remaining' => $countRemaining ?? 0,
            'notifications' => $messageArray,
        ];
    }

    /**
     * Set name servers for the specified action.
     *
     * @param string $action The action to perform ('update' or other).
     *
     * @return string $ns The formatted XML for name servers.
     */
    private function setNs($action)
    {
        $i = 1;
        $ns = null;

        // Check if the action is 'update'
        if ($action == 'update') {
            $nameserversArray = $this->nameServers;
        } else {
            // Decode the JSON and retrieve name servers from parameters
            $nameserversArray = json_decode(json_encode($this->params['nameservers']), true);
        }

        // Loop through name servers and generate XML
        foreach ($nameserversArray as $key => $val) {
            $host = isset($val['host']) ? $val['host'] : $val;

            // Check if the host is not empty
            if (!empty($host)) {
                // Generate XML for the name server
                $ns .= "
                    <nameserver:{$action}>
                        <nameserver:priority>{$i}</nameserver:priority>
                        <nameserver:fqdn>{$host}</nameserver:fqdn>
                        <nameserver:ipaddr>" . gethostbyname($host) . "</nameserver:ipaddr>
                    </nameserver:{$action}>";
            }

            $i++;
        }

        return $ns;
    }

    /**
     * Set contact details for the specified action.
     *
     * @param string $action      The action to perform ('create', 'update', etc.).
     *
     * @return string $contact    The formatted XML for contact details.
     */
    private function setContact($action)
    {
        // Retrieve contact details from billing, registrant, admin, and tech sections
        $billingDetails = $this->params['billing']['register'];
        $registrantDetails = $this->params['registrant']['register'];
        $techDetails = $this->params['tech']['register'];
        $adminDetails = $this->params['admin']['register'];

        // Replace '+' with '00' in phone numbers to avoid API passing errors
        $billingPhone = str_replace('+', '00', $billingDetails['phone']);
        $contactPhone = str_replace('+', '00', $registrantDetails['phone']);
        $adminPhone = str_replace('+', '00', $adminDetails['phone']);
        $techPhone = str_replace('+', '00', $techDetails['phone']);

        //find firstname and last name from the given name
        $nameBilling = $this->splitName($billingDetails['name']);
        $nameContact = $this->splitName($registrantDetails['name']);
        $nameAdmin = $this->splitName($adminDetails['name']);
        $nameTech = $this->splitName($techDetails['name']);

        // Build the XML for contact details
        $contact = "
            <contact:{$action}>
                <contact:type>billing</contact:type>
                <contact:firstname>" . htmlspecialchars($nameBilling['first_name'], ENT_QUOTES, 'UTF-8') . "</contact:firstname>
                <contact:lastname>" . htmlspecialchars($nameBilling['last_name'], ENT_QUOTES, 'UTF-8') . "</contact:lastname>
                <contact:company>" . (empty($billingDetails['organisation']) ? '' : htmlspecialchars($billingDetails['organisation'], ENT_QUOTES, 'UTF-8')) . "</contact:company>
                <contact:address1>" . htmlspecialchars($billingDetails['address1'], ENT_QUOTES, 'UTF-8') . "</contact:address1>
                <contact:address2>" . htmlspecialchars($billingDetails['state'], ENT_QUOTES, 'UTF-8') . "</contact:address2>
                <contact:city>" . htmlspecialchars($billingDetails['city'], ENT_QUOTES, 'UTF-8') . "</contact:city>
                <contact:zipcode>" . htmlspecialchars($billingDetails['postcode'], ENT_QUOTES, 'UTF-8') . "</contact:zipcode>
                <contact:country_code>" . htmlspecialchars($billingDetails['country_code'], ENT_QUOTES, 'UTF-8') . "</contact:country_code>
                <contact:email>" . htmlspecialchars($billingDetails['email'], ENT_QUOTES, 'UTF-8') . "</contact:email>
                <contact:phone>{$billingPhone}</contact:phone>
                <contact:fax></contact:fax>
            </contact:{$action}>

            <contact:{$action}>
                <contact:type>org</contact:type>
                <contact:firstname>" . htmlspecialchars($nameContact['first_name'], ENT_QUOTES, 'UTF-8') . "</contact:firstname>
                <contact:lastname>" . htmlspecialchars($nameContact['last_name'], ENT_QUOTES, 'UTF-8') . "</contact:lastname>
                <contact:company>" . (empty($registrantDetails['organisation']) ? '' : htmlspecialchars($registrantDetails['organisation'], ENT_QUOTES, 'UTF-8')) . "</contact:company>
                <contact:address1>" . htmlspecialchars($registrantDetails['address1'], ENT_QUOTES, 'UTF-8') . "</contact:address1>
                <contact:address2>" . htmlspecialchars($registrantDetails['state'], ENT_QUOTES, 'UTF-8') . "</contact:address2>
                <contact:city>" . htmlspecialchars($registrantDetails['city'], ENT_QUOTES, 'UTF-8') . "</contact:city>
                <contact:zipcode>" . htmlspecialchars($registrantDetails['postcode'], ENT_QUOTES, 'UTF-8') . "</contact:zipcode>
                <contact:country_code>" . htmlspecialchars($registrantDetails['country_code'], ENT_QUOTES, 'UTF-8') . "</contact:country_code>
                <contact:email>" . htmlspecialchars($registrantDetails['email'], ENT_QUOTES, 'UTF-8') . "</contact:email>
                <contact:phone>{$contactPhone}</contact:phone>
                <contact:fax></contact:fax>
            </contact:{$action}>

            <contact:{$action}>
                <contact:type>admin</contact:type>
                <contact:firstname>" . htmlspecialchars($nameAdmin['first_name'], ENT_QUOTES, 'UTF-8') . "</contact:firstname>
                <contact:lastname>" . htmlspecialchars($nameAdmin['last_name'], ENT_QUOTES, 'UTF-8') . "</contact:lastname>
                <contact:company>" . (empty($adminDetails['organisation']) ? '' : htmlspecialchars($adminDetails['organisation'], ENT_QUOTES, 'UTF-8')) . "</contact:company>
                <contact:address1>" . htmlspecialchars($adminDetails['address1'], ENT_QUOTES, 'UTF-8') . "</contact:address1>
                <contact:address2>" . htmlspecialchars($adminDetails['state'], ENT_QUOTES, 'UTF-8') . "</contact:address2>
                <contact:city>" . htmlspecialchars($adminDetails['city'], ENT_QUOTES, 'UTF-8') . "</contact:city>
                <contact:zipcode>" . htmlspecialchars($adminDetails['postcode'], ENT_QUOTES, 'UTF-8') . "</contact:zipcode>
                <contact:country_code>" . htmlspecialchars($adminDetails['country_code'], ENT_QUOTES, 'UTF-8') . "</contact:country_code>
                <contact:email>" . htmlspecialchars($adminDetails['email'], ENT_QUOTES, 'UTF-8') . "</contact:email>
                <contact:phone>{$adminPhone}</contact:phone>
                <contact:fax></contact:fax>
            </contact:{$action}>

            <contact:{$action}>
                <contact:type>tech</contact:type>
                <contact:firstname>" . htmlspecialchars($nameTech['first_name'], ENT_QUOTES, 'UTF-8') . "</contact:firstname>
                <contact:lastname>" . htmlspecialchars($nameTech['last_name'], ENT_QUOTES, 'UTF-8') . "</contact:lastname>
                <contact:company>" . (empty($techDetails['organisation']) ? '' : htmlspecialchars($techDetails['organisation'], ENT_QUOTES, 'UTF-8')) . "</contact:company>
                <contact:address1>" . htmlspecialchars($techDetails['address1'], ENT_QUOTES, 'UTF-8') . "</contact:address1>
                <contact:address2>" . htmlspecialchars($techDetails['state'], ENT_QUOTES, 'UTF-8') . "</contact:address2>
                <contact:city>" . htmlspecialchars($techDetails['city'], ENT_QUOTES, 'UTF-8') . "</contact:city>
                <contact:zipcode>" . htmlspecialchars($techDetails['postcode'], ENT_QUOTES, 'UTF-8') . "</contact:zipcode>
                <contact:country_code>" . htmlspecialchars($techDetails['country_code'], ENT_QUOTES, 'UTF-8') . "</contact:country_code>
                <contact:email>" . htmlspecialchars($techDetails['email'], ENT_QUOTES, 'UTF-8') . "</contact:email>
                <contact:phone>{$techPhone}</contact:phone>
                <contact:fax></contact:fax>
            </contact:{$action}>";

        return $contact;
    }

    /**
     * FUNCTION setContactUpdate
     * Set contact details for update action from the provided parameters.
     *
     * @param string $action
     *
     * @return string $contact
     *
     * @throws \Throwable
     */
    private function setContactUpdate($action)
    {
        // Retrieve registrant details from the provided contact parameters
        $registrantDetails = $this->contactParams;

        // Check if the phone number starts with '+'
        if (!$this->startsWith($registrantDetails['phone'], '+')) {
            // Get the formatted phone number with the correct extension
            $contactPhone = Utils::localPhoneToInternational($registrantDetails['phone'], $registrantDetails['country_code']);
        } else {
            // Use the provided phone number if it already starts with '+'
            $contactPhone = $registrantDetails['phone'];
        }

        $name = $this->splitName($registrantDetails['name']);

        // Construct the XML for the contact update
        $contact = '
        <contact:' . $action . '>
            <contact:type>org</contact:type>
            <contact:firstname>' . htmlspecialchars($name['first_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</contact:firstname>
            <contact:lastname>' . htmlspecialchars($name['last_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</contact:lastname>
            <contact:company>' . htmlspecialchars($registrantDetails['organisation'] ?? '', ENT_QUOTES, 'UTF-8') . '</contact:company>
            <contact:address1>' . htmlspecialchars($registrantDetails['address1'], ENT_QUOTES, 'UTF-8') . '</contact:address1>
            <contact:address2>' . htmlspecialchars($registrantDetails['state'] ?? '', ENT_QUOTES, 'UTF-8') . '</contact:address2>
            <contact:city>' . htmlspecialchars($registrantDetails['city'], ENT_QUOTES, 'UTF-8') . '</contact:city>
            <contact:zipcode>' . htmlspecialchars($registrantDetails['postcode'], ENT_QUOTES, 'UTF-8') . '</contact:zipcode>
            <contact:country_code>' . htmlspecialchars($registrantDetails['country_code'], ENT_QUOTES, 'UTF-8') . '</contact:country_code>
            <contact:email>' . htmlspecialchars($registrantDetails['email'], ENT_QUOTES, 'UTF-8') . '</contact:email>
            <contact:phone>' . $contactPhone . '</contact:phone>
            <contact:fax></contact:fax>
        </contact:' . $action . '>';

        return $contact;
    }

    /***
     * Function to split the name into firstname and last name based on the space between them
     * if there is no speration then take firstname and last name as same
     */
    private function splitName($fullName)
    {
        // Check if the full name is empty
        if (empty($fullName)) {
            return [
                'first_name' => '',
                'last_name' => ''
            ];
        }
        // Explode the name by space
        $nameParts = explode(' ', (string)$fullName);

        // Set first name and last name
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? $nameParts[0]; // If space is not there, use the whole name as the last name

        // Create an array with first name and last name
        $nameArray = [
            'first_name' => $firstName,
            'last_name' => $lastName
        ];

        return $nameArray;
    }

    /**
      * Check if a string starts with a specific substring.
      *
      * @param string $haystack The string to search in.
      * @param string $needle   The substring to check for at the beginning.
      *
      * @return bool Whether the string starts with the specified substring.
      */
    public function startsWith($haystack, $needle)
    {
        // Check if the PHP version is 8 or above to use the built-in function
        if (phpversion() >= 8) {
            // Use str_starts_with function if PHP version is 8 or above
            return str_starts_with($haystack, $needle);
        }

        // Use the traditional substr method for older PHP versions
        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    /**
     * FUNCTION connect
     * Connects to the API and returns XML response.
     *
     * @param string $request The XML request to be sent to the API.
     *
     * @return mixed $response The XML response from the API or false if an error occurs.
     */
    private function connect(string $request)
    {
        // Check if USERNAME and PASSWORD are not empty
        if (empty($this->username) || empty($this->password)) {
            return false;
        }

        if (isset($this->logger)) {
            $this->logger->debug('EuroDNS Request:' . $request);
        }

        // Initialize cURL session
        $cUrl = curl_init();

        // Set cURL options
        curl_setopt($cUrl, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($cUrl, CURLOPT_URL, $this->url);
        curl_setopt($cUrl, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($cUrl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($cUrl, CURLOPT_VERBOSE, 0);
        curl_setopt($cUrl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cUrl, CURLOPT_POST, 1);
        curl_setopt($cUrl, CURLOPT_POSTFIELDS, 'xml=' . urlencode(urlencode($request)));

        // Execute cURL request
        $response = curl_exec($cUrl);

        // Check for cURL and API errors
        $this->errorCode = $this->getCode($response);
        $response = preg_replace('/(\>(?!\<).*?)((\&)\3*)(.*?\<)/', '$1&amp;$4', $response);
        $response = utf8_encode($response);

        if (isset($this->logger)) {
            $this->logger->debug('EuroDNS Response:' . $response);
        }

        // Check for errors or empty response
        if (!empty($this->error) || 0 != curl_errno($cUrl)) {
            return false;
        }

        // Close cURL session and return the response
        curl_close($cUrl);

        return $response;
    }

    /**
     * FUNCTION processResponse
     * Fetches details from the response XML.
     *
     * @param string $response The XML response from the API.
     * @param string $type     The type of response (e.g., 'create', 'poll', 'info', 'contact', 'check').
     *
     * @return mixed $resdataArray An array containing relevant details based on the response type.
     */
    private function processResponse($response, $type = "")
    {
        $dom = new \DOMDocument();
        $dom->loadXML($response);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('domain', 'http://www.eurodns.com/domain');
        $resdataArray = [];

        if ($type == "create") {
            // Processing create response

            $cdNodes = $xpath->query('//domain:create');

            foreach ($cdNodes as $cd) {
                //extracting the domainame and id from response xml
                $domainName = $xpath->query('./domain:name', $cd)->item(0)->nodeValue;
                $domainRoid = $xpath->query('./domain:roid', $cd)->item(0)->nodeValue;
                $resdataArray[] = [
                    'domain-name' => $domainName,
                    'domain-roid' => $domainRoid,
                ];
            }
        } elseif($type == 'poll') {
            // Processing poll response

            $xpath->registerNamespace('eurodns', 'http://www.eurodns.com/poll');
            $resDataNode = $xpath->query('/response/resData');

            foreach ($resDataNode as $childNode) {
                //getting the poll count from xml response
                $pollCount = $this->getNodeValueIfExists($xpath, './poll:data/poll:numMessages', $childNode);
                //if poll count is less than 1 then return the array with the poll count
                if($pollCount < 1) {
                    $resdataArray[] = [
                        'pollCount' => $pollCount,
                        'id' => '',
                        'createDate' => '',
                        'type' => '',
                        'data' => '',
                        'domain' => '',
                    ] ;
                    break;
                }
                // Otherwise, extract details from the response and then assign to the return array
                $msgId = $this->getNodeValueIfExists($xpath, './poll:message/message:id', $childNode);
                $createDate = $this->getNodeValueIfExists($xpath, './poll:message/message:crDate', $childNode);
                $class = $this->getNodeValueIfExists($xpath, './poll:message/message:class', $childNode);
                $data = $this->getNodeValueIfExists($xpath, './poll:message/message:data', $childNode);
                $domain = $this->getNodeValueIfExists($xpath, './poll:message/message:domain', $childNode);

                $resdataArray[] = [
                    'pollCount' => $pollCount,
                    'id' => $msgId,
                    'createDate' => $createDate,
                    'type' => $class,
                    'data' => $data,
                    'domain' => $domain,
                ] ;
            }

            return $resdataArray[0];
        } elseif ($type == 'info') {
            // Processing info response
            // Query for resData element
            $resDataNode = $xpath->query('/response/resData');

            // Process the children of resData
            foreach ($resDataNode as $childNode) {
                $pendingStatus = $xpath->query('./domain:pending[@status="yes"]', $childNode);
                if(count($pendingStatus) > 0) {
                    if (!empty($xpath->query("/response/resData/domain:pending[@status='yes']", $childNode)) && empty($xpath->query("/response/resData/domain:pending/domain:crdate", $childNode))) {
                        $this->pendingMessage = (string) $xpath->query('/response/resData/domain:pending/domain:pendingAction', $childNode)->item(0)->nodeValue;
                    } else {
                        $this->pendingMessage = "Domain Registration is pending on server side";
                    }

                    // return false;
                }
                $domainName = $this->getNodeValueIfExists($xpath, './domain:name', $childNode);
                $domainRoid = $this->getNodeValueIfExists($xpath, './domain:roid', $childNode);
                $status = $this->getNodeValueIfExists($xpath, './domain:status', $childNode);
                $lockStatus = $this->getNodeValueIfExists($xpath, './domain:lockStatus', $childNode);
                $renewal = $this->getNodeValueIfExists($xpath, './domain:renewal', $childNode);
                $this->contactOrgId = $this->getNodeValueIfExists($xpath, './domain:contact[@type="org"]', $childNode);
                $this->contactBillingId = $this->getNodeValueIfExists($xpath, './domain:contact[@type="billing"]', $childNode);
                $this->contactTechId = $this->getNodeValueIfExists($xpath, './domain:contact[@type="tech"]', $childNode);
                $this->contactAdminId = $this->getNodeValueIfExists($xpath, './domain:contact[@type="admin"]', $childNode);
                $createdDate = $this->getNodeValueIfExists($xpath, './domain:crDate', $childNode);
                $updatedDate = $this->getNodeValueIfExists($xpath, './domain:upDate', $childNode);
                $expDate = $this->getNodeValueIfExists($xpath, './domain:expDate', $childNode);
                $authCode = $this->getNodeValueIfExists($xpath, './domain:authCode', $childNode);

                $resdataArray[] = [
                    'id' => $domainRoid,
                    'domain' => $domainName,
                    'statuses' => $status,
                    'locked' => $lockStatus,
                    'created_at' => $createdDate,
                    'updated_at' => $updatedDate,
                    'expires_at' => $expDate,
                    'ns' => '',
                    'authCode' => $authCode
                ];
            }

            $xpath->registerNamespace('eurodns', 'http://www.eurodns.com/eurodns');
            // Query for all domain:ns elements within eurodns:domain
            $nsNodes = $xpath->query('/response/extension/eurodns:domain/domain:ns');

            // Process the results
            $nsDataArray = [];
            foreach ($nsNodes as $nsNode) {
                $nsDataArray[] = $nsNode->nodeValue;
            }
            //create the nameserver array
            $resdataArray[0]['ns'] = $this->parseNameservers($nsDataArray);
            //get the contact deatils by passing the contact id that get from the info response
            $resdataArray[0]['registrant'] = $this->getContact($this->contactOrgId);
            $resdataArray[0]['billing'] = $this->getContact($this->contactBillingId);
            $resdataArray[0]['tech'] = $this->getContact($this->contactTechId);
            $resdataArray[0]['admin'] = $this->getContact($this->contactAdminId);

            return $resdataArray[0];
        } elseif($type == 'contact') {
            //processing the contact response
            $resDataNode = $xpath->query('/response/resData');

            // Process the children of resData
            foreach ($resDataNode as $childNode) {
                $company = $this->getNodeValueIfExists($xpath, './contact:entity', $childNode);
                $name = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:name', $childNode);
                $org = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:org', $childNode);
                $phone = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:voice', $childNode);
                $email = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:email', $childNode);
                $street = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:addr/contact:street', $childNode);
                $city = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:addr/contact:city', $childNode);
                $pin = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:addr/contact:pc', $childNode);
                $cc = $this->getNodeValueIfExists($xpath, './contact:postalInfo/contact:addr/contact:cc', $childNode);

                $contactDetails [] = [
                    'organization' => $org,
                    'name' => $name,
                    'address1' => $street,
                    'city' => $city,
                    'state' => $city,
                    'postcode' => $pin,
                    'country_code' => $cc,
                    'email' => $email,
                    'phone' => $phone
                ];
            }

            return $contactDetails ?? [];
        } elseif($type == 'check') {
            //processing the check response
            $cdNodes = $xpath->query('//domain:check/domain:cd');

            foreach ($cdNodes as $cd) {
                $name = $xpath->query('./domain:name', $cd)->item(0)->nodeValue;
                $avail = $xpath->query('./domain:name/@avail', $cd)->item(0)->nodeValue === 'true';
                $reasonCode = $xpath->query('./domain:reason/@code', $cd)->item(0)->nodeValue;
                $reasonLang = $xpath->query('./domain:reason/@lang', $cd)->item(0)->nodeValue;
                $reasonValue = $xpath->query('./domain:reason', $cd)->item(0)->nodeValue;

                $resdataArray[] = [
                    'name' => $name,
                    'avail' => $avail,
                    'reason' => [
                        'code' => $reasonCode,
                        'lang' => $reasonLang,
                        'value' => $reasonValue,
                    ],
                ];
            }
        } else {
            //process common responses
            $xpath->registerNamespace('eurodns', 'http://www.eurodns.com/');

            // Query for the msg element
            $msgNode = $xpath->query('/eurodns:response/eurodns:result/eurodns:msg')->item(0);

            $codeNode = $xpath->query('/eurodns:response/eurodns:result/@code')->item(0);

            // Get the value of the msg element
            $msgValue = $msgNode ? $msgNode->nodeValue : null;
            $codeValue = $codeNode ? $codeNode->nodeValue : null;

            return ['msg' => $msgValue , 'code' => $codeValue ];
        }

        return $resdataArray;
    }

    /**
     * Function to check if a node value exists and return it.
     *
     * @param \DOMXPath $xpath       The DOMXPath object for querying.
     * @param string    $query       The XPath query to find the node.
     * @param \DOMNode  $contextNode The context node to start the query from.
     *
     * @return mixed|null The node value if it exists, otherwise null.
     */
    private function getNodeValueIfExists($xpath, $query, $contextNode)
    {
        // Query for the specified node
        $nodeList = $xpath->query($query, $contextNode);

        // Check if the query returned a result
        if ($nodeList->length > 0) {
            // Return the node value
            return $nodeList->item(0)->nodeValue;
        }

        // Return null
        return null;
    }

    /**
     * Function to retrieve contact details based on ID from EuroDNS.
     *
     * @param string $contactId The ID of the contact to retrieve.
     *
     * @return array Contact details obtained from EuroDNS.
     */
    private function getContact($contactId): array
    {
        // Constructing the XML request to get contact details
        $request = <<<XML
                    <?xml version="1.0" encoding="UTF-8"?>
                    <request xmlns:contact="http://www.eurodns.com/contact">
                        <contact:info>
                            <contact:ID>{$contactId}</contact:ID>
                        </contact:info>
                    </request>
                    XML;

        // Connecting to EuroDNS API and getting the response
        $response = $this->connect($request);

        // Processing the XML response to extract contact details
        return $this->processResponse($response, 'contact');
    }

    /**
     * FUNCTION getCode
     * Checks answer code for any errors that may have occurred.
     *
     * @param string $response
     *
     * @return mixed
     */
    private function getCode($response)
    {
        // Create a new DOMDocument instance
        $xmlDoc = new \DOMDocument();

        // Check if the response is empty
        if (!$response) {
            $this->error = 'Something went wrong. Response is null on code. Please try again later.';
            return '0';
        }

        // Load the XML response into the DOMDocument
        $xmlDoc->loadXML($response);

        // Get the 'result' element from the XML
        $result = $xmlDoc->getElementsByTagName('result')[0];

        if ($result) {
            // Get the 'code' attribute from the 'result' element
            $code = $result->getAttribute('code');

            // Check if the code indicates an error
            if ('1000' !== $code && '1001' !== $code) {
                $msg = $xmlDoc->getElementsByTagName('msg');

                // Iterate through each 'msg' element and append its text content to the error message
                foreach ($msg as $desc) {
                    $this->error .= $desc->textContent . "\n";
                }

                return $code;
            }

            return $code;
        }

        // Set an error message if the 'result' element is not found in the XML response
        $this->error = 'Response is null on code.';

        return '0';
    }

    /**
     * Function to initiate domain transfer in EuroDNS.
     *
     * @param string $domainName - The domain name to be transferred.
     * @param array $data - Additional data for the transfer.
     *
     * @return array - Result of the transfer initiation.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function initiateTransfer($domainName, $data): array
    {
        // Set transfer parameters
        $this->params = $data;

        // Build XML request for domain transfer
        $request = $this->generateDomainTransferRequest($domainName);

        // Set the request property for reference
        $this->request = $request;

        // Connect to EuroDNS API to initiate the transfer
        $response = $this->connect($request);

        // Prepare the result array
        $result = [];

        // Check for errors and set the result accordingly
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            $result['error'] = false;
            $result['msg'] = $this->processResponse($response);
        }

        return $result;
    }

    /**
     * This function is used to give nameserver details manually because
     * upmind not providing any options to give nameserver details at transfer
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setManualNS($type, $domainName)
    {
        //check for there is any lookup nameservers available for this domain name
        $nameserversArray = Utils::lookupNameservers($domainName);

        $ns = null;

        //if yes then generate xml with the  nameservers array
        $i = 1;
        // Loop through name servers and generate XML
        foreach ($nameserversArray as $key => $val) {
            // Check if the host is not empty
            if (!empty($val)) {
                // Generate XML for the name server
                $ns .= "
                    <nameserver:{$type}>
                        <nameserver:priority>{$key}</nameserver:priority>
                        <nameserver:fqdn>{$val}</nameserver:fqdn>
                        <nameserver:ipaddr>" . gethostbyname($val) . "</nameserver:ipaddr>
                    </nameserver:{$type}>";
            }

            $i++;
        }

        return $ns;
    }

    /**
     * Function to renew a domain in EuroDNS.
     *
     * @param string $domainName - The domain name to be renewed.
     * @param int $period - The number of years for the renewal.
     *
     * @return array - Result of the domain renewal.
     */
    public function renew(string $domainName, int $period): array
    {
        // Build XML request for domain renewal
        $request = <<<XML
                    <?xml version="1.0" encoding="UTF-8"?>
                    <request xmlns:domain="http://www.eurodns.com/domain">
                    <domain:renew>
                        <domain:name>{$domainName}</domain:name>
                        <domain:year>{$period}</domain:year>
                    </domain:renew>
                    </request>
                    XML;

        // Set the request property for reference
        $this->request = $request;

        // Connect to EuroDNS API to renew the domain
        $response = $this->connect($request);

        // Prepare the result array
        $result = [];

        // Check for errors and set the result accordingly
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            $result['error'] = false;
            $result['msg'] = $this->processResponse($response);
        }

        return $result;
    }

    /**
     * Function to retrieve the EPP code for a domain.
     *
     * @param string $domainName - The name of the domain for which to get the EPP code.
     *
     * @return array - Result of the EPP code retrieval.
     */
    public function getDomainEppCode(string $domainName): array
    {
        // Get domain information to check for existing EPP code
        $data = $this->getDomainInfo($domainName);

        // Prepare the result array
        $result = [];

        // Check for errors during domain information retrieval
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } elseif (empty($data['authCode'])) {
            // If no existing EPP code, initiate domain transfer out to generate a new EPP Code
            $response = $this->TransferOut($domainName);

            // Check for errors during domain transfer out
            if (!empty($this->error)) {
                $result['error'] = true;
                $result['msg'] = $this->error;
            } else {
                // Notify the user to wait for the transfer out to process
                $result['error'] = true;
                $result['msg'] = 'Transfer out to generate a new EPP Code, then allow 10 - 15 minutes for it to process before clicking on "Get EPP Code"';
            }
        } else {
            // If EPP code exists, set the result accordingly
            $result['error'] = false;
            $result['authCode'] = $data['authCode'];
        }

        return $result;
    }

    /**
     * FUNCTION Transfer out to get EPP code
     * Transfer out domain.
     *
     * @param string $domainName - The name of the domain to transfer out.
     *
     * @return string - XML response from the transfer out request.
     */
    public function TransferOut($domainName)
    {
        // Build XML request for transferring out the domain
        $request = <<<XML
                        <?xml version="1.0" encoding="UTF-8"?>
                        <request xmlns:domain="http://www.eurodns.com/domain">
                            <domain:transferout>
                                <domain:name>{$domainName}</domain:name>
                            </domain:transferout>
                        </request>
                    XML;

        // Initiate the transfer out request and get the XML response
        $response = $this->connect($request);

        return $response;
    }

    /**
     * Function to set the renewal mode for a domain in EuroDNS.
     *
     * @param string $domainName - The name of the domain to set renewal mode.
     * @param bool $autoRenew - Flag indicating whether to enable (true) or disable (false) auto-renewal.
     *
     * @return array - An associative array containing 'error' and 'msg' indicating the success or failure of the operation.
     */
    public function setRenewalMode(string $domainName, bool $autoRenew): array
    {
        // Convert the boolean autoRenew flag to a string value ('autorenew' or 'autoexpire')
        $autoRenewFlag = $autoRenew ? 'autorenew' : 'autoexpire';

        // Build XML request for setting renewal mode
        $request = <<<XML
                        <?xml version="1.0" encoding="UTF-8"?>
                        <request xmlns:ip="http://www.eurodns.com/">
                            <domain:setrenewalmode>
                                <domain:name>{$domainName}</domain:name>
                                <domain:renewal>{$autoRenewFlag}</domain:renewal>
                            </domain:setrenewalmode>
                        </request>
                    XML;

        // Set the request property for reference
        $this->request = $request;

        // Initiate the request and get the XML response
        $response = $this->connect($request);

        // Check for errors and process the response
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            $result['error'] = false;
            $result['msg'] = $this->processResponse($response);
        }

        return $result;
    }

    /**
     * Function to update name servers for a domain in EuroDNS.
     *
     * @param string $domainName - The name of the domain for which name servers need to be updated.
     * @param array $nameServers - An array of name servers to be set for the domain.
     * @param UpdateNameserversParams $params - Additional parameters for the update, if needed.
     *
     * @return array - An associative array containing 'error' and 'msg' indicating the success or failure of the operation.
     */
    public function updateNameservers(string $domainName, array $nameServers, UpdateNameserversParams $params): array
    {
        // Set data and parameters for the query
        $this->params = $params;
        $this->nameServers = $nameServers;

        // Build XML request for updating name servers
        $request = <<<XML
                    <?xml version="1.0" encoding="UTF-8"?>
                    <request xmlns:domain="http://www.eurodns.com/domain" xmlns:nameserver="http://www.eurodns.com/nameserver">
                        <domain:update>
                            <domain:name>{$domainName}</domain:name>
                        </domain:update>
                        {$this->setNs('update')}
                        {$this->setAdditionalInformation('update', false, true)}
                    </request>
                XML;

        // Set the request property for reference
        $this->request = $request;

        // Initiate the request and get the XML response
        $response = $this->connect($request);

        // Check for errors and process the response
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            $result['error'] = false;
            $result['msg'] = $this->processResponse($response);
        }

        return $result;
    }

    /**
     * Function to parse contact details from the provided array.
     *
     * @param array $contact - The array representing contact details.
     *
     * @return ContactData - A ContactData object containing parsed contact information.
     */
    private function parseContact(array $contact): ContactData
    {
        // Retrieve the first contact from the array
        $contact = $contact[0];

        // Create a ContactData object with parsed contact details
        return ContactData::create([
            'organisation' => (string)$contact['organization'] ?: null,
            'name' => $contact['name'],
            'address1' => (string)$contact['address1'],
            'city' => (string)$contact['city'],
            'state' => (string)$contact['state'] ?: null,
            'postcode' => (string)$contact['postcode'],
            'country_code' => $contact['country_code'],
            'email' => (string)$contact['email'],
            'phone' => (string)$contact['phone'],
        ]);
    }

    /**
     * Function to parse nameservers from the provided array.
     *
     * @param array $nameservers - The array representing nameservers.
     *
     * @return array - An array containing parsed nameserver information.
     */
    private function parseNameservers(array $nameservers): array
    {
        // Initialize an empty array to store the parsed nameserver information
        $result = [];

        // Counter variable to create unique keys for each nameserver
        $i = 1;

        // Iterate through each nameserver in the array
        foreach ($nameservers as $ns) {
            // Add an entry to the result array with a key like 'ns1', 'ns2', etc.
            $result['ns' . $i] = ['host' => (string)$ns];

            // Increment the counter
            $i++;
        }

        // Return the final array containing parsed nameserver information
        return $result;
    }

    /**
     * Function to set registrar lock status for a domain in EuroDNS.
     *
     * @param string $domainName - The name of the domain for which the lock status should be set.
     * @param bool $lock - A boolean indicating whether to lock or unlock the domain.
     *
     * @return array - An array containing the result of the operation.
     */
    public function setRegistrarLock(string $domainName, bool $lock): array
    {
        // Construct the XML request for setting registrar lock status
        $request = <<<XML
                        <?xml version="1.0" encoding="UTF-8"?>
                            <request xmlns:domain="http://www.eurodns.com/domain">
                                <domain:lock>
                                    <domain:name>{$domainName}</domain:name>
                                </domain:lock>
                            </request>
                   XML;

        // Set the constructed request to the class property for reference
        $this->request = $request;

        // Connect to EuroDNS API and send the request
        $response = $this->connect($request);

        // Initialize the result array
        $result = [];

        // Check for errors in the response
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            // If no errors, set success status in the result array
            $result['error'] = false;
            $result['msg'] = $this->processResponse($response);
        }

        // Return the result array
        return $result;
    }

    /**
     * Function to set registrar lock/unlock in EuroDNS.
     *
     * @param string $domainName
     * @param bool $lock
     *
     * @return array
     */
    public function setRegistrarUnLock(string $domainName, bool $lock): array
    {
        // Construct XML request

        $request = <<<XML
                    <?xml version="1.0" encoding="UTF-8"?>
                        <request xmlns:domain="http://www.eurodns.com/domain">
                            <domain:unlock>
                                <domain:name>{$domainName}</domain:name>
                            </domain:unlock>
                        </request>
                   XML;

        // Set the request property for reference
        $this->request = $request;

        // Connect to EuroDNS API
        $response = $this->connect($request);

        // Process the response
        if (!empty($this->error)) {
            $result['error'] = true;
            $result['msg'] = $this->error;
        } else {
            $result['error'] = false;
            $result['msg'] = $this->processResponse($response);
        }

        return $result;
    }

    /**
     * FUNCTION setAdditionalInformation
     * Set custom fields for TLD.
     *
     * @param string $action
     * @param mixed  $security
     * @param mixed  $nsUpdate
     *
     * @return string $extension
     */
    private function setAdditionalInformation($action, $security = false, $nsUpdate = false)
    {
        $additional = null;
        $extension = null;

        // Normalize TLD
        $tld = Utils::normalizeTld($this->params['tld']);

        // Check TLD for specific configurations
        if ('com' == $tld || 'net' == $tld) {
            // Extension settings for .com and .net
            $extension = '<extension:' . $action . '>
                <extension:service>
                    <service:domainprivacy>' . ($security ? 'Yes' : 'No') . '</service:domainprivacy>
                </extension:service>
                <extension:data tld="com">
                    <extension:registry>
                        <registry:language>ENG</registry:language>
                    </extension:registry>
                </extension:data>
            </extension:' . $action . '>';

            // Set nsUpdate to true
            $nsUpdate = true;
        } elseif ('abogado' == $tld) {
            // Extension settings for .abogado
            $extension = '<extension:' . $action . '>
                    <extension:service>
                        <service:domainprivacy>' . ($security ? 'Yes' : 'No') . '</service:domainprivacy>
                    </extension:service>
                    <extension:data tld="training">
                        <extension:registry>
                            <registry:language>es</registry:language>
                        </extension:registry>
                    </extension:data>
                </extension:' . $action . '>';
        } elseif ('ae.org' == $tld) {
            // Extension settings for .ae.org
            $extension = '<extension:' . $action . '>
                    <extension:service>
                        <service:domainprivacy>' . ($security ? 'Yes' : 'No') . '</service:domainprivacy>
                    </extension:service>
                    <extension:data tld="training">
                        <extension:registry>
                            <registry:language>AR</registry:language>
                        </extension:registry>
                    </extension:data>
                </extension:' . $action . '>';
        } else {
            // Default settings for other TLDs
            $extension = '<extension:' . $action . '>
                        <extension:service>
                            <service:domainprivacy>' . ($security ? 'Yes' : 'No') . '</service:domainprivacy>
                        </extension:service>
                        <extension:data tld="training">
                            <extension:registry>
                                <registry:language>DE</registry:language>
                            </extension:registry>
                        </extension:data>
                    </extension:' . $action . '>';

            // Set nsUpdate to true
            $nsUpdate = true;
        }

        return $extension;
    }

    /**
     * Concatenate domain names into the XML request.
     *
     * @param array $domainList
     *
     * @return string
     */
    public function buildDomainCheckRequest(array $domainList): string
    {
        $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>
                        <request xmlns:domain="http://www.eurodns.com/domain">
                            <domain:check>';
        foreach ($domainList as $domain) {
            $xmlRequest .= '<domain:name>' . $domain . '</domain:name>';
        }
        $xmlRequest .= '</domain:check></request>';

        return $xmlRequest;
    }

    /**
     * Generate XML request for domain registration.
     */
    private function generateDomainRegistrationRequest(string $domainName): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <request
            xmlns:domain="http://www.eurodns.com/domain"
            xmlns:nameserver="http://www.eurodns.com/nameserver"
            xmlns:contact="http://www.eurodns.com/contact"
            xmlns:company="http://www.eurodns.com/company"
            xmlns:extension="http://www.eurodns.com/extension"
            xmlns:secdns="http://www.eurodns.com/secdns"
            xmlns:nameserverprofile="http://www.eurodns.com/nameserverprofile"
            xmlns:zoneprofile="http://www.eurodns.com/zoneprofile">

            <domain:create>
                <domain:name>{$domainName}</domain:name>
                <domain:year>{$this->params['renew_years']}</domain:year>
                <domain:renewal>autoexpire</domain:renewal>
            </domain:create>
            {$this->setNs('create')}
            {$this->setContact('create')}
            {$this->setAdditionalInformation('create')}
        </request>
        XML;
    }

    /**
     * Generate XML request for transfer registration.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function generateDomainTransferRequest(string $domainName): string
    {
        return <<<XML
                    <?xml version="1.0" encoding="UTF-8"?>
                    <request
                            xmlns:domain="http://www.eurodns.com/domain"
                            xmlns:nameserver="http://www.eurodns.com/nameserver"
                            xmlns:contact="http://www.eurodns.com/contact">

                        <domain:transfer>
                            <domain:name>{$domainName}</domain:name>
                            <domain:authcode>{$this->params['epp_code']}</domain:authcode>
                        </domain:transfer>
                        {$this->setContact('create')}
                        {$this->setManualNS('create', $domainName)}
                        {$this->setAdditionalInformation('create')}
                    </request>
                XML;
    }

    /**
     * Generate XML request for retrieving domain information.
     *
     * @param string $domainName The domain name for which details are to be retrieved.
     *
     * @return string The XML request.
     */
    private function generateDomainInfoRequest(string $domainName): string
    {
        return <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                    <request xmlns:domain="http://www.eurodns.com/domain">
                        <domain:info>
                            <domain:name>{$domainName}</domain:name>
                        </domain:info>
                    </request>
                XML;
    }

    /**
     * Generate XML request for updating domain information.
     *
     * @param string $domainName The domain name to update.
     * @param string $updateType The type of update operation ('update' in this case).
     * @param bool $additionalInfoFlag Flag to include additional information in the update.
     *
     * @return string The generated XML request.
     *
     * @throws \Throwable
     */
    private function generateDomainUpdateRequest(string $domainName, string $updateType, bool $additionalInfoFlag): string
    {
        return <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                    <request
                        xmlns:domain="http://www.eurodns.com/domain"
                        xmlns:contact="http://www.eurodns.com/contact">

                        <domain:update>
                            <domain:name>{$domainName}</domain:name>
                        </domain:update>
                        {$this->setContactUpdate($updateType)}
                        {$this->setAdditionalInformation($updateType, false, $additionalInfoFlag)}
                    </request>
                XML;
    }
}
