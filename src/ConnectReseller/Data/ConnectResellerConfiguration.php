<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\ConnectReseller\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;

/**
 * ConnectReseller EPP credentials configuration
 *
 * @property-read string $api_key APIKey of the account
 * @property-read bool|null $sandbox Used for switching to testing environment
 * @property-read bool|null $debug Whether or not to debug log
 */
class ConnectResellerConfiguration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_key' => ['required', 'string'],
            'sandbox' => ['boolean'],
            'debug' => ['boolean'],
        ]);
    }
}
