<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\ResellBiz;

use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\LogicBoxes\Provider as LogicBoxesProvider;

class Provider extends LogicBoxesProvider
{
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Resell.biz')
            ->setDescription(
                'Resell.biz provides low-cost domain registration, '
                    . 'domain management, and hosting services for thousands of resellers worldwide'
            )
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/resell-biz-logo.jpeg');
    }
}
