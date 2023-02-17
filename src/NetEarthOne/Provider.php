<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\NetEarthOne;

use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\LogicBoxes\Provider as LogicBoxesProvider;

class Provider extends LogicBoxesProvider
{
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('NetEarthOne')
            ->setDescription(
                'NetEarthOne offers a one-stop platform which allows '
                . 'you, your Resellers and your Customers to buy, sell and '
                . 'manage various gTLD and ccTLD Domain Names.'
            )
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/netearthone-logo_2x.png');
    }
}
