<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\EppExtension\Responses;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Metaregistrar\EPP\eppResponse;

/**
 * @link https://wiki.hexonet.net/wiki/EPP:QueryTransferList
 */
class EppQueryTransferListResponse extends eppResponse
{
    /**
     * @var string[]
     */
    protected $extensionData;

    /**
     * Determine whether transfer-IN already exists.
     */
    public function transferExists(): bool
    {
        return Arr::get($this->getData(), 'COUNT') > 0;
    }

    /**
     * Determine when the transfer was initiated.
     */
    public function transferDate(): ?CarbonImmutable
    {
        if (!$this->transferExists()) {
            return null;
        }

        $created = Arr::get($this->getData(), 'CREATEDDATE');
        return new CarbonImmutable($created);
    }

    /**
     * @return string[] Assoc array of extension keyvalue pairs
     */
    public function getData(): array
    {
        if (isset($this->extensionData)) {
            return $this->extensionData;
        }

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

        return $this->extensionData = $data;
    }
}
