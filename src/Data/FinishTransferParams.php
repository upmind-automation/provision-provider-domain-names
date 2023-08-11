<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for finish transfer of domain.
 *
 * @property-read string $sld
 * @property-read string $tld
 * @property-read string $transfer_order_id Transfer order ID
 */
class FinishTransferParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'transfer_order_id' => ['nullable'],
        ]);
    }
}
