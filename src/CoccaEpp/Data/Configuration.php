<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\CoccaEpp\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Nira configuration.
 *
 * @property-read string $epp_username
 * @property-read string $epp_password
 * @property-read string $hostname
 * @property-read integer|null $port
 * @property-read string|null $certificate
 * @property-read string|null $supported_tlds
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'epp_username' => ['required', 'string', 'min:1'],
            'epp_password' => ['required', 'string', 'min:6'],
            'hostname' => ['required', 'string', 'domain_name'],
            'port' => ['nullable', 'integer', 'min:1'],
            'certificate' => ['nullable', 'certificate_pem'],
            'supported_tlds' => ['nullable', 'string'],
        ]);
    }
}
