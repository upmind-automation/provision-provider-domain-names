<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TPPWholesale\Helper;

use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class TPPWholesaleResponse implements \JsonSerializable
{
    protected string $response;

    public function __construct(string $response)
    {
        $this->response = $response;
    }

    private function errorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case 100:
                return "Missing parameters";
            case 102:
                return "Authentication Failure";
            case 105:
                return "Request coming from incorrect IP address. IP Lock error. See section 1.1";
            case 201:
                return "Invalid or not supplied 'Type' parameter";
            case 202:
                return "Your Account has not been enabled for this 'Type'";
            case 203:
                return "Invalid or not supplied 'Action/Object parameter/s'";
            case 301:
                return "Invalid Order ID";
            case 302:
                return "Domain not supplied";
            case 303:
                return "Domain Pricing table not set up for your account";
            case 304:
                return "Domain not available for Registration. When this code is returned a reason is also returned.";
            case 305:
                return "Domain is not renewable. When this code is returned a reason is also returned.";
            case 306:
                return "Domain is not transferable. When this code is returned a reason is also returned.";
            case 307:
                return "Incorrect Domain Password";
            case 308:
                return "Domain UserID or Password not supplied";
            case 309:
                return "Invalid Domain Extension";
            case 310:
                return "Domain does not exist, has been deleted or transferred away";
            case 311:
                return "Domain does not exist in your reseller profile";
            case 312:
                return "Supplied UserID and Password do not match the domain";
            case 313:
                return "The account does not exist in your reseller profile";
            case 314:
                return "Only .au domains can have their registrant name details changed";
            case 401:
                return "Connection to Registry failed - retry";
            case 500:
                return "Pre-Paid balance is not enough to cover order cost";
            case 501:
                return "Invalid credit card type. See Appendix G";
            case 502:
                return "Invalid credit card number";
            case 503:
                return "Invalid credit card expiry date";
            case 504:
                return "Credit Card amount plus the current pre-paid balance is not sufficient to cover the cost of the order";
            case 505:
                return "Error with credit card transaction at bank"; // This error code will always be followed by a comma then a description of the error
            case 600:
                return "Error with one or more fields when creating a Domain Contact"; // This error code will always be followed by a comma then a space separated list of fields that have failed
            case 601:
                return "Error with one or more fields when creating, renewing or transferring a Domain"; // This error code will always be followed by a comma then a space separated list of fields that have failed
            case 602:
                return "Error with one or more fields associated with a Host"; // This error code will always be followed by a comma then a space separated list of fields that have failed
            case 603:
                return "Error with one or more fields associated with eligibility fields"; // This error code will always be followed by a comma then a space separated list of fields that have failed
            case 604:
                return "Error with one or more fields associated with a Nameserver"; // This error code will always be followed by a comma then a space separated list of fields that have failed
            case 610:
                return "Error connecting to registry";
            case 611:
                return "Domain cannot be Renewed or Transferred";
            case 612:
                return "Locking is not available for this domain";
            case 613:
                return "Domain Status prevents changing of domain lock";
            case 615:
                return "Nameserver host delegation failed"; // This seems to return some random json data as the error message
            default:
                return "Unknown error";
        };
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     *
     * @return never
     * @return no-return
     */
    private function throwError(string $message, int $errorCode): void
    {
        throw ProvisionFunctionError::create(sprintf('Provider API Error: %d: %s ', $errorCode, $message))
            ->withData([
                'response' => $this->response,
            ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     *
     * @return never
     * @return no-return
     */
    private function throwResponseError(?string $response = null): void
    {
        $errorDescription = 'Unknown error';

        list(, $errorData) = explode("ERR: ", $response ?? $this->response, 2);
        if (str_contains($errorData, ',')) {
            list($errorCode, $errorDescription) = explode(",", $errorData, 2);
        }

        if (!isset($errorCode) || in_array($errorCode, [600, 601, 602, 603, 604, 615])) {
            $errorCode = $errorData;
            $errorDescription = $this->errorMessage((int)$errorCode);
        }

        $this->throwError($errorDescription, (int)$errorCode);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseAuthResponse(): string
    {
        $sessionId = "";
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            list(, $sessionId) = explode("OK: ", $this->response, 2);
        }

        return $sessionId;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseInfoResponse(): array
    {
        $parsedArray = [];

        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            $lines = explode("\n", trim($this->response));
            if (trim(array_shift($lines)) === "OK:") {
                $parsedArray['Status'] = 'ok';

                foreach ($lines as $line) {
                    list($field, $value) = explode("=", $line, 2);

                    if ($field === "Nameserver") {
                        $parsedArray['Nameserver'][] = $value;
                    } else {
                        if (preg_match('/^(Owner|Administration|Technical|Billing)-(.+)$/', $field, $matches)) {
                            $type = $matches[1];
                            $fieldName = $matches[2];

                            $parsedArray[$type][$fieldName] = $value;
                        } else {
                            $parsedArray[$field] = $value;
                        }
                    }
                }
            }
        }

        return $parsedArray;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseDACResponse(): array
    {
        $result = [];
        $lines = explode("\n", trim($this->response));

        foreach ($lines as $line) {
            $r = [];
            list($r["Domain"], $data) = explode(": ", $line, 2);
            if (str_starts_with($data, "ERR:")) {
                $r['Status'] = 'ERR';

                list(, $errorDetails) = explode("ERR: ", $data, 2);
                if (str_contains($errorDetails, ',')) {
                    list($errorCode, $errorDescription) = explode(",", $errorDetails, 2);
                } else {
                    $errorCode = $errorDetails;
                    $errorDescription = $this->errorMessage((int)$errorCode);
                }

                $r['ErrorCode'] = $errorCode;
                $r['ErrorDescription'] = $errorDescription;
            } else {
                list(, $successDetails) = explode("OK: ", $data, 2);
                parse_str(str_replace('&', '&', $successDetails), $values);
                $r['Status'] = 'OK';
                $r['Minimum'] = (int)$values['Minimum'];
                $r['Maximum'] = (int)$values['Maximum'];
            }

            $result[] = $r;
        }

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseRenewalResponse(): array
    {
        $result = [];
        list(, $data) = explode(": ", $this->response, 2);
        if (str_starts_with($data, "ERR:")) {
            $this->throwResponseError();
        } else {
            list(, $successData) = explode("OK: ", $data, 2);
            parse_str(str_replace('&', '&', $successData), $values);
            $result['Status'] = 'ok';
            $result['Minimum'] = (int)$values['Minimum'];
            $result['Maximum'] = (int)$values['Maximum'];
        }

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseCreateContactResponse(): string
    {
        $result = "";
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            list(, $result) = explode(": ", $this->response, 2);
        }

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseCreateDomainResponse(): string
    {
        $result = "";
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            list(, $result) = explode(": ", $this->response, 2);
        }

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseRenewalOrderResponse(): void
    {
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseLockStatusResponse(): ?string
    {
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            $lines = explode("\n", trim($this->response));
            if (trim(array_shift($lines)) === "OK:") {
                foreach ($lines as $line) {
                    list($field, $value) = explode("=", $line, 2);
                    if ($field == "LockStatus") {
                        if ($value == 0) {
                            throw ProvisionFunctionError::create(sprintf('Provider API Error:  %s ', 'Domain cannot be locked/unlocked'))
                                ->withData([
                                    'response' => $this->response,
                                ]);
                        } elseif ($value == 1) {
                            return "Unlock";
                        } else {
                            return "Lock";
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseLockResponse(): void
    {
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseEppResponse(): ?string
    {
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            $lines = explode("\n", trim($this->response));
            if (trim(array_shift($lines)) === "OK:") {
                foreach ($lines as $line) {
                    list($field, $value) = explode("=", $line, 2);
                    if ($field == "DomainPassword") {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseUpdateHostResponse(): string
    {
        $result = "";
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            list(, $result) = explode(": ", $this->response, 2);
        }

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseUpdateContactResponse(): void
    {
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseTransferResponse(): string
    {
        if (str_starts_with($this->response, "ERR:")) {
            if (str_contains($this->response, '601') && str_contains($this->response, 'existing order')) {
                $this->throwError('Theres an existing order for this domain', 601);
            }

            $this->throwResponseError();
        }

        list(, $result) = explode(": ", $this->response, 2);

        return $result;
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function parseDomainOrderResponse(): array
    {
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        }

        $responses = explode("\n", $this->response);

        // return the most recent available order data
        [$key, $message] = explode(": ", $responses[0], 2);
        if (str_starts_with($message, "ERR:")) {
            $this->throwResponseError($message);
        }

        if (str_contains($message, 'OK:')) {
            [, $message] = explode(': ', $message, 2);
        }

        if (is_numeric($key)) {
            // Search was for an order id
            $orderId = $key;
            [$status, $description] = explode(',', $message, 2);

            return [
                'orderId' => $orderId,
                'type' => 'order',
                'status' => $status,
                'description' => $description,
                'response' => $this,
            ];
        }

        // Search was for a domain name
        $domain = $key;
        [$orderType, $status, $description] = explode(',', $message, 3);

        switch ($orderType) {
            case 'transferral2':
                $type = 'Transfer';
                break;
            case 'registration2':
                $type = 'Registration';
                break;
            default:
                $type = 'Order';
                break;
        }

        return [
            'domain' => $domain,
            'type' => $type,
            'status' => $status,
            'description' => $description,
            'response' => $this,
        ];
    }

    public function jsonSerialize(): string
    {
        return  $this->response;
    }
}
