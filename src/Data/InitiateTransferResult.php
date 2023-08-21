<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Result for initiate transfer of domain.
 *
 * @property-read string $domain Domain name
 * @property-read string $transfer_status Transfer status
 * @property-read string $transfer_order_id Transfer order ID
 * @property-read string $domain_info Domain info
 */
class InitiateTransferResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'domain' => ['required', 'domain_name'],
            'transfer_status' => ['required', 'string', 'in:in_progress,complete'],
            'transfer_order_id' => ['nullable'],
            'domain_info' => ['required_if:transfer_status,complete', DomainResult::class]
        ]);
    }
}
