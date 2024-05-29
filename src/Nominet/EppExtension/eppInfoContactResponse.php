<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nominet\EppExtension;

use DOMElement;
use Metaregistrar\EPP\eppInfoContactResponse as MetaRegistrarEppInfoContactResponse;

class eppInfoContactResponse extends MetaRegistrarEppInfoContactResponse
{
    public function getNominetContactData(): ?array
    {
        return [
            'type' => $this->getNominetContactValue('type'),
            'trad-name' => $this->getNominetContactValue('trad-name'),
            'co-no' => $this->getNominetContactValue('co-no'),
            'opt-out' => $this->getNominetContactValue('opt-out'),
        ];
    }

    public function getNominetContactValue($name): ?string
    {
        /**
         * @var \DOMElement $element
         */
        $element = $this->getExtensionElement()->getElementsByTagName($name)->item(0);
        return $element->textContent ?? null;
    }

    public function getExtensionElement(): ?DOMElement
    {
        return $this->getElementsByTagName('epp')->item(0)
            ->getElementsByTagName('response')->item(0)
            ->getElementsByTagName('extension')->item(0);
    }
}
