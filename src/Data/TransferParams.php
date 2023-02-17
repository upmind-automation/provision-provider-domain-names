<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for transfer of domain.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read int $renew_years For how long to be renewed upon transfer
 * @property-read string $epp_code EPP code for domain transfer
 * @property-read RegisterContactParams $admin Admin contact data
 */
class TransferParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'renew_years' => ['integer', 'max:10'],
            'epp_code' => ['string'],
            'admin' => ['required', RegisterContactParams::class],
        ]);
    }
}
