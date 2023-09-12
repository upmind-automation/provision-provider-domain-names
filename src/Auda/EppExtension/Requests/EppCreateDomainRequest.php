<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Auda\EppExtension\Requests;

use Metaregistrar\EPP\eppDomain;

class EppCreateDomainRequest extends \Metaregistrar\EPP\eppCreateDomainRequest
{
    function __construct(eppDomain $domain)
    {
        parent::__construct($domain, false);
    }

    public function setRegistrantExt(string $name, string $eligibilityType = 'Other' , string $policyReason = '2') {
        $create = $this->createElement('auext:create');
        $this->setNamespace('xmlns:auext', 'urn:X-au:params:xml:ns:auext-1.3', $create);

        $auProperties = $this->createElement('auext:auProperties');
        $auProperties->appendChild($this->createElement('auext:registrantName', $name));

        $auProperties->appendChild($this->createElement('auext:eligibilityType', $eligibilityType));
        $auProperties->appendChild($this->createElement('auext:policyReason', $policyReason));

        $create->appendChild($auProperties);

        $this->getExtension()->appendChild($create);
    }
}
