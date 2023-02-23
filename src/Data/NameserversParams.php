<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for nameservers, up to five positions.
 *
 * @property-read Nameserver|null $ns1 Nameserver 1
 * @property-read Nameserver|null $ns2 Nameserver 2
 * @property-read Nameserver|null $ns3 Nameserver 3
 * @property-read Nameserver|null $ns4 Nameserver 4
 * @property-read Nameserver|null $ns5 Nameserver 5
 */
class NameserversParams extends DataSet
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
     * @param Nameserver|array|null $ns1
     *
     * @return static $this
     */
    public function setNs1($ns1)
    {
        $this->setValue('ns1', $ns1);
        return $this;
    }

    /**
     * @param Nameserver|array|null $ns2
     *
     * @return static $this
     */
    public function setNs2($ns2)
    {
        $this->setValue('ns2', $ns2);
        return $this;
    }

    /**
     * @param Nameserver|array|null $ns3
     *
     * @return static $this
     */
    public function setNs3($ns3)
    {
        $this->setValue('ns3', $ns3);
        return $this;
    }

    /**
     * @param Nameserver|array|null $ns4
     *
     * @return static $this
     */
    public function setNs4($ns4)
    {
        $this->setValue('ns4', $ns4);
        return $this;
    }

    /**
     * @param Nameserver|array|null $ns5
     *
     * @return static $this
     */
    public function setNs5($ns5)
    {
        $this->setValue('ns5', $ns5);
        return $this;
    }
}
