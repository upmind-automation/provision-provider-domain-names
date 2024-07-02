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
    public function getCode(): int
    {
        return intval(substr($this->getResultReason(), 0, 3)) ?: intval(Str::before($this->getResultReason(), ' '));
    }

    public function isAvailable(): bool
    {
        return $this->getResultCode() == 1000
            && Str::startsWith($this->getResultReason(), '218 ');
    }

    public function getUnavailableReason(): string
    {
        // attempt to make error less verbose
        if (preg_match("/^(?:219 [\w ]+); ([\w ]+)$/", $this->getResultReason(), $matches)) {
            return ucfirst(strtolower($matches[1]));
        }

        return $this->getResultReason();
    }

    /**
     * @return string[] Assoc array of extension keyvalue pairs
     */
    public function getData(): array
    {
        $extensionNode = $this->xPath()->query('/epp:epp/epp:response/epp:extension');
        $extension = $extensionNode->item(0);

        foreach ($extension->childNodes as $child) {
            /** @var \DomElement $child */
            if ($child->nodeName === 'keyvalue:extension') {
                $keyValuesNode = $child;
                break;
            }
        }

        if (!isset($keyValuesNode)) {
            return [];
        }

        $data = [];

        foreach ($keyValuesNode->childNodes as $child) {
            /** @var \DomElement $child */
            if ($child->nodeName === 'keyvalue:kv') {
                $data[$child->getAttribute('key')] = $child->getAttribute('value');
            }
        }

        return $data;
    }
}
