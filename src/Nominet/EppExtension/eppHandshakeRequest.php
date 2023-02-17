<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use Metaregistrar\EPP\eppRequest;

class eppHandshakeRequest extends eppRequest
{
    public function __construct(string $caseId, string $registrarId)
    {
        parent::__construct();
        $this->getEpp()->setAttribute('xmlns', 'urn:ietf:params:xml:ns:epp-1.0');
        $this->getEpp()->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->getEpp()->setAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd');

        $update = $this->createElement('update');
        $handshake = $this->createElement('h:accept');
        $handshake->setAttribute('xmlns:h', 'http://www.nominet.org.uk/epp/xml/std-handshake-1.0');
        $handshake->setAttribute('xsi:schemaLocation', 'http://www.nominet.org.uk/epp/xml/std-handshake-1.0 std-handshake-1.0.xsd');

        $handshake->appendChild($this->createElement('h:caseId', $caseId));
        $handshake->appendChild($this->createElement('h:registrant', $registrarId));

        $update->appendChild($handshake);
        $this->getCommand()->appendChild($update);
    }
}
