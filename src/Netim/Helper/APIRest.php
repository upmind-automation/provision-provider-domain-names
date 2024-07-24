<?php

/** 
 * @created 2021-04-02
 * @lastUpdated 2022-11-09
 * @version 1.01
 *
 * Generic class for a NETIM REST client API. 
 * Documentation @ https://support.netim.com/en/docs/resellers/apis/rest-api
 *
 * How to use the class?
 * =====================
 * 
 * Beforehand you need to include this script into your php script:
 * ```php
 * 		include_once('$PATH/APIRest.php');
 * 		//(replace $PATH by the path of the file)
 * ```
 * 
 * Then you can instantiate a APIRest object:
 * ```php
 * 		$username = 'yourUsername';
 * 		$secret = 'yourSecret';
 * 		$client = new APIRest($username, $secret);
 * ```
 * 
 * You can also create a conf.xml file next to the APIRest.php class with the login credentials to connect to the API with no parameters
 * 	
 * Now that you have your object, you can issue commands to the API.
 * 
 * Say you want to see the information you gave when creating your contact, and your contact ID is 'GK521'.
 * The code is:
 * ```php
 * 		$result = $client->contactInfo('GK521');
 * ```
 * 
 * (SIDENOTE: you may have noticed that you didn't need to explicitely open nor close a connexion with the API, the client handle it for you.
 * It is good for shortlived scripts. The connection is automatically stopped when the script ends. However if you open multiple connections
 * in a long running script, you should close each connection when you don't need them anymore to avoid having too many connections opened).
 * 
 * To know if there is an error we provide you an exception type NetimAPIException
 * 
 * How to issue many commands more effectively
 * ===========================================
 * 
 * Previously we saw how to issue a simple command. Now we will look into issueing many commands sequentially.
 * 
 * Let's take an example, we want to create 2 contacts, look up info on 2 domains and look up infos on the contacts previously created
 * We could do it simply:
 * ```php
 * 		//creating contacts
 * 		try
 * 		{
 * 			$result1 = $client->contactCreate(...); //skipping needed parameters here for the sake of the example brevity
 * 			$result2 = $client->contactCreate(...);
 * 			
 * 			//asking for domain informations
 * 			$result3 = $client->domainInfo('myDomain.fr');
 * 			$result4 = $client->domainInfo('myDomain.com');
 * 		}
 * 		catch (NetimAPIException $exception)
 * 		{
 * 			//do something about the error
 * 		}
 * 		
 * 		//asking for contact informations
 * 		$result5 = $client->contactInfo($result1));
 * 		$result6 = $client->contactInfo($result2));
 * ```
 * 	
 * The connection is automatically closed when the script ends. However we recommend you to close the connection yourself when you won't use it
 * anymore like so : 
 * ```php
 * 		$client->sessionClose();
 * ```
 * The reason is that PHP calls the destructor only if it's running out of memory or when the script ends. If your script is running in a cron for
 * example, and it instanciates many APIRest objects without closing them, you may reach the limit of sessions you're allowed to open.
 */

namespace Upmind\ProvisionProviders\DomainNames\Netim\Helper\Api;

require_once __DIR__ . '/NetimAPIException.php';

use Upmind\ProvisionProviders\DomainNames\Netim\Helper\Api\NetimAPIException as NetimAPIException;
use stdClass;

class APIRest
{
    private $_connected;
    private $_sessionID;

    private $_userID;
    private $_secret;
    private $_apiURL;
    private $_defaultLanguage;

    private $_lastRequestParams;
    private $_lastRequestRessource;
    private $_lastHttpVerb;
    private $_lastHttpStatus;
    private $_lastResponse;
    private $_lastError;

    /**
     * Constructor for class APIRest
     *
     * @param string $userID the ID the client uses to connect to his NETIM account
     * @param string $secret the SECRET the client uses to connect to his NETIM account
     *	 
     * @throws Error if $userID, $secret or $apiURL are not string or are empty
     * 
     * @link semantic versionning http://semver.org/ by Tom Preston-Werner 
     */
    public function __construct(string $userID = null, string $secret = null, $url)
    {
        register_shutdown_function([&$this, "__destruct"]);
        // Init variables
        $this->_connected = false;
        $this->_sessionID = null;

        // $confpath = dirname(__FILE__) . "/conf.xml";
        // if (!file_exists($confpath))
        //     throw new NetimAPIException("Missing conf.xml file.");

        // $conf = get_object_vars(simplexml_load_file($confpath));

        // if (is_null($userID) && is_null($secret)) //No parameters
        // {
        //     if (!array_key_exists('login', $conf) || empty($conf['login']))
        //         throw new NetimAPIException("Missing or empty <login> in conf file.");

        //     if (!array_key_exists('secret', $conf) || empty($conf['secret']))
        //         throw new NetimAPIException("Missing or empty <secret> in conf file.");

        //     $this->_userID = trim($conf['login']);
        //     $this->_secret = trim($conf['secret']);
        // } else //With parameters
        // {
        //     if (empty($userID))
        //         throw new NetimAPIException("Missing \$userID.");

        //     if (empty($secret))
        //         throw new NetimAPIException("Missing \$secret.");

        // $this->_userID = $userID;
        // $this->_secret = $secret;
        // }

        // if (!array_key_exists('url', $conf) || empty($conf['url']))
        //     throw new NetimAPIException("Missing or empty <url> in conf file.");

        // $this->_apiURL = $conf['url'];


        // if (in_array($conf['language'], array("EN", "FR")))
        //     $this->_defaultLanguage = $conf['language'];
        // else
        if (isset($userID) && isset($secret) && isset($url)) {
            $this->_userID = $userID;
            $this->_secret = $secret;
            $this->_apiURL = $url;
            $this->_defaultLanguage = "EN";
        } else
            throw new NetimAPIException("Error in configuration, please check your inputs.");
    }

    public function __destruct()
    {
        if ($this->_connected && isset($this->_sessionID)) {
            $this->sessionClose();
        }
    }

    # ---------------------------------------------------
    # GETTER
    # ---------------------------------------------------
    public function getLastRequestParams()
    {
        return $this->_lastRequestParams;
    }
    public function getLastRequestRessource()
    {
        return $this->_lastRequestRessource;
    }
    public function getLastHttpVerb()
    {
        return $this->_lastHttpVerb;
    }
    public function getLastHttpStatus()
    {
        return $this->_lastHttpStatus;
    }
    public function getLastResponse()
    {
        return $this->_lastResponse;
    }
    public function getLastError()
    {
        return $this->_lastError;
    }
    public function getUserID()
    {
        return $this->_userID;
    }
    public function getUserPassword()
    {
        return $this->_secret;
    }
    # ---------------------------------------------------
    # PRIVATE UTILITIES
    # ---------------------------------------------------

    /**
     * Launches a function of the API, abstracting the connect/disconnect part to one place
     *
     * Example 1: API command returning a StructOperationResponse
     *
     *	return $this->call("/contacts/$idContactToDelete", 'DELETE');
     *
     * Example 2: API command that takes many args
     *
     *	$params['host'] = $host;
     *	$params['ipv4'] = $ipv4;
     *	$params['ipv6'] = $ipv6;
     *	return $this->call('/hosts', 'POST', $params);
     *
     *
     * @param string $ressource name of a ressource in the API
     * @param string $httpVerb the http verb for the request ('GET', 'POST', 'PUT', 'PATCH', 'DELETE')
     * @param array $params the parameters of $ressource in an indexed array.
     *
     * @throws NetimAPIException
     *
     * @return mixed the result of the call of $ressource with parameters $params and http verb $httpVerb
     *
     * @see curl https://www.php.net/manual/en/function.curl-init.php
     *                           https://www.php.net/manual/en/function.curl-setopt.php
     * @see json_decode https://www.php.net/manual/en/function.json-decode.php
     */
    public function call(string $ressource, string $httpVerb, array $params = array())
    {
        require_once __DIR__ . '/const.php';
        $httpVerb = strtoupper($httpVerb);

        $this->_lastRequestRessource = $ressource;
        $this->_lastRequestParams = $params;
        $this->_lastHttpVerb = $httpVerb;
        $this->_lastHttpStatus = "";
        $this->_lastResponse = "";
        $this->_lastError = "";

        $params['source'] = 'UPMIND=,PLUGIN=' . NETIM_MODULE_VERSION;

        try {

            //login		
            if (!$this->_connected) {
                if ($this->isSessionClose($ressource, $httpVerb)) //If already disconnected, just return.
                    return;
                elseif (!$this->isSessionOpen($ressource, $httpVerb)) // If not connected and running sessionOpen, don't fall in an endless loop.
                    $this->sessionOpen();
            } elseif ($this->_connected && $this->isSessionOpen($ressource, $httpVerb))
                return;

            if ($this->isSessionOpen($ressource, $httpVerb))
                $header = ["Accept-Language: $this->_defaultLanguage", "Authorization: Basic " . base64_encode("$this->_userID:$this->_secret"), "Content-Type: application/json"];
            else
                $header = ["Authorization: Bearer $this->_sessionID", "Content-type: application/json"];

            //Call the REST ressource
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->_apiURL . $ressource);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpVerb);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $json = curl_exec($ch);
            $result = json_decode($json, true);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->_lastHttpStatus = $status_code;

            if ($this->isSessionClose($ressource, $httpVerb)) {
                if ($status_code == 200 || $status_code == 401) {
                    unset($this->_sessionID);
                    $this->_connected = false;
                } else {
                    if (array_key_exists("message", $result))
                        throw new NetimAPIException($result['message']);
                    else
                        throw new NetimAPIException("");
                }
            } elseif ($this->isSessionOpen($ressource, $httpVerb)) {
                if ($status_code == 200) {
                    $this->_sessionID = $result['access_token'];
                    $this->_connected = true;
                } else {
                    if (array_key_exists("message", $result))
                        throw new NetimAPIException($result['message']);
                    else
                        throw new NetimAPIException("");
                }
            } else {
                if (!preg_match('/^2/', strval($status_code))) // Code doesn't start with "2xx"
                {
                    if ($status_code == 401) {
                        unset($this->_sessionID);
                        $this->_connected = false;
                    }
                    if (array_key_exists("message", $result ?? array()))
                        throw new NetimAPIException($result['message']);
                    else
                        throw new NetimAPIException("" . $this->getLastHttpStatus());
                }
            }

