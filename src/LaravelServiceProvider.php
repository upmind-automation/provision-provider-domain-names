<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames;

use Upmind\ProvisionBase\Laravel\ProvisionServiceProvider;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Example\Provider as ExampleProvider;
use Upmind\ProvisionProviders\DomainNames\Demo\Provider as DemoProvider;
use Upmind\ProvisionProviders\DomainNames\Namecheap\Provider as Namecheap;
use Upmind\ProvisionProviders\DomainNames\Nominet\Provider as Nominet;
use Upmind\ProvisionProviders\DomainNames\Hexonet\Provider as Hexonet;
use Upmind\ProvisionProviders\DomainNames\Enom\Provider as Enom;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Provider as OpenSRS;
use Upmind\ProvisionProviders\DomainNames\ConnectReseller\Provider as ConnectReseller;
use Upmind\ProvisionProviders\DomainNames\LogicBoxes\Provider as LogicBoxes;
use Upmind\ProvisionProviders\DomainNames\ResellerClub\Provider as ResellerClub;
use Upmind\ProvisionProviders\DomainNames\NetEarthOne\Provider as NetEarthOne;
use Upmind\ProvisionProviders\DomainNames\NameSilo\Provider as NameSilo;
use Upmind\ProvisionProviders\DomainNames\OpenProvider\Provider as OpenProvider;
use Upmind\ProvisionProviders\DomainNames\ResellBiz\Provider as ResellBiz;
use Upmind\ProvisionProviders\DomainNames\CoccaEpp\Provider as CoccaEpp;
use Upmind\ProvisionProviders\DomainNames\Nira\Provider as Nira;
use Upmind\ProvisionProviders\DomainNames\Ricta\Provider as Ricta;
use Upmind\ProvisionProviders\DomainNames\UGRegistry\Provider as UGRegistry;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Provider as DomainNameApi;
use Upmind\ProvisionProviders\DomainNames\CentralNic\Provider as CentralNic;
use Upmind\ProvisionProviders\DomainNames\GoDaddy\Provider as GoDaddy;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Provider as CentralNicReseller;
use Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Provider as RealtimeRegister;
use Upmind\ProvisionProviders\DomainNames\InternetBS\Provider as InternetBS;
use Upmind\ProvisionProviders\DomainNames\EuroDNS\Provider as EuroDNS;
use Upmind\ProvisionProviders\DomainNames\InternetX\Provider as InternetX;
use Upmind\ProvisionProviders\DomainNames\EURID\Provider as EURID;
use Upmind\ProvisionProviders\DomainNames\TPPWholesale\Provider as TPPWholesale;
use Upmind\ProvisionProviders\DomainNames\SynergyWholesale\Provider as SynergyWholesale;
use Upmind\ProvisionProviders\DomainNames\Norid\Provider as Norid;

class LaravelServiceProvider extends ProvisionServiceProvider
{
    public function boot()
    {
        $this->bindCategory('domain-names', DomainNames::class);

        // $this->bindProvider('domain-names', 'example', ExampleProvider::class);

        $this->bindProvider('domain-names', 'demo', DemoProvider::class);
        $this->bindProvider('domain-names', 'nominet', Nominet::class);
        $this->bindProvider('domain-names', 'hexonet', Hexonet::class);
        $this->bindProvider('domain-names', 'enom', Enom::class);
        $this->bindProvider('domain-names', 'opensrs', OpenSRS::class);
        $this->bindProvider('domain-names', 'hrs', HRS\Provider::class);
        $this->bindProvider('domain-names', 'connect-reseller', ConnectReseller::class);
        $this->bindProvider('domain-names', 'logic-boxes', LogicBoxes::class);
        $this->bindProvider('domain-names', 'resellerclub', ResellerClub::class);
        $this->bindProvider('domain-names', 'netearthone', NetEarthOne::class);
        $this->bindProvider('domain-names', 'namesilo', NameSilo::class);
        $this->bindProvider('domain-names', 'openprovider', OpenProvider::class);
        $this->bindProvider('domain-names', 'resell-biz', ResellBiz::class);
        $this->bindProvider('domain-names', 'cocca', CoccaEpp::class);
        $this->bindProvider('domain-names', 'nira', Nira::class);
        $this->bindProvider('domain-names', 'ricta', Ricta::class);
        $this->bindProvider('domain-names', 'ug-registry', UGRegistry::class);
        $this->bindProvider('domain-names', 'domain-name-api', DomainNameApi::class);
        $this->bindProvider('domain-names', 'namecheap', Namecheap::class);
        $this->bindProvider('domain-names', 'centralnic', CentralNic::class);
        $this->bindProvider('domain-names', 'godaddy', GoDaddy::class);
        $this->bindProvider('domain-names', 'centralnic-reseller', CentralNicReseller::class);
        $this->bindProvider('domain-names', 'realtime-register', RealtimeRegister::class);
        $this->bindProvider('domain-names', 'internetbs', InternetBS::class);
        $this->bindProvider('domain-names', 'eurodns', EuroDNS::class);
        $this->bindProvider('domain-names', 'internetx', InternetX::class);
        $this->bindProvider('domain-names', 'eurid', EURID::class);
        $this->bindProvider('domain-names', 'tpp-wholesale', TPPWholesale::class);
        $this->bindProvider('domain-names', 'synergy-wholesale', SynergyWholesale::class);
        $this->bindProvider('domain-names', 'norid', Norid::class);
    }
}
