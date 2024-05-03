<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Nameservers of a domain.
 *
 * @property-read Nameserver|null $ns1 Nameserver 1
 * @property-read Nameserver|null $ns2 Nameserver 2
 * @property-read Nameserver|null $ns3 Nameserver 3
 * @property-read Nameserver|null $ns4 Nameserver 4
 * @property-read Nameserver|null $ns5 Nameserver 5
 */
class NameserversResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'ns1' => [Nameserver::class],
            'ns2' => [Nameserver::class],
            'ns3' => [Nameserver::class],
            'ns4' => [Nameserver::class],
            'ns5' => [Nameserver::class],
        ]);
    }

    /**
     * @param Nameserver|array $ns1
     *
     * @return $this
     */
    public function setNs1($ns1): NameserversResult
    {
        $this->setValue('ns1', $ns1);
        return $this;
    }

    /**
     * @param Nameserver|array $ns2
     *
     * @return $this
     */
    public function setNs2($ns2): NameserversResult
    {
        $this->setValue('ns2', $ns2);
        return $this;
    }

    /**
     * @param Nameserver|array $ns3
     *
     * @return $this
     */
    public function setNs3($ns3): NameserversResult
    {
        $this->setValue('ns3', $ns3);
        return $this;
    }

    /**
     * @param Nameserver|array $ns4
     *
     * @return $this
     */
    public function setNs4($ns4): NameserversResult
    {
        $this->setValue('ns4', $ns4);
        return $this;
    }

    /**
     * @param Nameserver|array $ns5
     *
     * @return $this
     */
    public function setNs5($ns5): NameserversResult
    {
        $this->setValue('ns5', $ns5);
        return $this;
    }
}
