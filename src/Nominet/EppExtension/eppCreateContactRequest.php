<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use Metaregistrar\EPP\eppCreateContactRequest as MetaRegistrarEppCreateContactRequest;

/**
 * Extended eppCreateContractRequest so we can set the Nominet contact create extension.
 */
class eppCreateContactRequest extends MetaRegistrarEppCreateContactRequest
{
    /**
     * Set nominet contact type e.g., IND for individual.
     *
     * @link https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/field-definitions-and-registrant-types/#
     */
    public function setNominetContactType(
        ?string $type,
        ?string $tradeName = null,
        ?string $companyNumber = null
    ): void {
        // reset extension
        /** @phpstan-ignore-next-line  */
        if ($this->extension) {
            $this->getCommand()->removeChild($this->getExtension());
            /** @phpstan-ignore-next-line  */
            $this->extension = null;
        }

        if (empty($type)) {
            return;
        }

        // set epp stuff

        $this->getEpp()->setAttribute('xmlns', 'urn:ietf:params:xml:ns:epp-1.0');
        $this->getEpp()->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->getEpp()->setAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd');

        // set contact create stuff

        /** @var \DOMElement $create */
        $create = $this->getCommand()->getElementsByTagName('create')->item(0);
        /** @var \DOMElement $contactCreate */
        $contactCreate = $create->getElementsByTagName('contact:create')->item(0);

        $contactCreate->setAttribute('xmlns:contact', 'urn:ietf:params:xml:ns:contact-1.0');
        $contactCreate->setAttribute('xsi:schemaLocation', 'urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd');

        // create + set extension

        $contactExtension = $this->createElement('contact-ext:create');
        $contactExtension->setAttribute('xmlns:contact-ext', 'http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0');
        $contactExtension->setAttribute('xsi:schemaLocation', 'http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0 contact-nom-ext-1.0.xsd');

        $contactExtension->appendChild($this->createElement('contact-ext:type', $type));

        if ($tradeName) {
            $contactExtension->appendChild($this->createElement('contact-ext:trad-name', $tradeName));
        }

        if ($companyNumber) {
            $contactExtension->appendChild($this->createElement('contact-ext:co-no', $companyNumber));
        }

        $this->getExtension()->appendChild($contactExtension);

        // move clTRID to after the extension
        $clTRID = $this->getCommand()->getElementsByTagName('clTRID')->item(0);
        $this->getCommand()->removeChild($clTRID);
        $this->getCommand()->appendChild($clTRID);
    }
}
