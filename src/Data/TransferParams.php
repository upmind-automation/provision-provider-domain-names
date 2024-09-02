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
 * @property-read bool|null $whois_privacy Enable WHOIS privacy (should default to true)
 * @property-read string $epp_code EPP code for domain transfer
 * @property-read RegisterContactParams|null $registrant Registrant contact data
 * @property-read RegisterContactParams|null $billing Billing contact data
 * @property-read RegisterContactParams|null $tech Tech contact data
 * @property-read RegisterContactParams|null $admin Admin contact data
 */
class TransferParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash', 'regex:/^[^\_]+$/'],
            'tld' => ['required', 'alpha-dash-dot'],
            'renew_years' => ['integer', 'max:10'],
            'whois_privacy' => ['nullable', 'boolean'],
            'epp_code' => ['string'],
            'registrant' => ['nullable', RegisterContactParams::class],
            'billing' => ['nullable', RegisterContactParams::class],
            'tech' => ['nullable', RegisterContactParams::class],
            'admin' => ['nullable', RegisterContactParams::class],
        ]);
    }
}
