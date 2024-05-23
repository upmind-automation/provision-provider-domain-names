<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Result of a Domain Availability Check.
 *
 * @property-read DacDomain[] $domains
 */
class DacResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'domains' => ['present', 'array'],
            'domains.*' => [DacDomain::class],
        ]);
    }
}
