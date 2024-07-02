<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppUpdateRequest;

/**
 * Class metaregEppTransferExtendedRequest
 */
class eppReleaseRequest extends eppUpdateRequest
{
    /**
     * metaregEppTransferExtendedRequest constructor.
     *
     * @param eppDomain $object
     * @param string $newTag
     *
     * @throws \DOMException
     * @throws \Metaregistrar\EPP\eppException
     */
    public function __construct(eppDomain $object, string $newTag)
    {
        parent::__construct($object);
        $this->getEpp()->setAttribute('xmlns', 'urn:ietf:params:xml:ns:epp-1.0');
        $this->getEpp()->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->getEpp()->setAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd');
        // $this->addExtension('xmlns:command-ext-domain', 'http://www.metaregistrar.com/epp/command-ext-domain-1.0');
        // $command = $this->createElement('command');
        $update = $this->createElement('update');
        $release = $this->createElement('r:release');
        $release->setAttribute('xmlns:r', 'http://www.nominet.org.uk/epp/xml/std-release-1.0');
        $release->setAttribute('xsi:schemaLocation', 'http://www.nominet.org.uk/epp/xml/std-release-1.0 std-release-1.0.xsd');
        if ($object->getDomainname()) {
            $domain = $this->createElement('r:domainName', $object->getDomainname());
        } else {
            throw new eppException("Missing domain!");
        }
        $release->appendChild($domain);
        $release->appendChild($this->createElement('r:registrarTag', $newTag));

        $update->appendChild($release);
        $this->getCommand()->appendChild($update);
    }
}
