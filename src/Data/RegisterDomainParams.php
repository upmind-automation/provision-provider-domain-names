<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Params for domain registering.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read int $renew_years Years to renew
 * @property-read RegisterContactParams $registrant Registrant data
 * @property-read RegisterContactParams $billing Billing contact data
 * @property-read RegisterContactParams $tech Tech contact data
 * @property-read RegisterContactParams $admin Admin contact data
 * @property-read NameserversParams $nameservers Nameservers
 */
class RegisterDomainParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'renew_years' => ['required', 'integer', 'max:10'],
            'registrant' => ['required', RegisterContactParams::class],
            'billing' => ['required', RegisterContactParams::class],
            'tech' => ['required', RegisterContactParams::class],
            'admin' => ['required', RegisterContactParams::class],
            'nameservers' => ['required', NameserversParams::class],
        ]);
    }
}
