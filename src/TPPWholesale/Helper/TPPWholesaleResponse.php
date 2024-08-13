<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TPPWholesale\Helper;

use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class TPPWholesaleResponse
{
    protected string $response;

    public function __construct(string $response)
    {
        $this->response = $response;
    }

    private function errorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            100 => "Missing parameters",
            102 => "Authentication Failure",
            105 => "Request coming from incorrect IP address. IP Lock error. See section 1.1",
            201 => "Invalid or not supplied 'Type' parameter",
            202 => "Your Account has not been enabled for this 'Type'",
            203 => "Invalid or not supplied 'Action/Object parameter/s'",
            301 => "Invalid Order ID",
            302 => "Domain not supplied",
            303 => "Domain Pricing table not set up for your account",
            304 => "Domain not available for Registration. When this code is returned a reason is also returned.",
            305 => "Domain is not renewable. When this code is returned a reason is also returned.",
            306 => "Domain is not transferable. When this code is returned a reason is also returned.",
            307 => "Incorrect Domain Password",
            308 => "Domain UserID or Password not supplied",
            309 => "Invalid Domain Extension",
            310 => "Domain does not exist, has been deleted or transferred away",
            311 => "Domain does not exist in your reseller profile",
            312 => "Supplied UserID and Password do not match the domain",
            313 => "The account does not exist in your reseller profile",
            314 => "Only .au domains can have their registrant name details changed",
            401 => "Connection to Registry failed - retry",
            500 => "Pre-Paid balance is not enough to cover order cost",
            501 => "Invalid credit card type. See Appendix G",
            502 => "Invalid credit card number",
            503 => "Invalid credit card expiry date",
            504 => "Credit Card amount plus the current pre-paid balance is not sufficient to cover the cost of the order",
            505 => "Error with credit card transaction at bank", // This error code will always be followed by a comma then a description of the error
            600 => "Error with one or more fields when creating a Domain Contact", // This error code will always be followed by a comma then a space separated list of fields that have failed.
            601 => "Error with one or more fields when creating, renewing or transferring a Domain", // This error code will always be followed by a comma then a space separated list of fields that have failed.
            602 => "Error with one or more fields associated with a Host", // This error code will always be followed by a comma then a space separated list of fields that have failed.
            603 => "Error with one or more fields associated with eligibility fields", // This error code will always be followed by a comma then a space separated list of fields that have failed.
            604 => "Error with one or more fields associated with a Nameserver", // This error code will always be followed by a comma then a space separated list of fields that have failed.
            610 => "Error connecting to registry",
            611 => "Domain cannot be Renewed or Transferred",
            612 => "Locking is not available for this domain",
            613 => "Domain Status prevents changing of domain lock",
            615 => "Nameserver host delegation failed", // This seems to return some random json data as the error message
            default => "Unknown error",
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
        $result = "";
        if (str_starts_with($this->response, "ERR:")) {
            $this->throwResponseError();
        } else {
            list(, $result) = explode(": ", $this->response, 2);
        }

        return $result;
    }
}
