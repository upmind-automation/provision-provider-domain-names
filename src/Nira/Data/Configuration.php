<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nira\Data;

use Upmind\ProvisionBase\Provider\DataSet\Rules;
use Upmind\ProvisionProviders\DomainNames\CoccaEpp\Data\Configuration as CoccaEppConfiguration;

/**
 * Nira configuration.
 *
 * Even though the configuration extends the CoccaEppConfiguration, only 2 property-reads are available.
 * Do Not Use any inherited property-reads from the CoccaEppConfiguration.
 *
 * @property-read string $epp_username
 * @property-read string $epp_password
 */
class Configuration extends CoccaEppConfiguration
{
    public static function rules(): Rules
    {
        return new Rules([
            'epp_username' => ['required', 'string', 'min:1'],
            'epp_password' => ['required', 'string', 'min:6'],
        ]);
    }
}
