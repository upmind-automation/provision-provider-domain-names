<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests;

use Metaregistrar\EPP\eppRequest;

/**
 * @link https://wiki.hexonet.net/wiki/EPP:CheckDomainTransfer
 */
class EppCheckTransferRequest extends eppRequest
{
    /**
     * @throws \DOMException
     */
    public function __construct(string $domainName, ?string $eppCode = null)
    {
        parent::__construct();

        /**
            <?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
                <extension>
                    <keyvalue:extension xmlns:keyvalue="http://schema.ispapi.net/epp/xml/keyvalue-1.0" xsi:schemaLocation="http://schema.ispapi.net/epp/xml/keyvalue-1.0 keyvalue-1.0.xsd">
                    <keyvalue:kv key='COMMAND' value='CheckDomainTransfer' />
                    <keyvalue:kv key='DOMAIN' value='test12.com' />
                    </keyvalue:extension>
                </extension>
            </epp>
        */

        // Set the extension tag for internal transfer
        $extension = $this->createElement('extension');
        $keyValueExtension = $this->createElement('keyvalue:extension');

        $command = $this->createElement('keyvalue:kv');
        $command->setAttribute('key', 'COMMAND');
        $command->setAttribute('value', 'CheckDomainTransfer');
        $keyValueExtension->appendChild($command);

        $domain = $this->createElement('keyvalue:kv');
        $domain->setAttribute('key', 'DOMAIN');
        $domain->setAttribute('value', $domainName);
        $keyValueExtension->appendChild($domain);

        if (!empty($eppCode)) {
            $auth = $this->createElement('keyvalue:kv');
            $auth->setAttribute('key', 'AUTH');
            $auth->setAttribute('value', $eppCode);
            $keyValueExtension->appendChild($auth);
        }

        $extension->appendChild($keyValueExtension);

        $this->getEpp()->appendChild($extension);
    }
}
