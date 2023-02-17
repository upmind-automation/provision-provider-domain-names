<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for setting domain renewing.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read Nameserver $ns1 Nameserver data
 * @property-read Nameserver $ns2 Nameserver data
 * @property-read Nameserver|null $ns3 Nameserver data
 * @property-read Nameserver|null $ns4 Nameserver data
 * @property-read Nameserver|null $ns5 Nameserver data
 */
class UpdateNameserversParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'ns1' => ['required', Nameserver::class],
            'ns2' => ['required', Nameserver::class],
            'ns3' => ['nullable', Nameserver::class],
            'ns4' => ['nullable', Nameserver::class],
            'ns5' => ['nullable', Nameserver::class],
        ]);
    }
}
