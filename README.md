# [Upmind Provision Providers](https://github.com/upmind-automation) - Domain Names

[![Latest Version on Packagist](https://img.shields.io/packagist/v/upmind/provision-provider-domain-names.svg?style=flat-square)](https://packagist.org/packages/upmind/provision-provider-domain-names)

This provision category contains common functions used in domain name provisioning flows with various registries and registrar/reseller platforms.

- [Installation](#installation)
- [Usage](#usage)
  - [Quick-start](#quick-start)
- [Supported Providers](#supported-providers)
- [Functions](#functions)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)
- [Upmind](#upmind)

## Installation

```bash
composer require upmind/provision-provider-domain-names
```

## Usage

This library makes use of [upmind/provision-provider-base](https://packagist.org/packages/upmind/provision-provider-base) primitives which we suggest you familiarize yourself with by reading the usage section in the README.

### Quick-start

The easiest way to see this provision category in action and to develop/test changes is to install it in [upmind/provision-workbench](https://github.com/upmind-automation/provision-workbench#readme).

Alternatively you can start using it for your business immediately with [Upmind.com](https://upmind.com/start) - the ultimate web hosting billing and management solution.

**If you wish to develop a new Provider, please refer to the [WORKFLOW](WORKFLOW.md) guide.**

## Supported Providers

The following providers are currently implemented:
  - [OpenSRS](https://domains.opensrs.guide/docs/quickstart)
  - [HRS](https://domains.opensrs.guide/docs/quickstart)
  - [Hexonet](https://wiki.hexonet.net/wiki/Domain_API)
  - [Nominet](https://registrars.nominet.uk/uk-namespace/registration-and-domain-management/registration-systems/epp/epp-commands/)
  - [NameSilo](https://www.namesilo.com/api-reference#domains/register-domain)
  - [OpenProvider](https://docs.openprovider.com/doc/all#tag/descDomainQuickstart)
  - [ConnectReseller](https://www.connectreseller.com/integration-options/#api)
  - [DomainNameApi](https://www.domainnameapi.com/domain-reseller-api)
  - [Enom](https://cp.enom.com/APICommandCatalog/API%20topics/api_Command_Categories.htm)
  - [LogicBoxes](https://manage.logicboxes.com/kb/servlet/KBServlet/cat119.html)
  - [ResellerClub](https://manage.resellerclub.com/kb/servlet/KBServlet/cat119.html)
  - [NetEarthOne](https://manage.netearthone.com/kb/servlet/KBServlet/cat119.html)
  - [Resell.biz](https://cp.us2.net/kb/servlet/KBServlet/cat119.html)
  - [CoCCA](https://cocca.org.nz/)
  - [NIRA](https://nira.ng/become-a-registrar)
  - [Ricta](https://www.ricta.org.rw/become-a-registrar/)
  - [UGRegistry](https://registry.co.ug/docs/v2/)
  - [Namecheap](https://www.namecheap.com/support/api/methods/)
  - [CentralNic Registry](https://centralnic.support/hc/en-gb/articles/4403312126993-Where-do-I-find-the-Registry-API-documentation-)
  - [CentralNic Reseller](https://kb.centralnicreseller.com/api/api-commands/api-command-reference)
  - [GoDaddy](https://developer.godaddy.com/doc/endpoint/domains)
  - [Realtime Register](https://dm.realtimeregister.com/docs/api/domains)
  - [Internet.bs](https://internetbs.net/internet-bs-api.pdf)
  - [EuroDNS](https://whois.eurodns.com/doc/domain/info)
  - [InternetX](https://help.internetx.com/display/APIXMLEN/Domain+tasks)
  - [EURid](https://eurid.eu/en/become-a-eu-registrar/accreditation-criteria/)
  - [TPP Wholesale](https://www.tppwholesale.com.au/api/)
  - [Synergy Wholesale](https://synergywholesale.com/wp-content/uploads/2024/06/Synergy-Wholesale-API-Documentation-v3-11.pdf)

## Functions

| Function | Parameters | Return Data | Description |
|---|---|---|---|
| poll() | [_PollParams_](src/Data/PollParams.php) | [_PollResult_](src/Data/PollResult.php) | Poll for the latest relevant domain event notifications e.g., successful transfer-in, domain deletion etc |
| domainAvailabilityCheck() | [_DacParams_](src/Data/DacParams.php) | [_DacResult_](src/Data/DacResult.php) | Check the availability of a domain SLD across one or more TLDs |
| register() | [_RegisterDomainParams_](src/Data/RegisterDomainParams.php) | [_DomainResult_](src/Data/DomainResult.php) | Register a new domain name |
| transfer() | [_TransferParams_](src/Data/TransferParams.php) | [_DomainResult_](src/Data/DomainResult.php) | Initiate and/or check a domain name transfer, returning successfully if transfer is complete |
| renew() | [_RenewParams_](src/Data/RenewParams.php) | [_DomainResult_](src/Data/DomainResult.php) | Renew a domain name for a given number of years |
| getInfo() | [_DomainInfoParams_](src/Data/DomainInfoParams.php) | [_DomainResult_](src/Data/DomainResult.php) | Get information about a domain name including status, expiry date, nameservers, contacts etc |
| updateRegistrantContact() | [_UpdateDomainContactParams_](src/Data/UpdateDomainContactParams.php) | [_ContactResult_](src/Data/ContactResult.php) | Update the registrant contact details of a domain name |
| updateNameservers() | [_UpdateNameserversParams_](src/Data/UpdateNameserversParams.php) | [_NameserversResult_](src/Data/NameserversResult.php) | Update a domain's nameservers |
| setLock() | [_LockParams_](src/Data/LockParams.php) | [_DomainResult_](src/Data/DomainResult.php) | Lock or unlock a domain name for transfers and changes |
| setAutoRenew() | [_AutoRenewParams_](src/Data/AutoRenewParams.php) | [_DomainResult_](src/Data/DomainResult.php) | Toggle registry auto-renewal for a domain name |
| getEppCode() | [_EppParams_](src/Data/EppParams.php) | [_EppCodeResult_](src/Data/EppCodeResult.php) | Get the EPP/Auth code of a domain name |
| updateIpsTag() | [_IpsTagParams_](src/Data/IpsTagParams.php) | [_ResultData_](src/Data/ResultData.php) | Release a domain name to a new IPS tag (UK-only) |

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

 - [Harry Lewis](https://github.com/uphlewis)
 - [Nayden Panchev](https://github.com/airnayden)
 - [Ivaylo Georgiev](https://github.com/Georgiev-Ivaylo)
 - [Nikolai Arsov](https://github.com/nikiarsov777)
 - [Codeline](https://codeline.fi/)
 - [PEWEO](https://www.peweo.com/)
 - [Dan](https://github.com/domainregistrar)
-  [Roussetos Karafyllakis](https://github.com/RoussKS)
 - [All Contributors](../../contributors)

## License

GNU General Public License version 3 (GPLv3). Please see [License File](LICENSE.md) for more information.

## Upmind

Sell, manage and support web hosting, domain names, ssl certificates, website builders and more with [Upmind.com](https://upmind.com/start).
