<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses;

use Illuminate\Support\Str;
use Metaregistrar\EPP\eppResponse;

/**
 * @link https://wiki.hexonet.net/wiki/EPP:CheckDomainTransfer
 */
class EppCheckTransferResponse extends eppResponse
{
    public function isAvailable(): bool
    {
        return $this->getResultCode() == 1000
            && Str::startsWith($this->getResultReason(), '218 ');
    }

    /**
     * @return string[] Assoc array of extension keyvalue pairs
     */
    public function getData(): array
    {
        $extensionNode = $this->xPath()->query('/epp:epp/epp:response/epp:extension');
        $extension = $extensionNode->item(0);

        /** @var \DomElement $child */
        foreach ($extension->childNodes as $child) {
            if ($child->nodeName === 'keyvalue:extension') {
                $keyValuesNode = $child;
                break;
            }
        }

        if (!isset($keyValuesNode)) {
            return [];
        }

        $data = [];

        /** @var \DomElement $child */
        foreach ($keyValuesNode->childNodes as $child) {
            if ($child->nodeName === 'keyvalue:kv') {
                $data[$child->getAttribute('key')] = $child->getAttribute('value');
            }
        }

        return $data;
    }
}