            if (is_array($result))
                $this->_lastResponse = (object) $result;
            else
                $this->_lastResponse = $result;
        } catch (NetimAPIException $exception) {
            $this->_lastError = $exception->getMessage();
            throw $exception;
        }

        if (is_array($result)) {
            if (!$this->isObject($result)) {
                foreach ($result as $key => $value) {
                    $result[$key] = (object) $value;
                }
                return $result;
            }
            return (object) $result;
        } else
            return  $result;
    }

    /**
     * @param array $arr content returned by the server
     * 
     * @return bool true : structure is an object
     * 				false : structure is an array of objects (or empty array)    
     */
    private function isObject(array $arr)
    {

        if (array() === $arr) return false; // Empty array
        if (array_keys($arr) !== range(0, count($arr) - 1)) {
            $res = false;
            foreach ($arr as $key => $value) {
                if (!is_array($value)) $res = true;
            }
            return $res;  // false : keys are not integers, all their childs are arrays so it's still an array
        } else return false; // Keys are integers so it is an array
    }

    private function isSessionOpen(string $ressource, string $httpVerb): bool
    {
        return (($ressource == "/session" || $ressource == "/session/" || $ressource == "session/" || $ressource == "session") && $httpVerb == "POST");
    }

    private function isSessionClose(string $ressource, string $httpVerb): bool
    {
        return (($ressource == "/session" || $ressource == "/session/" || $ressource == "session/" || $ressource == "session") && $httpVerb == "DELETE");
    }



    # -------------------------------------------------
    # SESSION
    # -------------------------------------------------	
    /**
     * Opens a session with REST
     *
     * @param string $lang OPTIONAL a language to define which error message you'll get. Support 'EN' and 'FR' for english and french respectively.
     *
     * @throws NetimAPIException
     */
    public function sessionOpen(): void
    {
        $this->call("session/", "POST");
    }

    /**
     * Close the session.
     */
    public function sessionClose(): void
    {
        $this->call("session/", "DELETE");
    }

    /**
     * Return the information of the current session. 
     *
     * @throws NetimAPIException
     * 
     * @return StructSessionInfo A structure StructSessionInfo
     *
     * @see sessionInfo API https://support.netim.com/en/wiki/SessionInfo
     */
    public function sessionInfo(): stdClass
    {
        return $this->call("session/", "GET");
    }

    /**
     * Returns all active sessions linked to the reseller account. 
     *
     * @throws NetimAPIException
     *
     * @return StructSessionInfo[] An array of StructSessionInfo
     *
     * @see queryAllSessions API https://support.netim.com/en/wiki/QueryAllSessions
     */
    public function queryAllSessions(): array
    {
        return $this->call("sessions/", "GET");
    }

    /**
     * Updates the settings of the current session. 
     *
     * @param string $type Setting to be modified : lang
     *                                              sync
     * @param string $value New value of the Setting : lang = EN / FR
     *                                                 sync = 0 (for asynchronous) / 1 (for synchronous) 
     * @throws NetimAPIException
     *
     * @see sessionSetPreference API https://support.netim.com/en/wiki/SessionSetPreference
     */
    public function sessionSetPreference(string $type, string $value): void
    {
        $this->call("session/", "PATCH", array("type" => $type, "value" => $value));
    }

    /**
     * Returns a welcome message
     *
     * Example
     * ```php
     *	try
     *	{
     *		$res = $client->hello();
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing```
     *
     * @return string a welcome message
     * 
     * @throws NetimAPIException
     *
     * @see hello API http://support.netim.com/en/wiki/Hello
     */
    public function hello(): string
    {
        return $this->call("hello/", "GET");
    }

    /**
     * Returns the list of parameters reseller account
     *
     *
     * @return StructQueryResellerAccount A structure of StructQueryResellerAccount containing the information
     * 
     * @throws NetimAPIException
     *
     * @see queryResellerAccount API https://support.netim.com/en/wiki/QueryResellerAccount
     */
    public function queryResellerAccount(): stdClass
    {
        return $this->call("account/", "GET");
    }

    # -------------------------------------------------
    # CONTACT
    # -------------------------------------------------	        

    /**
     * Creates a contact
     *
     * Example1: non-owner
     *	```php
     *	//we create a contact as a non-owner 
     *	$id = null;
     *	try
     *	{
     *		$contact = array(
     *	 		'firstName'=> 'barack',
     *			'lastName' => 'obama',
     *			'bodyForm' => 'IND',
     *			'bodyName' => '',
     *			'address1' => '1600 Pennsylvania Ave NW',
     *			'address2' => '',
     *			'zipCode'  => '20500',
     *			'area'	   => 'DC',
     *			'city'	   => 'Washington',
     *			'country'  => 'US',
     *			'phone'	   => '2024561111',
     *			'fax'	   => '',
     *			'email'    => 'barack.obama@gov.us',
     *			'language' => 'EN',
     *			'isOwner'  => 0
     *		);
     *		$id = $client->contactCreate($contact);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something about the error
     *	}
     *
     *	//continue processing
     *	```
     *
     * Example2: owner
     *	```php	
     *	$id = null;
     *	try
     *	{
     *	 	$contact = array(
     *	 		'firstName'=> 'bill',
     *			'lastName' => 'gates',
     *			'bodyForm' => 'IND',
     *			'bodyName' => '',
     *			'address1' => '1 hollywood bvd',
     *			'address2' => '',
     *			'zipCode'  => '18022',
     *			'area'	   => 'LA',
     *			'city'	   => 'Los Angeles',
     *			'country'  => 'US',
     *			'phone'	   => '2024531111',
     *			'fax'	   => '',
     *			'email'    => 'bill.gates@microsoft.com',
     *			'language' => 'EN',
     *			'isOwner'  => 1
     *		);
     *		$id = $client->contactCreate($contact);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something about the error
     *	}
     *
     *	//continue processing
     *	```
     * @param StructContact $contact the contact to create
     *
     * @throws NetimAPIException
     *
     * @return string the ID of the contact
     *
     * @see StructContact http://support.netim.com/en/wiki/StructContact
     */
    public function contactCreate(array $contact): string
    {
        $params = array();
        $params["contact"] = $contact;
        return $this->call("contact/", "POST", $params);
    }

    /**
     * Returns all informations about a contact object
     *
     * Example:
     *	```php
     *	$idContact = 'BJ007';
     *	$res = null;
     *	try 
     *	{
     *		$res = $client->contactInfo($idContact);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something about the error
     *	}
     *	$contactInfo = $res;
     *	//continue processing
     *	```
     * @param string $idContact ID of the contact to be queried
     *
     * @throws NetimAPIException
     *
     * @return StructContactReturn information on the contact
     *
     * @see contactInfo API http://support.netim.com/en/wiki/ContactInfo
     * @see StructContactReturn API http://support.netim.com/en/wiki/StructContactReturn
     */
    public function contactInfo(string $idContact): stdClass
    {
        return $this->call("contact/$idContact", "GET");
    }

    /**
     * Edit contact details
     *
     * Example: 
     *	```php
     *	//we update a contact as a non-owner 
     *	$res = null;
     *	try {
     *	 	$contact = array(
     *	 		'firstName'=> 'donald',
     *			'lastName' => 'trump',
     *			'bodyForm' => 'IND',
     *			'bodyName' => '',
     *			'address1' => '1600 Pennsylvania Ave NW',
     *			'address2' => '',
     *			'zipCode'  => '20500',
     *			'area'	   => 'DC',
     *			'city'	   => 'Washington',
     *			'country'  => 'US',
     *			'phone'	   => '2024561111',
     *			'fax'	   => '',
     *			'email'    => 'donald.trump@gov.us',
     *			'language' => 'EN',
     *			'isOwner'  => 0
     *		);
     *		$res = $client->contactUpdate($idContact, $contact);   
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     * ```
     *
     * @param string $idContact the ID of the contact to be updated
     * @param StructContact $contact the contact object containing the new values
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see contactUpdate API http://support.netim.com/en/wiki/ContactUpdate
     */
    public function contactUpdate(string $idContact, array $contact): stdClass
    {
        $params = array();
        $params["contact"] = $contact;
        return $this->call("contact/$idContact", "PATCH", $params);
    }

    /**
     * Edit contact details (for owner only) 
     *
     * Example
     *	```php
     *	//we update a owner contact
     *	$res = null;
     *	try
     *	{
     *			$contact = array(
     *	 		'firstName'=> 'elon',
     *			'lastName' => 'musk',
     *			'bodyForm' => 'IND',
     *			'bodyName' => '',
     *			'address1' => '1 hollywood bvd',
     *			'address2' => '',
     *			'zipCode'  => '18022',
     *			'area'	   => 'LA',
     *			'city'	   => 'Los Angeles',
     *			'country'  => 'US',
     *			'phone'	   => '2024531111',
     *			'fax'	   => '',
     *			'email'    => 'elon.musk@tesla.com',
     *			'language' => 'EN',
     *			'isOwner'  => 1
     *		);
     *		$res = $client->contactOwnerUpdate($idContact, $contact); 
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $idContact the ID of the contact to be updated
     * @param StructOwnerContact $contact the contact object containing the new values
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see contactOwnerUpdate API http://support.netim.com/en/wiki/ContactOwnerUpdate
     * @see StructOwnerContact http://support.netim.com/en/wiki/StructOwnerContact
     * 
     */
    public function contactOwnerUpdate(string $idContact, array $datas)
    {
        return $this->contactUpdate($idContact, $datas);
    }

    /**
     * Deletes a contact object 
     *
     * Example1:
     *	```php
     *	$contactID = 'BJ007';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->contactDelete($contactID);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $idContact ID of the contact to be deleted
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see contactDelete API http://support.netim.com/en/wiki/ContactDelete
     * @see StructOperationResponse API http://support.netim.com/en/wiki/StructOperationResponse
     */
    public function contactDelete(string $idContact): stdClass
    {
        return $this->call("contact/$idContact", "DELETE");
    }

    /**
     * Query informations about the state of an operation
     *
     * Example
     *	```php	
     *	$domain = 'myDomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainAuthID($domain, 0);
     *		$try = 0;
     *		while($try < 10 && $res->STATUS=="Pending")
     *		{	
     *			// The operation is pending, we will wait at most 10sec to see if the operation status change
     *			// and check every second if it changes
     *			sleep(1); 
     *			$try++;
     *			$res = $client->queryOpe()
     *		}
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param int $operationID The id of the operation requested
     * 
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see queryOpe API http://support.netim.com/en/wiki/QueryOpe
     */
    public function queryOpe(int $idOpe): stdClass
    {
        return $this->call("operation/$idOpe", "GET");
    }

    /**
     * Cancel a pending operation
     * @warning Depending on the current status of the operation, the cancellation might not be possible
     * 
     * @param int $idOpe Tracking ID of the operation
     * 
     * @throws NetimAPIException
     *
     * @see cancelOpe http://support.netim.com/en/wiki/CancelOpe
     */
    public function cancelOpe(int $idOpe): void
    {
        $this->call("operation/$idOpe/cancel/", "PATCH");
    }

    /**
     * Returns the status (opened/closed) for all operations for the extension 
     * 
     * @param string $tld Extension (uppercase without dot)
     * 
     * @throws NetimAPIException
     * 
     * @return object An associative array with (Name of the operation, boolean active)
     * 
     * @see queryOpeList API https://support.netim.com/en/wiki/QueryOpeList
     * 
     */
    public function queryOpeList(string $tld): stdClass
    {
        return $this->call("tld/$tld/operations/", "GET");
    }

    /**
     * Returns the list of pending operations processing 
     * 
     * @throws NetimAPIException
     * 
     * @return StructQueryOpePending[]  the list of pending operations processing 
     * 
     * @see queryOpePending API https://support.netim.com/en/wiki/QueryOpePending
     * 
     */
    public function queryOpePending(): array
    {
        return $this->call('operations/pending/', "GET");
    }

    /**
     * Returns the list of pending operations processing 
     * 
     * @throws NetimAPIException
     * 
     * @return StructContactList[] the list of contacts associated to the account
     * 
     * @see queryContactList API https://support.netim.com/en/wiki/QueryContactList
     * 
     */
    public function queryContactList(string $filter = "", string $field = ""): array
    {
        if (empty($filter) && empty($field))
            return $this->call("contacts/", "GET");
        else
            return $this->call("contacts/$field/$filter/", "GET");
    }

    # -------------------------------------------------
    # HOST
    # -------------------------------------------------
    /**
     * Creates a new host at the registry
     *
     * Example
     *	```php
     *	$host = 'ns1.mydomain.com';
     *	$ipv4 = array('10.11.12.13');
     *	$ipv6 = array();
     *	$res = null;
     *	try
     *	{
     *		$res =  $client->hostCreate($host, $ipv4, $ipv6);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $host hostname
     * @param array $ipv4 Must contain ipv4 adresses as strings
     * @param array $ipv6 Must contain ipv6 adresses as strings
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see hostCreate API http://support.netim.com/en/wiki/HostCreate
     */
    public function hostCreate(string $host, array $ipv4, array $ipv6): stdClass
    {
        $params["host"] = $host;
        $params["ipv4"] = $ipv4;
        $params["ipv6"] = $ipv6;
        return $this->call("host/", "POST", $params);
    }

    /**
     * Deletes an Host at the registry 
     *
     * Example
     *	```php
     *	$host = 'ns1.mydomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->hostDelete($host);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $host hostname to be deleted
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see hostDelete API http://support.netim.com/en/wiki/HostDelete
     */
    public function hostDelete(string $host): stdClass
    {
        return $this->call("host/$host", "DELETE");
    }

    /**
     * Updates a host at the registry 
     *
     * Example
     *	```php
     *	$host = 'ns1.myDomain.com';
     *	$ipv4 = array('10.12.13.11');
     *	$ipv6 = array();
     *	$res = null;
     *	try
     *	{
     *		$res = $client->hostUpdate($host, $ipv4, $ipv6);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $host string hostname
     * @param array $ipv4 Must contain ipv4 adresses as strings
     * @param array $ipv6 Must contain ipv6 adresses as strings
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see hostUpdate API http://support.netim.com/en/wiki/HostUpdate
     */
    public function hostUpdate(string $host, array $ipv4, array $ipv6): stdClass
    {
        $params["ipv4"] = $ipv4;
        $params["ipv6"] = $ipv6;
        return $this->call("host/$host", "PATCH", $params);
    }

    /**
     * @param string $filter The filter applies onto the host name 
     * 
     * @throws NetimAPIException
     *
     * @return array An array of StructHostList
     *
     * @see queryHostList API http://support.netim.com/en/wiki/QueryHostList
     */
    public function queryHostList(string $filter): array
    {
        return $this->call("hosts/$filter", "GET");
    }

    /**
     * Checks if domain names are available for registration   
     *
     *  
     * Example: Check one domain name
     *	```php
     *	$domain = "myDomain.com";
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainCheck($domain);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something about the error
     *	}
     *	$domainCheckResponse = $res[0];
     *	//continue processing
     *	```
     * @param string $domain Domain names to be checked 
     * You can provide several domain names separated with semicolons. 
     * Caution : 
     *	- you can't mix different extensions during the same call 
     *	- all the extensions don't accept a multiple checkDomain. See HasMultipleCheck in Category:Tld
     *
     * @throws NetimAPIException
     *
     * @return array An array of StructDomainCheckResponse
     * 
     * @see StructDomainCheckResponse http://support.netim.com/en/wiki/StructDomainCheckResponse
     * @see DomainCheck API http://support.netim.com/en/wiki/DomainCheck
     */
    public function domainCheck(string $domain): array
    {
        return $this->call("domain/$domain/check/", "GET");
    }

    /**
     * Requests a new domain registration 
     *
     * Example:
     *	```php
     *	$domain = 'myDomain.com';
     *	$idOwner = 'BJ008';
     *	$idAdmin = 'BJ007';
     *	$idTech = 'BJ007';
     *	$idBilling = 'BJ007';
     *	$ns1 = 'ns1.netim.com';
     *	$ns2 = 'ns2.netim.com';
     *	$ns3 = 'ns3.netim.com'; 
     *	$ns4 = 'ns4.netim.com';
     *	$ns5 = 'ns5.netim.com';
     *	$duration = 1;
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainCreate($domain, $idOwner, $idAdmin, $idTech, $idBilling, $ns1, $ns2, $ns3, $ns4, $ns5, $duration);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $domain the name of the domain to create
     * @param string $idOwner the id of the owner for the new domain
     * @param string $idAdmin the id of the admin for the new domain
     * @param string $idTech the id of the tech for the new domain
     * @param string $idBilling the id of the billing for the new domain
     *                          To get an ID, you can call contactCreate() with the appropriate information
     * @param string $ns1 the name of the first dns
     * @param string $ns2 the name of the second dns
     * @param string $ns3 the name of the third dns
     * @param string $ns4 the name of the fourth dns
     * @param string $ns5 the name of the fifth dns
     * @param int $duration how long the domain will be created
     * @param int $templateDNS OPTIONAL number of the template DNS created on netim.com/direct
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainCreate API http://support.netim.com/en/wiki/DomainCreate 
     */
    public function domainCreate(string $domain, string $idOwner, string $idAdmin, string $idTech, string $idBilling, string $ns1, string $ns2, string $ns3, string $ns4, string $ns5, int $duration, int $templateDNS = null): stdClass
    {
        $domain = strtolower($domain);

        $params["idOwner"] = $idOwner;
        $params["idAdmin"] = $idAdmin;
        $params["idTech"] = $idTech;
        $params["idBilling"] = $idBilling;

        $params["ns1"] = $ns1;
        $params["ns2"] = $ns2;
        $params["ns3"] = $ns3;
        $params["ns4"] = $ns4;
        $params["ns5"] = $ns5;

        $params["duration"] = $duration;

        if (!empty($templateDNS))
            $params["templateDNS"] = $templateDNS;

        return $this->call("domain/$domain/", "POST", $params);
    }

    /**
     * Returns all informations about a domain name 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainInfo($domain);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something about the error
     *	}
     *
     *	$domainInfo = $res;
     *	//continue processing
     *	```
     * @param string $domain name of the domain
     *
     * @throws NetimAPIException
     * 
     * @return StructDomainInfo information about the domain
     *
     * @see domainInfo API http://support.netim.com/en/wiki/DomainInfo
     */
    public function domainInfo(string $domain): stdClass
    {
        return $this->call("domain/$domain/info/", "GET");
    }

    /**
     * Requests a new domain registration during a launch phase
     *
     * Example:
     *	```php
     *	$domain = 'myDomain.com';
     *	$idOwner = 'BJ008';
     *	$idAdmin = 'BJ007';
     *	$idTech = 'BJ007';
     *	$idBilling = 'BJ007';
     *	$ns1 = 'ns1.netim.com';
     *	$ns2 = 'ns2.netim.com';
     *	$ns3 = 'ns3.netim.com';
     *	$ns4 = 'ns4.netim.com';
     *	$ns5 = 'ns5.netim.com';
     *	$duration = 1;
     *	$phase = 'GA';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainCreateLP($domain, $idOwner, $idAdmin, $idTech, $idBilling, $ns1, $ns2, $ns3, $ns4, $ns5, $duration, $phase);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $domain the name of the domain to create
     * @param string $idOwner the id of the owner for the new domain
     * @param string $idAdmin the id of the admin for the new domain
     * @param string $idTech the id of the tech for the new domain
     * @param string $idBilling the id of the billing for the new domain
     *                          To get an ID, you can call contactCreate() with the appropriate information
     * @param string $ns1 the name of the first dns
     * @param string $ns2 the name of the second dns
     * @param string $ns3 the name of the third dns
     * @param string $ns4 the name of the fourth dns
     * @param string $ns5 the name of the fifth dns
     * @param int $duration how long the domain will be created
     * @param string $phase the id of the launch phase
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainCreateLP API http://support.netim.com/en/wiki/DomainCreateLP 
     */
    public function domainCreateLP(string $domain, string $idOwner, string $idAdmin, string $idTech, string $idBilling, string $ns1, string $ns2, string $ns3, string $ns4, string $ns5, int $duration, string $phase): stdClass
    {
        $domain = strtolower($domain);

        $params["idOwner"] = $idOwner;
        $params["idAdmin"] = $idAdmin;
        $params["idTech"] = $idTech;
        $params["idBilling"] = $idBilling;

        $params["ns1"] = $ns1;
        $params["ns2"] = $ns2;
        $params["ns3"] = $ns3;
        $params["ns4"] = $ns4;
        $params["ns5"] = $ns5;

        $params["duration"] = $duration;

        $params["launchPhase"] = $phase;

        return $this->call("domain/$domain/lp/", "POST", $params);
    }

    /**
     * Deletes immediately a domain name 
     * 
     * Example:
     *	```php
     *	$domain = 'myDomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainDelete($domain);
     *		//equivalent to $res = $client->domainDelete($domain, 'NOW');
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $domain the name of the domain to delete
     * @param string $typeDeletion OPTIONAL if the deletion is to be done now or not. Only supported value as of 2.0 is 'NOW'.
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainDelete API http://support.netim.com/en/wiki/DomainDelete
     */
    public function domainDelete(string $domain, string $typeDelete = 'NOW'): stdClass
    {
        $params["typeDelete"] = strtoupper($typeDelete);

        return $this->call("domain/$domain/", "DELETE", $params);
    }

    /**
     * Requests the transfer of a domain name to Netim 
     *
     * Example:
     *	```php
     *	$domain = 'myDomain.com';
     *	$authID = 'qlskjdlqkxlxkjlqksjdlkj';
     *	$idOwner = 'BJ008';
     *	$idAdmin = 'BJ007';
     *	$idTech = 'BJ007';
     *	$idBilling = 'BJ007';
     *	$ns1 = 'ns1.netim.com';
     *	$ns2 = 'ns2.netim.com';
     *	$ns3 = 'ns3.netim.com'; 
     *	$ns4 = 'ns4.netim.com';
     *	$ns5 = 'ns5.netim.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainTransferIn($domain, $authID, $idOwner, $idAdmin, $idTech, $idBilling, $ns1, $ns2, $ns3, $ns4, $ns5);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $domain name of the domain to transfer
     * @param string $authID authorisation code / EPP code (if applicable)
     * @param string $idOwner a valid idOwner. Can also be #AUTO#
     * @param string $idAdmin a valid idAdmin
     * @param string $idTech a valid idTech
     * @param string $idBilling a valid idBilling
     * @param string $ns1 the name of the first dns
     * @param string $ns2 the name of the second dns
     * @param string $ns3 the name of the third dns
     * @param string $ns4 the name of the fourth dns
     * @param string $ns5 the name of the fifth dns
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainTransferIn API http://support.netim.com/en/wiki/DomainTransferIn
     */
    public function domainTransferIn(string $domain, string $authID, string $idOwner, string $idAdmin, string $idTech, string $idBilling, string $ns1, string $ns2, string $ns3, string $ns4, string $ns5): stdClass
    {
        $domain = strtolower($domain);

        $params["authID"] = $authID;

        $params["idOwner"] = $idOwner;
        $params["idAdmin"] = $idAdmin;
        $params["idTech"] = $idTech;
        $params["idBilling"] = $idBilling;

        $params["ns1"] = $ns1;
        $params["ns2"] = $ns2;
        $params["ns3"] = $ns3;
        $params["ns4"] = $ns4;
        $params["ns5"] = $ns5;

        return $this->call("domain/$domain/transfer/", "POST", $params);
    }

    /**
     * Requests the transfer (with change of domain holder) of a domain name to Netim 
     *
     * Example:
     *	```php
     *	$domain = 'myDomain.com';
     *	$authID = 'qlskjdlqkxlxkjlqksjdlkj';
     *	$idOwner = 'BJ008';
     *	$idAdmin = 'BJ007';
     *	$idTech = 'BJ007';
     *	$idBilling = 'BJ007';
     *	$ns1 = 'ns1.netim.com';
     *	$ns2 = 'ns2.netim.com';
     *	$ns3 = 'ns3.netim.com'; 
     *	$ns4 = 'ns4.netim.com';
     *	$ns5 = 'ns5.netim.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainTransferTrade($domain, $authID, $idOwner, $idAdmin, $idTech, $idBilling, $ns1, $ns2, $ns3, $ns4, $ns5);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * 
     * @param string $domain name of the domain to transfer
     * @param string $authID authorisation code / EPP code (if applicable)
     * @param string $idOwner a valid idOwner.
     * @param string $idAdmin a valid idAdmin
     * @param string $idTech a valid idTech
     * @param string $idBilling a valid idBilling
     * @param string $ns1 the name of the first dns
     * @param string $ns2 the name of the second dns
     * @param string $ns3 the name of the third dns
     * @param string $ns4 the name of the fourth dns
     * @param string $ns5 the name of the fifth dns
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainTransferTrade API http://support.netim.com/en/wiki/domainTransferTrade
     */
    public function domainTransferTrade(string $domain, string $authID, string $idOwner, string $idAdmin, string $idTech, string $idBilling, string $ns1, string $ns2, string $ns3, string $ns4, string $ns5): stdClass
    {
        $domain = strtolower($domain);

        $params["authID"] = $authID;

        $params["idOwner"] = $idOwner;
        $params["idAdmin"] = $idAdmin;
        $params["idTech"] = $idTech;
        $params["idBilling"] = $idBilling;

        $params["ns1"] = $ns1;
        $params["ns2"] = $ns2;
        $params["ns3"] = $ns3;
        $params["ns4"] = $ns4;
        $params["ns5"] = $ns5;

        return $this->call("domain/$domain/transfer-trade/", "POST", $params);
    }

    /**
     * Requests the internal transfer of a domain name from one Netim account to another. 
     *
     * Example:
     *	```php
     *	$domain = 'myDomain.com';
     *	$authID = 'qlskjdlqkxlxkjlqksjdlkj';
     *	$idAdmin = 'BJ007';
     *	$idTech = 'BJ007';
     *	$idBilling = 'BJ007';
     *	$ns1 = 'ns1.netim.com';
     *	$ns2 = 'ns2.netim.com';
     *	$ns3 = 'ns3.netim.com'; 
     *	$ns4 = 'ns4.netim.com';
     *	$ns5 = 'ns5.netim.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainTransferTrade($domain, $authID, $idAdmin, $idTech, $idBilling, $ns1, $ns2, $ns3, $ns4, $ns5);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * 
     * @param string $domain name of the domain to transfer
     * @param string $authID authorisation code / EPP code (if applicable)
     * @param string $idAdmin a valid idAdmin
     * @param string $idTech a valid idTech
     * @param string $idBilling a valid idBilling
     * @param string $ns1 the name of the first dns
     * @param string $ns2 the name of the second dns
     * @param string $ns3 the name of the third dns
     * @param string $ns4 the name of the fourth dns
     * @param string $ns5 the name of the fifth dns
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainInternalTransfer API http://support.netim.com/en/wiki/domainInternalTransfer
     */
    public function domainInternalTransfer(string $domain, string $authID, string $idAdmin, string $idTech, string $idBilling, string $ns1, string $ns2, string $ns3, string $ns4, string $ns5): stdClass
    {
        $params[] = strtolower($domain);
        $params["authID"] = $authID;

        $params["idAdmin"] = $idAdmin;
        $params["idTech"] = $idTech;
        $params["idBilling"] = $idBilling;

        $params["ns1"] = $ns1;
        $params["ns2"] = $ns2;
        $params["ns3"] = $ns3;
        $params["ns4"] = $ns4;
        $params["ns5"] = $ns5;

        return $this->call("domain/$domain/internal-transfer/", "PATCH", $params);
    }

    /**
     * Renew a domain name for a new subscription period 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com'
     *	$duration = 1;
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainCreate($domain, $duration)
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * 
     * @param string $domain the name of the domain to renew
     * @param int $duration the duration of the renewal expressed in year. Must be at least 1 and less than the maximum amount
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainRenew API  http://support.netim.com/en/wiki/DomainRenew
     */
    public function domainRenew(string $domain, int $duration): stdClass
    {
        $domain = strtolower($domain);
        $params["duration"] = $duration;

        return $this->call("domain/$domain/renew/", "PATCH", $params);
    }

    /**
     * Restores a domain name in quarantine / redemption status
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainRestore($domain);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * @param string $domain name of the domain
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainRestore API http://support.netim.com/en/wiki/DomainRestore
     */
    public function domainRestore(string $domain): stdClass
    {
        $domain = strtolower($domain);
        return $this->call("domain/$domain/restore/", "PATCH");
    }

    /**
     * Updates the settings of a domain name
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$codePref = 'registrar_lock': //possible values are 'whois_privacy', 'registrar_lock', 'auto_renew', 'tag' or 'note'
     *	$value = 1; // 1 or 0
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainSetPreference($domain, $codePref, $value);
     *		//equivalent to $res = $client->domainSetRegistrarLock($domain,$value); each codePref has a corresponding helping function
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $domain name of the domain
     * @param string $codePref setting to be modified. Accepted value are 'whois_privacy', 'registrar_lock', 'auto_renew', 'tag' or 'note'
     * @param string $value new value for the settings. 
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainSetPreference API http://support.netim.com/en/wiki/DomainSetPreference
     * @see domainSetWhoisPrivacy, domainSetRegistrarLock, domainSetAutoRenew, domainSetTag, domainSetNote
     */
    public function domainSetPreference(string $domain, string $codePref, string $value): stdClass
    {
        $domain = strtolower($domain);
        $params["codePref"] = $codePref;
        $params["value"] = $value;
        return $this->call("domain/$domain/preference/", "PATCH", $params);
    }

    /**
     * Requests the transfer of the ownership to another party
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$idOwner = 'BJ008';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainTransferOwner($domain, $idOwner);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $domain name of the domain
     * @param string $idOwner id of the new owner
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainTransferOwner API http://support.netim.com/en/wiki/DomainTransferOwner
     * @see function createContact
     */
    public function domainTransferOwner(string $domain, string $idOwner): stdClass
    {
        $domain = strtolower($domain);
        $params["idOwner"] = $idOwner;
        return $this->call("domain/$domain/transfer-owner/", "PUT", $params);
    }

    /**
     * Replaces the contacts of the domain (administrative, technical, billing) 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$idAdmin = 'BJ007';
     *	$idTech = 'BJ007';
     *	$idBilling = 'BJ007';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainChangeContact$domain, $idAdmin, $idTech, $idBilling);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * 
     * @param string $domain name of the domain
     * @param string $idAdmin id of the admin contact
     * @param string $idTech id of the tech contact
     * @param string $idBilling id of the billing contact
     * 
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainChangeContact API http://support.netim.com/en/wiki/DomainChangeContact
     * @see function createContact
     */
    public function domainChangeContact(string $domain, string $idAdmin, string $idTech, string $idBilling): stdClass
    {
        $domain = strtolower($domain);
        $params["idAdmin"] = $idAdmin;
        $params["idTech"] = $idTech;
        $params["idBilling"] = $idBilling;
        return $this->call("domain/$domain/contacts/", "PUT", $params);
    }

    /**
     * Replaces the DNS servers of the domain (redelegation) 
     * 
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$ns1 = 'ns1.netim.com';
     *	$ns2 = 'ns2.netim.com';
     *	$ns3 = 'ns3.netim.com';
     *	$ns4 = 'ns4.netim.com';
     *	$ns5 = 'ns5.netim.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainChangeDNS($domain, $ns1, $ns2, $ns3, $ns4, $ns5);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * 
     * @param string $domain name of the domain
     * @param string $ns1 the name of the first dns
     * @param string $ns2 the name of the second dns
     * @param string $ns3 the name of the third dns
     * @param string $ns4 the name of the fourth dns
     * @param string $ns5 the name of the fifth dns
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainChangeDNS API http://support.netim.com/en/wiki/DomainChangeDNS
     */
    public function domainChangeDNS(string $domain, string $ns1, string $ns2, string $ns3 = "", string $ns4 = "", string $ns5 = ""): stdClass
    {
        $domain = strtolower($domain);
        $params["ns1"] = $ns1;
        $params["ns2"] = $ns2;
        $params["ns3"] = $ns3;
        $params["ns4"] = $ns4;
        $params["ns5"] = $ns5;
        return $this->call("domain/$domain/dns/", "PUT", $params);
    }

    /**
     * Allows to sign a domain name with DNSSEC if it uses NETIM DNS servers 
     * 
     * @param string $domain name of the domain
     * @param int $enable New signature value 0 : unsign
     * 										1 : sign 
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainSetDNSsec API http://support.netim.com/en/wiki/DomainSetDNSsec
     */
    public function domainSetDNSSec(string $domain, int $enable): stdClass
    {
        $domain = strtolower($domain);
        $params["enable"] = $enable;
        return $this->call("domain/$domain/dnssec/", "PATCH", $params);
    }

    /**
     * Returns the authorization code to transfer the domain name to another registrar or to another client account 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainAuthID($domain, 0);
     *		//$res = $client->domainAuthID($domain, 1); to send the authID in an email to the registrant of the domain
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $domain name of the domain to get the AuthID
     * @param int $sendToRegistrant recipient of the AuthID. Possible value are 0 for the reseller and 1 for the registrant
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainAuthID API http://support.netim.com/en/wiki/DomainAuthID
     */
    public function domainAuthID(string $domain, int $sendToRegistrant): stdClass
    {
        $domain = strtolower($domain);
        $params["sendtoregistrant"] = $sendToRegistrant;
        return $this->call("domain/$domain/authid/", "PATCH", $params);
    }

    /**
     * Release a domain name (managed by the reseller) to its registrant (who will become a direct customer at Netim) 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainRelease($domain);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $domain domain name to be released
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainRelease API http://support.netim.com/en/wiki/DomainRelease
     */
    public function domainRelease(string $domain): stdClass
    {
        $domain = strtolower($domain);
        return $this->call("domain/$domain/release/", "PATCH");
    }

    /**
     * Adds a membership to the domain name 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com';
     *	$token = 'qmksjdmqsjdmkl'; //replace with your token here
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainSetMembership($domain, $token);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $domain name of domain
     * @param string $token membership number into the community
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainSetMembership API http://support.netim.com/en/wiki/DomainSetMembership
     */
    public function domainSetMembership(string $domain, string $token): stdClass
    {
        $domain = strtolower($domain);
        $params["token"] = $token;
        return $this->call("/domain/$domain/membership/", "PATCH", $params);
    }

    /**
     * Returns all available operations for a given TLD 
     * 
     * Example:
     *	```php
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainTldInfo("COM"); //or 'com'
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something about the error
     *	}
     *
     *	$domainInfo = $res;
     *	//continue processing
     *	```
     *	
     * @param string $tld a valid tld without the dot before it
     *
     * @throws NetimAPIException
     *
     * @return StructDomainTldInfo information about the tld
     *
     * @see domainTldInfo API http://support.netim.com/fr/wiki/DomainTldInfo
     */
    public function domainTldInfo(string $tld): stdClass
    {
        return $this->call("tld/$tld/", "GET");
    }

    /**
     * Returns whois informations on given domain
     * 
     * Example:
     *	```php
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainWhois("myDomain.com");
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something about the error
     *	}
     *
     *	//continue processing
     *	```
     *	
     * @param string $domain the domain's name
     *
     * @throws NetimAPIException
     *
     * @return string information about the domain
     */
    public function domainWhois(string $domain): string
    {
        $domain = strtolower($domain);
        return $this->call("/domain/$domain/whois/", "GET");
    }

    /**
     * Allows to sign a domain name with DNSSEC if it doesn't use NETIM DNS servers 
     * 
     * @param string 	$domain name of the domain
     * @param array		$DSRecords An object StructDSRecord
     * @param int 		$flags
     * @param int		$protocol
     * @param int		$algo
     * @param string	$pubKeys
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     * 
     * @see domainSetDNSSecExt API http://support.netim.com/en/wiki/DomainSetDNSSecExt
     */
    public function domainSetDNSSecExt(string $domain, array $DSRecords, int $flags, int $protocol, int $algo, string $pubKey): stdClass
    {
        $domain = strtolower($domain);
        $params["DSRecords"] = $DSRecords;
        $params["flags"] = $flags;
        $params["protocol"] = $protocol;
        $params["algo"] = $algo;
        $params["pubKey"] = $pubKey;
        return $this->call("/domain/$domain/dnssec/", "PATCH", $params);
    }

    /**
     * Returns the list of all prices for each tld 
     * 
     * @throws NetimAPIException
     * 
     * @return StructDomainPriceList[] 
     * 
     * @see domainPriceList API http://support.netim.com/en/wiki/DomainPriceList
     */
    public function domainPriceList(): array
    {
        return $this->call("/tlds/price-list/", "GET");
    }

    /**
     * Allows to know a domain's price 
     * 
     * @param string $domain name of domain
     * @param string $authID authorisation code (optional)
     * 
     * @throws NetimAPIException
     * 
     * @return StructQueryDomainPrice
     * 
     * @see queryDomainPrice API https://support.netim.com/en/wiki/QueryDomainPrice
     * 
     */
    public function queryDomainPrice(string $domain, string $authID = ""): stdClass
    {
        $params = array();
        $domain = strtolower($domain);
        if (!empty($authID)) $params["authId"] = $authID;
        return $this->call("/domain/$domain/price/", "GET", $params);
    }

    /**
     * Allows to know if there is a claim on the domain name 
     * 
     * @param string $domain name of domain
     * 
     * @throws NetimAPIException
     * 
     * @return int 0: no claim ; 1: at least one claim
     * 
     * @see queryDomainClaim API https://support.netim.com/en/wiki/QueryDomainClaim
     * 
     */
    public function queryDomainClaim(string $domain): int
    {
        $domain = strtolower($domain);
        return $this->call("/domain/$domain/claim/", "GET");
    }

    /**
     * Returns all domains linked to the reseller account.
     * 
     * @param string $filter Domain name
     * 
     * @throws NetimAPIException
     * 
     * @return array The filter applies onto the domain name
     *
     * @see queryDomainList API https://support.netim.com/en/wiki/QueryDomainList
     *
     */
    public function queryDomainList(string $filter = ""): array
    {
        return $this->call("/domains/$filter", "GET");
    }

    /**
     * Resets all DNS settings from a template 
     * 
     * @param string 	$domain Domain name
     * @param int 		$numTemplate Template number
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse
     * 
     * @see domainZoneInit API https://support.netim.com/en/wiki/DomainZoneInit
     * 
     */
    public function domainZoneInit(string $domain, int $numTemplate): stdClass
    {
        $domain = strtolower($domain);
        $params['numTemplate'] = $numTemplate;
        return $this->call("/domain/$domain/zone/init/", "PATCH", $params);
    }

    /**
     * Creates a DNS record into the domain zonefile
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com'
     *	$subdomain = 'www';
     *	$type = 'A';
     *	$value = '192.168.0.1';
     *	$options = array('service' => '', 'protocol' => '', 'ttl' => '3600', 'priority' => '', 'weight' => '', 'port' => '');
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainZoneCreate($domain, $subdomain, $type, $value, $options);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $domain name of the domain
     * @param string $subdomain subdomain
     * @param string $type type of DNS record. Accepted values are: 'A', 'AAAA', 'MX, 'CNAME', 'TXT', 'NS and 'SRV'
     * @param string $value value of the new DNS record
     * @param array $options StructOptionsZone : settings of the new DNS record 
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainZoneCreate API http://support.netim.com/en/wiki/DomainZoneCreate
     * @see StructOptionsZone http://support.netim.com/en/wiki/StructOptionsZone
     */
    public function domainZoneCreate(string $domain, string $subdomain, string $type, string $value, array $options): stdClass
    {
        $domain = strtolower($domain);
        $params["subdomain"] = $subdomain;
        $params["type"] = $type;
        $params["value"] = $value;
        $params["options"] = $options;
        return $this->call("/domain/$domain/zone/", "POST", $params);
    }

    /**
     * Deletes a DNS record into the domain's zonefile 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com'
     *	$subdomain = 'www';
     *	$type = 'A';
     *	$value = '192.168.0.1';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainZoneDelete($domain, $subdomain, $type, $value);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * 
     * @param string $domain name of the domain
     * @param string $subdomain subdomain
     * @param string $type type of DNS record. Accepted values are: 'A', 'AAAA', 'MX, 'CNAME', 'TXT', 'NS and 'SRV'
     * @param string $value value of the new DNS record
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     * 
     * @see domainZoneDelete API http://support.netim.com/en/wiki/DomainZoneDelete
     */
    public function domainZoneDelete(string $domain, string $subdomain, string $type, string $value): stdClass
    {
        $domain = strtolower($domain);
        $params["subdomain"] = $subdomain;
        $params["type"] = $type;
        $params["value"] = $value;
        return $this->call("/domain/$domain/zone/", "DELETE", $params);
    }

    /**
     * Resets the SOA record of a domain name 
     *
     * Example
     *	```php
     *	$domain = 'myDomain.com'
     *	$ttl = 24;
     *	$ttlUnit = 'H';
     *	$refresh = 24;
     *	$refreshUnit = 'H';
     *	$retry = 24;
     *	$retryUnit = 'H';
     *	$expire = 24;
     *	$expireUnit = 'H';
     *	$minimum = 24;
     *	$minimumUnit = 'H';
     *	
     *	try
     *	{
     *		$res = $client->domainZoneInitSoa($domain, $ttl, $ttlUnit, $refresh, $refreshUnit, $retry, $retryUnit, $expire, $expireUnit, $minimum, $minimumUnit);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     * 
     * @param string $domain name of the domain
     * @param int 	 $ttl time to live
     * @param string $ttlUnit TTL unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $refresh Refresh delay
     * @param string $refreshUnit Refresh unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $retry Retry delay
     * @param string $retryUnit Retry unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $expire Expire delay
     * @param string $expireUnit Expire unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $minimum Minimum delay
     * @param string $minimumUnit Minimum unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     * 
     * @see domainZoneInitSoa API http://support.netim.com/en/wiki/DomainZoneInitSoa
     */
    public function domainZoneInitSoa(string $domain, int $ttl, string $ttlUnit, int $refresh, string $refreshUnit, int $retry, string $retryUnit, int $expire, string $expireUnit, int $minimum, string $minimumUnit): stdClass
    {
        $domain = strtolower($domain);
        $params["ttl"] = $ttl;
        $params["ttlUnit"] = $ttlUnit;
        $params["refresh"] = $refresh;
        $params["refreshUnit"] = $refreshUnit;
        $params["retry"] = $retry;
        $params["retryUnit"] = $retryUnit;
        $params["expire"] = $expire;
        $params["expireUnit"] = $expireUnit;
        $params["minimum"] = $minimum;
        $params["minimumUnit"] = $minimumUnit;

        return $this->call("/domain/$domain/zone/init-soa/", "PATCH", $params);
    }

    /**
     * Returns all DNS records of a domain name 
     * 
     * @param string $domain Domain name
     * 
     * @throws NetimAPIException
     * 
     * @return array An array of StructQueryZoneList
     *
     * @see queryZoneList API https://support.netim.com/en/wiki/QueryZoneList
     *
     */
    public function queryZoneList(string $domain): array
    {
        $domain = strtolower($domain);
        return $this->call("/domain/$domain/zone/", "GET");
    }

    /**
     * Creates an email address forwarded to recipients
     *
     * Example
     *	```php
     *	$mailBox = 'example@myDomain.com';
     *	$recipients = 'address1@abc.com, address2@abc.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainMailFwdCreate($mailBox, $recipients);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $mailBox email adress (or * for a catch-all)
     * @param string $recipients string list of email adresses (separated by commas)
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainMailFwdCreate API http://support.netim.com/en/wiki/DomainMailFwdCreate
     */
    public function domainMailFwdCreate(string $mailBox, string $recipients): stdClass
    {
        $mailBox = strtolower($mailBox);
        $params["recipients"] = $recipients;
        return $this->call("/domain/$mailBox/mail-forwarding/", "POST", $params);
    }

    /**
     * Deletes an email forward
     *
     * Example
     *	```php
     *	$mailBox = 'example@myDomain.com';
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainMailFwdDelete($mailBox);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $mailBox email adress 
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainMailFwdDelete API http://support.netim.com/en/wiki/DomainMailFwdDelete
     */
    public function domainMailFwdDelete(string $mailBox): stdClass
    {
        $mailBox = strtolower($mailBox);
        return $this->call("/domain/$mailBox/mail-forwarding/", "DELETE");
    }

    /**
     * Returns all email forwards for a domain name
     * 
     * @param string $domain Domain name
     * 
     * @throws NetimAPIException
     * 
     * @return array An array of StructQueryMailFwdList
     * 
     * @see queryMailFwdList API https://support.netim.com/en/wiki/QueryMailFwdList
     */
    public function queryMailFwdList(string $domain): array
    {
        $domain = strtolower($domain);
        return $this->call("/domain/$domain/mail-forwardings/", "GET");
    }

    /**
     * Creates a web forwarding 
     *
     * Example
     *	```php
     *	$fqdn = 'subdomain.myDomain.com';
     *	$target = 'myDomain.com';
     *	$type = 'DIRECT';
     *	$options = $array('header'=>301, 'protocol'=>ftp, 'title'=>'', 'parking'=>'');
     *	
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainWebFwdCreate($fqdn, $target, $type, $options);
     *		//equivalent to $res = $client->domainWebFwdCreateTypeDirect($fqdn, $target, 301, 'ftp')
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $fqdn hostname (fully qualified domain name)
     * @param string $target target of the web forwarding
     * @param string $type type of the web forwarding. Accepted values are: "DIRECT", "IP", "MASKED" or "PARKING"
     * @param array $options contains StructOptionsFwd : settings of the web forwarding. An array with keys: header, protocol, title and parking.
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainWebFwdCreate API http://support.netim.com/en/wiki/DomainWebFwdCreate
     * @see StructOptionsFwd http://support.netim.com/en/wiki/StructOptionsFwd
     */
    public function domainWebFwdCreate(string $fqdn, string $target, string $type, array $options): stdClass
    {
        $params["target"] = $target;
        $params["type"] = strtoupper($type);
        $params["options"] = $options;
        return $this->call("/domain/$fqdn/web-forwarding/", "POST", $params);
    }

    /**
     * Removes a web forwarding 
     *
     * Example
     *	```php
     *	$fqdn = 'subdomain.myDomain.com'
     *	$res = null;
     *	try
     *	{
     *		$res = $client->domainWebFwdDelete($fqdn);
     *	}
     *	catch (NetimAPIexception $exception)
     *	{
     *		//do something when operation had an error
     *	}
     *	//continue processing
     *	```
     *
     * @param string $fqdn hostname, a fully qualified domain name
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainWebFwdDelete API http://support.netim.com/en/wiki/DomainWebFwdDelete
     */
    public function domainWebFwdDelete(string $fqdn): stdClass
    {
        return $this->call("/domain/$fqdn/web-forwarding/", "DELETE");
    }

    /**
     * Return all web forwarding of a domain name 
     * 
     * @param string $domain Domain name
     * 
     * @throws NetimAPIException
     * 
     * @return array An array of StructQueryWebFwdList
     *
     * @see queryWebFwdList API https://support.netim.com/en/wiki/QueryWebFwdList
     *
     */
    public function queryWebFwdList(string $domain): array
    {
        $domain = strtolower($domain);
        return $this->call("/domain/$domain/web-forwardings/", "GET");
    }

    /**
     * Creates a SSL redirection 
     *		
     * @param string $prod certificate type 
     * @param string $duration period of validity (in years)
     * @param StructCSR $CSRInfo object containing informations about the CSR 
     * @param string $validation validation method of the CSR (either by email or file) : 	"file"
     *																						"email:admin@yourdomain.com"
     *																						"email:postmaster@yourdomain.com,webmaster@yourdomain.com" 
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see domainWebFwdCreate API http://support.netim.com/en/wiki/DomainWebFwdCreate
     * @see StructOptionsFwd http://support.netim.com/en/wiki/StructOptionsFwd
     */
    public function sslCreate(string $prod, int $duration, array $CSRInfo, string $validation): stdClass
    {
        $params = array();
        $params["prod"] = $prod;
        $params["duration"] = $duration;
        $params["CSR"] = $CSRInfo;
        $params["validation"] = $validation;
        return $this->call("/ssl/", "POST", $params);
    }

    /**
     * Renew a SSL certificate for a new subscription period. 
     *		
     * @param string $IDSSL SSL certificate ID
     * @param int $duration period of validity after the renewal (in years). Only the value 1 is valid
     * 
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see sslRenew API http://support.netim.com/en/wiki/SslRenew
     */
    public function sslRenew(string $IDSSL, int $duration): stdClass
    {
        $params = array();
        $params["duration"] = $duration;
        return $this->call("/ssl/$IDSSL/renew/", "PATCH", $params);
    }

    /**
     * Revokes a SSL Certificate. 
     * 
     * @param string $IDSSL SSL certificate ID
     * 
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see sslRevoke API http://support.netim.com/en/wiki/SslRevoke
     */
    public function sslRevoke(string $IDSSL): stdClass
    {
        return $this->call("/ssl/$IDSSL/", "DELETE");
    }

    /**
     * Reissues a SSL Certificate. 
     * 
     * @param string $IDSSL SSL certificate ID
     * @param StructCSR $CSRInfo Object containing informations about the CSR
     * @param string $validation validation method of the CSR (either by email or file) : 	"file"
     *																						"email:admin@yourdomain.com"
     *																						"email:postmaster@yourdomain.com,webmaster@yourdomain.com"
     * 
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see sslReIssue API http://support.netim.com/en/wiki/SslReIssue
     * @see StructCSR http://support.netim.com/en/wiki/StructCSR
     */
    public function sslReIssue(string $IDSSL, array $CSRInfo, string $validation): stdClass
    {
        $params = array();
        $params["CSR"] = $CSRInfo;
        $params["validation"] = $validation;

        return $this->call("/ssl/$IDSSL/reissue/", "PATCH", $params);
    }

    /**
     * Updates the settings of a SSL certificate. Currently, only the autorenew setting can be modified. 
     * 
     * @param string $IDSSL SSL certificate ID
     * @param string $codePref Setting to be modified (auto_renew/to_be_renewed)
     * @param string $value New value of the setting
     * 
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see sslSetPreference API http://support.netim.com/en/wiki/SslSetPreference
     */
    public function sslSetPreference(string $IDSSL, string $codePref, string $value): stdClass
    {
        $params = array();
        $params["codePref"] = $codePref;
        $params["value"] = $value;

        return $this->call("/ssl/$IDSSL/preference/", "PATCH", $params);
    }

    /**
     * Returns all the informations about a SSL certificate
     * 
     * @param string $IDSSL SSL certificate ID
     * 
     * @throws NetimAPIException
     * 
     * @return StructSSLInfo containing the SSL certificate informations 
     * 
     * @see sslInfo API http://support.netim.com/en/wiki/SslInfo
     */
    public function sslInfo(string $IDSSL): stdClass
    {
        return $this->call("/ssl/$IDSSL/", "GET");
    }

    /**
     * Creates a web hosting
     * 
     * @param string $fqdn Fully qualified domain of the main vhost. Warning, the secondary vhosts will always be subdomains of this FQDN
     * @param int $duration ID_TYPE_PROD of the hosting
     * @param array $options 
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingCreate(string $fqdn, string $offer, int $duration, array $cms = array()): stdClass
    {
        $params = array();
        $params["fqdn"] = $fqdn;
        $params["offer"] = $offer;
        $params["duration"] = $duration;
        $params["cms"] = $cms;

        return $this->call("/webhosting/", "POST", $params);
    }

    /**
     * Get the unique ID of the hosting
     * 
     * @param string $fqdn Fully qualified domain of the main vhost.
     * 
     * @throws NetimAPIException
     * 
     * @return string the unique ID of the hosting
     */
    public function webHostingGetID(string $fqdn): string
    {
        return $this->call("/webhosting/get-id/$fqdn", "GET");
    }

    /**
     * Get informations about web hosting (generic infos, MUTU platform infos, ISPConfig ...)
     * 
     * @param string $id Hosting id
     * @param array $additionalData determines which infos should be returned ("NONE", "ALL", "WEB", "VHOSTS", "SSL_CERTIFICATES",
     * "PROTECTED_DIRECTORIES", "DATABASES", "DATABASE_USERS", "FTP_USERS", "CRON_TASKS", "MAIL", "DOMAIN_MAIL")
     * 
     * @throws NetimAPIException
     * 
     * @return StructWebHostingInfo giving informations of the webhosting
     */
    public function webHostingInfo(string $id, array $additionalData): array
    {
        $params = array();
        $params["additionalData"] = $additionalData;

        return (array)$this->call("/webhosting/$id", "GET", $params);
    }

    /**
     * Renew a webhosting
     * 
     * @param string $id Hosting id
     * @param int $duration Duration period (in months)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingRenew(string $id, int $duration): stdClass
    {
        $params = array();
        $params["duration"] = $duration;

        return $this->call("/webhosting/$id/renew/", "PATCH", $params);
    }

    /**
     * Updates a webhosting
     * 
     * @param string $id Hosting id
     * @param string $action Action name ("SetHold", "SetWebHold", "SetDBHold", "SetFTPHold", "SetMailHold", "SetPackage", "SetAutoRenew", "SetRenewReminder", "CalculateDiskUsage")
     * @param array $params array("value"=>true/false) for all except SetPackage : array("offer"=>"SHWEB"/"SHLITE"/"SHMAIL"/"SHPREMIUM"/"SHSTART") and CalculateDiskUsage: array()
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id", "PATCH", $params);
    }

    /**
     * Deletes a webhosting
     * 
     * @param $id Hosting id
     * @param $typeDelete Only "NOW" is allowed
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDelete(string $id, string $typeDelete): stdClass
    {
        $params = array();
        $params['typeDelete'] = $typeDelete;

        return $this->call("/webhosting/$id", "DELETE", $params);
    }

    /**
     * Creates a vhost
     * 
     * @param $id Hosting id
     * @param $fqdn Fqdn of the vhost
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingVhostCreate(string $id, string $fqdn): stdClass
    {
        $params = array();
        $params['fqdn'] = $fqdn;

        return $this->call("/webhosting/$id/vhost/", "POST", $params);
    }

    /**
     * Change settings of a vhost
     * 
     * @param string $id Hosting id
     * @param string $action Possible values :"SetStaticEngine", "SetPHPVersion",  "SetFQDN", "SetWebApplicationFirewall",
     * "ResetContent", "FlushLogs", "AddAlias", "RemoveAlias", "LinkSSLCert", "UnlinkSSLCert", "EnableLetsEncrypt",
     * "DisableLetsEncrypt", "SetRedirectHTTPS", "InstallWordpress", "InstallPrestashop", "SetHold"
     * @param array $fparams Depends of the action
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingVhostUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/vhost/", "PATCH", $params);
    }

    /**
     * Deletes a vhost
     * 
     * @param string $id Hosting id
     * @param string $fqdn of the vhost
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingVhostDelete(string $id, string $fqdn): stdClass
    {
        $params = array();
        $params['fqdn'] = $fqdn;

        return $this->call("/webhosting/$id/vhost/", "DELETE", $params);
    }

    /**
     * Creates a mail domain
     * 
     * @param string $id Hosting id
     * @param string $domain
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDomainMailCreate(string $id, string $domain): stdClass
    {
        $params = array();
        $params['domain'] = $domain;

        return $this->call("/webhosting/$id/domain-mail/", "POST", $params);
    }

    /**
     * Change settings of mail domain based on the specified action
     * 
     * @param string $id Hosting id
     * @param string $action Action name 
     * @param array $fparams Parameters of the action
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDomainMailUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/domain-mail/", "PATCH", $params);
    }

    /**
     * Deletes a mail domain
     * 
     * @param string $id Hosting id
     * @param string $domain
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDomainMailDelete(string $id, string $domain): stdClass
    {
        $params = array();
        $params['domain'] = $domain;

        return $this->call("/webhosting/$id/domain-mail/", "DELETE", $params);
    }

    /**
     * Creates a SSL certificate
     * 
     * @param string $id Hosting id
     * @param string $sslName Name of the certificate
     * @param string $crt Content of the .crt file
     * @param string $key Content of the .key file
     * @param string $ca Content of the .ca file
     * @param string $csr Content of the .csr file (optional)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingSSLCertCreate(string $id, string $sslName, string $crt, string $key, string $ca, string $csr = ""): stdClass
    {
        $params = array();
        $params['sslName'] = $sslName;
        $params['crt'] = $crt;
        $params['key'] = $key;
        $params['ca'] = $ca;
        $params['csr'] = $csr;

        return $this->call("/webhosting/$id/ssl/", "POST", $params);
    }

    /**
     * Delete a SSL certificate
     * 
     * @param string $id Hosting id
     * @param string $sslName Name of the certificate
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingSSLCertDelete(string $id, string $sslName): stdClass
    {
        $params = array();
        $params['sslName'] = $sslName;

        return $this->call("/webhosting/$id/ssl/", "DELETE", $params);
    }

    /**
     * Creates a htpasswd protection on a directory
     * 
     * @param string $id Hosting id
     * @param string $fqdn FQDN of the vhost which you want to protect
     * @param string $pathSecured Path of the directory to protect starting from the selected vhost
     * @param string $authname Text shown by browsers when accessing the directory
     * @param string $username Login of the first user of the protected directory
     * @param string $password Password of the first user of the protected directory
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingProtectedDirCreate(string $id, string $fqdn, string $pathSecured, string $authname, string $username, string $password): stdClass
    {
        $params = array();
        $params['fqdn'] = $fqdn;
        $params['pathSecured'] = $pathSecured;
        $params['authname'] = $authname;
        $params['username'] = $username;
        $params['password'] = $password;

        return $this->call("/webhosting/$id/protected-dir/", "POST", $params);
    }

    /**
     * Change settings of a protected directory
     * 
     * @param string $id Hosting id
     * @param string $action Name of the action to perform
     * @param array $fparams Parameters for the action (depends of the action)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingProtectedDirUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/protected-dir/", "PATCH", $params);
    }

    /**
     * Remove protection of a directory
     * 
     * @param string $id Hosting id
     * @param string $fqdn Vhost's FQDN
     * @param string $pathSecured Path of the protected directory starting from the selected vhost
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingProtectedDirDelete(string $id, string $fqdn, string $pathSecured): stdClass
    {
        $params = array();
        $params['fqdn'] = $fqdn;
        $params['path'] = $pathSecured;

        return $this->call("/webhosting/$id/protected-dir/", "DELETE", $params);
    }

    /**
     * Creates a cron task
     * 
     * @param string $id Hosting id
     * @param string $fqdn Vhost's FDQN
     * @param string $path Path to the script starting from the vhost's directory
     * @param string $returnMethod "LOG", "MAIL" or "NONE"
     * @param string $returnTarget 	When $returnMethod == "MAIL" : an email address
     * 								When $returnMethod == "LOG" : a path to a log file starting from the vhost's directory
     * @param string $mm
     * @param string $hh
     * @param string $jj
     * @param string $mmm
     * @param string $jjj
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingCronTaskCreate(string $id, string $fqdn, string $path, string $returnMethod, string $returnTarget, string $mm, string $hh, string $jj, string $mmm, string $jjj): stdClass
    {
        $params = array();
        $params['fqdn'] = $fqdn;
        $params['path'] = $path;
        $params['returnMethod'] = $returnMethod;
        $params['returnTarget'] = $returnTarget;
        $params['mm'] = $mm;
        $params['hh'] = $hh;
        $params['jj'] = $jj;
        $params['mmm'] = $mmm;
        $params['jjj'] = $jjj;

        return $this->call("/webhosting/$id/cron-task/", "POST", $params);
    }

    /**
     * Change settings of a cron task
     * 
     * @param string $id Hosting id
     * @param string $action Name of the action to perform
     * @param array $fparams Parameters for the action (depends of the action)
     *
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingCronTaskUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/cron-task/", "PATCH", $params);
    }

    /**
     * Delete a cron task
     * 
     * @param string $id Hosting id
     * @param string $idCronTask 
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingCronTaskDelete(string $id, string $idCronTask): stdClass
    {
        $params = array();
        $params['idCronTask'] = $idCronTask;

        return $this->call("/webhosting/$id/cron-task/", "DELETE", $params);
    }

    /**
     * Create a FTP user
     * 
     * @param string $id Hosting id
     * @param string $username
     * @param string $password
     * @param string $rootDir User's root directory's path starting from the hosting root
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingFTPUserCreate(string $id, string $username, string $password, string $rootDir): stdClass
    {
        $params = array();
        $params['username'] = $username;
        $params['password'] = $password;
        $params['rootDir'] = $rootDir;

        return $this->call("/webhosting/$id/ftp-user/", "POST", $params);
    }

    /**
     * Update a FTP user
     * 
     * @param string $id Hosting id
     * @param string $action Name of the action to perform
     * @param array $fparams Parameters for the action (depends of the action)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingFTPUserUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/ftp-user/", "PATCH", $params);
    }

    /**
     * Delete a FTP user
     * 
     * @param string $id Hosting id
     * @param string $username
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingFTPUserDelete(string $id, string $username): stdClass
    {
        $params = array();
        $params['username'] = $username;

        return $this->call("/webhosting/$id/ftp-user/", "DELETE", $params);
    }

    /**
     * Create a database
     * 
     * @param string $id Hosting id
     * @param string $dbName Name of the database (Must be preceded by the hosting id separated with a "_")
     * @param string $version Wanted SQL version (Optional, the newest version will be chosen if left empty)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDBCreate(string $id, string $dbName, string $version = ""): stdClass
    {
        $params = array();
        $params['dbName'] = $dbName;
        $params['version'] = $version;

        return $this->call("/webhosting/$id/database/", "POST", $params);
    }

    /**
     * Update database settings
     * 
     * @param string $id Hosting id
     * @param string $action Name of the action to perform
     * @param array $fparams Parameters for the action (depends of the action)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDBUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/database/", "PATCH", $params);
    }

    /**
     * Delete a database
     * 
     * @param string $id Hosting id
     * @param string $dbName Name of the database
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDBDelete(string $id, string $dbName): stdClass
    {
        $params = array();
        $params['dbName'] = $dbName;

        return $this->call("/webhosting/$id/database/", "DELETE", $params);
    }

    /**
     * Create a database user
     * 
     * @param string $id Hosting id
     * @param string $username
     * @param string $password
     * @param string $internalAccess "RW", "RO" or "NO"
     * @param string $externalAccess "RW", "RO" or "NO"
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDBUserCreate(string $id, string $username, string $password, string $internalAccess, string $externalAccess): stdClass
    {
        $params = array();
        $params['username'] = $username;
        $params['password'] = $password;
        $params['internalAccess'] = $internalAccess;
        $params['externalAccess'] = $externalAccess;

        return $this->call("/webhosting/$id/database-user/", "POST", $params);
    }

    /**
     * Update database user's settings
     * 
     * @param string $id Hosting id
     * @param string $action Name of the action to perform
     * @param array $fparams Parameters for the action (depends of the action)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDBUserUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/database-user/", "PATCH", $params);
    }

    /**
     * Delete a database user
     * 
     * @param string $id Hosting id
     * @param string $username
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingDBUserDelete(string $id, string $username): stdClass
    {
        $params = array();
        $params['username'] = $username;

        return $this->call("/webhosting/$id/database-user/", "DELETE", $params);
    }

    /**
     * Create a mailbox
     * 
     * @param string $id Hosting id
     * @param string $email
     * @param string $password
     * @param int $quota Disk space allocated to this box in MB
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingMailCreate(string $id, string $email, string $password, int $quota): stdClass
    {
        $params = array();
        $params['email'] = $email;
        $params['password'] = $password;
        $params['quota'] = $quota;

        return $this->call("/webhosting/$id/mailbox/", "POST", $params);
    }

    /**
     * Update mailbox' settings
     * 
     * @param string $id Hosting id
     * @param string $action Name of the action to perform
     * @param array $fparams Parameters for the action (depends of the action)
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingMailUpdate(string $id, string $action, array $fparams): stdClass
    {
        $params = array();
        $params['action'] = $action;
        $params['params'] = $fparams;

        return $this->call("/webhosting/$id/mailbox/", "PATCH", $params);
    }

    /**
     * Delete a mailbox
     * 
     * @param string $id Hosting id
     * @param string $email
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingMailDelete(string $id, string $email): stdClass
    {
        $params = array();
        $params['email'] = $email;

        return $this->call("/webhosting/$id/mailbox/", "DELETE", $params);
    }

    /**
     * Create a mail redirection
     * 
     * @param string $id Hosting id
     * @param string $source
     * @param string[] $destination
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingMailFwdCreate(string $id, string $source, array $destination): stdClass
    {
        $params = array();
        $params['source'] = $source;
        $params['destination'] = $destination;

        return $this->call("/webhosting/$id/mail-forwarding/", "POST", $params);
    }

    /**
     * Delete a mail redirection
     * 
     * @param string $id Hosting id
     * @param string $source
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingMailFwdDelete(string $id, string $source): stdClass
    {
        $params = array();
        $params['source'] = $source;

        return $this->call("/webhosting/$id/mail-forwarding/", "DELETE", $params);
    }

    /**
     * Resets all DNS settings from a template 
     * 
     * @param string $domain
     * @param int $profil
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingZoneInit(string $fqdn, int $profil): stdClass
    {
        $params = array();
        $fqdn = strtolower($fqdn);
        $params['profil'] = $profil;

        return $this->call("/webhosting/$fqdn/zone/init/", "PATCH", $params);
    }

    /**
     * Resets the SOA record of a domain name for a webhosting
     * 
     * @param string $domain name of the domain
     * @param int 	 $ttl time to live
     * @param string $ttlUnit TTL unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $refresh Refresh delay
     * @param string $refreshUnit Refresh unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $retry Retry delay
     * @param string $retryUnit Retry unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $expire Expire delay
     * @param string $expireUnit Expire unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     * @param int	 $minimum Minimum delay
     * @param string $minimumUnit Minimum unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingZoneInitSoa(string $fqdn, int $ttl, string $ttlUnit, int $refresh, string $refreshUnit, int $retry, string $retryUnit, int $expire, string $expireUnit, int $minimum, string $minimumUnit): stdClass
    {
        $params = array();
        $fqdn = strtolower($fqdn);
        $params["ttl"] = $ttl;
        $params["ttlUnit"] = $ttlUnit;
        $params["refresh"] = $refresh;
        $params["refreshUnit"] = $refreshUnit;
        $params["retry"] = $retry;
        $params["retryUnit"] = $retryUnit;
        $params["expire"] = $expire;
        $params["expireUnit"] = $expireUnit;
        $params["minimum"] = $minimum;
        $params["minimumUnit"] = $minimumUnit;

        return $this->call("/webhosting/$fqdn/zone/init-soa/", "PATCH", $params);
    }

    /**
     * Returns all DNS records of a webhosting
     * 
     * @param string $domain Domain name
     * 
     * @throws NetimAPIException
     * 
     * @return StructQueryZoneList[]
     */
    public function webHostingZoneList(string $fqdn): array
    {
        return $this->call("/webhosting/$fqdn/zone/", "GET");
    }

    /**
     * Creates a DNS record into the webhosting domain zonefile
     *
     * @param string $domain name of the domain
     * @param string $subdomain subdomain
     * @param string $type type of DNS record. Accepted values are: 'A', 'AAAA', 'MX, 'CNAME', 'TXT', 'NS and 'SRV'
     * @param string $value value of the new DNS record
     * @param array $options  StructOptionsZone : settings of the new DNS record 
     *
     * @throws NetimAPIException
     *
     * @return StructOperationResponse giving information on the status of the operation
     *
     * @see StructOptionsZone http://support.netim.com/en/wiki/StructOptionsZone
     */
    public function webHostingZoneCreate(string $domain, string $subdomain, string $type, string $value, array $options): stdClass
    {
        $params = array();
        $fqdn = strtolower("$subdomain.$domain");
        $params['type'] = $type;
        $params['value'] = $value;
        $params['options'] = $options;

        return $this->call("/webhosting/$fqdn/zone/", "POST", $params);
    }

    /**
     * Deletes a DNS record into the webhosting domain zonefile
     * 
     * @param string $domain name of the domain
     * @param string $subdomain subdomain
     * @param string $type type of DNS record. Accepted values are: 'A', 'AAAA', 'MX, 'CNAME', 'TXT', 'NS and 'SRV'
     * @param string $value value of the new DNS record
     * 
     * @throws NetimAPIException
     * 
     * @return StructOperationResponse giving information on the status of the operation
     */
    public function webHostingZoneDelete(string $domain, string $subdomain, string $type, string $value): stdClass
    {
        $params = array();

        $fqdn = strtolower("$subdomain.$domain");
        $params['type'] = $type;
        $params['value'] = $value;

        return $this->call("/webhosting/$fqdn/zone/", "DELETE", $params);
    }
}
