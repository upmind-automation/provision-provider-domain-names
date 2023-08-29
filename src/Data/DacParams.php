<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read string $sld
 * @property-read string[] $tlds
 */
class DacParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash', 'min:2', 'max:63', 'regex:/^[a-z\d](-*[a-z\d])*$/i'],
            'tlds' => ['required', 'array'],
            'tlds.*' => ['required', 'alpha-dash-dot'],
        ]);
    }
}
