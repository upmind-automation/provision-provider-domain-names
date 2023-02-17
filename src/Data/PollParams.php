<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * @property-read int $limit
 * @property-read string|null $after_date
 */
class PollParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'limit' => ['required', 'integer'],
            'after_date' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);
    }
}
