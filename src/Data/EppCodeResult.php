<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Epp code result.
 *
 * @property-read string $epp_code EPP/Auth transfer code
 */
class EppCodeResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'epp_code' => ['required', 'string'],
        ]);
    }

    /**
     * @return static $this
     */
    public function setEppCode(string $eppCode): self
    {
        $this->setValue('epp_code', $eppCode);
        return $this;
    }
}
