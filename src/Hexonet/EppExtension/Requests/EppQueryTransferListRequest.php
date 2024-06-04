<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Requests;

use Metaregistrar\EPP\eppRequest;

/**
 * @link https://wiki.hexonet.net/wiki/EPP:QueryTransferList
 */
class EppQueryTransferListRequest extends eppRequest
{
    /**
     * @param string|null $domainPattern Domain name search pattern e.g., foo.com or *.com
     * @param int $limit Number of results to return (pagination)
     * @param int $offset Offset of results to return (pagination)
     *
     * @throws \DOMException
     */
    public function __construct(?string $domainPattern = null, int $limit = 10, int $offset = 0)
    {
        parent::__construct();

        /**
            <?xml version="1.0" encoding="UTF-8" standalone="no"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
                <extension>
                    <keyvalue:extension xmlns:keyvalue="http://schema.ispapi.net/epp/xml/keyvalue-1.0" xsi:schemaLocation="http://schema.ispapi.net/epp/xml/keyvalue-1.0 keyvalue-1.0.xsd">
                        <keyvalue:kv key='COMMAND' value='QueryTransferList' />
                    </keyvalue:extension>
                </extension>
            </epp>
        */

        // Set the extension tag for internal transfer
        $extensionElement = $this->createElement('extension');
        $keyValueElement = $this->createElement('keyvalue:extension');

        $commandElement = $this->createElement('keyvalue:kv');
        $commandElement->setAttribute('key', 'COMMAND');
        $commandElement->setAttribute('value', 'QueryTransferList');
        $keyValueElement->appendChild($commandElement);

        if (!empty($domainPattern)) {
            $domainElement = $this->createElement('keyvalue:kv');
            $domainElement->setAttribute('key', 'DOMAIN');
            $domainElement->setAttribute('value', $domainPattern);
            $keyValueElement->appendChild($domainElement);
        }

        $limitElement = $this->createElement('keyvalue:kv');
        $limitElement->setAttribute('key', 'LIMIT');
        $limitElement->setAttribute('value', (string)$limit);
        $keyValueElement->appendChild($limitElement);

        if ($offset > 0) {
            $offsetElement = $this->createElement('keyvalue:kv');
            $offsetElement->setAttribute('key', 'FIRST');
            $offsetElement->setAttribute('value', (string)$offset);
            $keyValueElement->appendChild($offsetElement);
        }

        $extensionElement->appendChild($keyValueElement);
        $this->getEpp()->appendChild($extensionElement);
    }
}
