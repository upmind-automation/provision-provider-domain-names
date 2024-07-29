<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read int $count_remaining
 * @property-read DomainNotification[] $notifications
 */
class PollResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'count_remaining' => ['required', 'integer'],
            'notifications' => ['present', 'array'],
            'notifications.*' => [DomainNotification::class]
        ]);
    }
}
