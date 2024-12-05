# Changelog

All notable changes to the package will be documented in this file.

## [v2.17.8](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.8) - 2024-12-05

- Fix OpenSrs getInfo() when nameserver_list is missing
- Remove ClientDeleteProhibited from lock statuses in providers:
  - Hexonet
  - CoccaEpp

## [v2.17.7](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.7) - 2024-11-22

- Update OpenSRS domain availability check to gracefully handle 'Invalid domain syntax' errors"

## [v2.17.6](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.6) - 2024-11-21

- Implement OpenSRS/HRS domain availability check

## [v2.17.5](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.5) - 2024-10-08

- Update TPPWholesaleResponse to gracefully handle HTML responses
- Add `account_id` and `account_option` to TPPWholesale configuration for register() and transfer()

## [v2.17.4](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.4) - 2024-09-27

- Update DomainNameApi add specific error for when registrant contact is not set, and workaround
  for updateRegistrantContact() when other contact types are also missing

## [v2.17.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.3) - 2024-09-25

- Update OpenProvider error handling, return api response data in provision result data

## [v2.17.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.2) - 2024-09-24

- Fix DomainNameAPI type error in domainInfoToResult()

## [v2.17.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.1) - 2024-09-19

- Remove existing order check from TPPWholesale renew()
- Update APPWholesale register() to pass AccountOption EXTERNAL in API call

## [v2.17.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.17.0) - 2024-09-09

- Add `auto_renew` to DomainResult
- Fix LogicBoxes _renewDomain() undefined index error

## [v2.16.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.16.1) - 2024-09-03

- Fix LogicBoxes _getDomain() whois_privacy bool cast

## [v2.16.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.16.0) - 2024-09-02

- Add `whois_privacy` to Register/Transfer params and DomainResult data
- Implement whois_privacy for LogicBoxes, OpenProvider + OpenSRS

## [v2.15.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.15.3) - 2024-09-02

- Update TPPWholesaleApi::checkMultipleDomains()
  -  Ensure only one result per domain, with sensible prioritisation
  -  Return available if the only result is that an order already exists

## [v2.15.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.15.2) - 2024-08-29

- Update TppWholesale renew() to throw an error if a scheduled renewal order already exists

## [v2.15.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.15.1) - 2024-08-20

- Update OpenSRS provider with exception handling for connection errors
- Update OpenSRS getEppCode() to reset EPP code if none set
- Tweak TppWHolesale inactive domain order data error message

## [v2.15.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.15.0) - 2024-08-15

- Implement Synergy Wholesale provider

## [v2.14.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.14.1) - 2024-08-15

- Remove invalid length validation rules from TPP Wholesale configuration

## [v2.14.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.14.0) - 2024-08-13

- Add optional `additional_fields` to RegisterDomainParams
- Implement TPP Wholesale provider

## [v2.13.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.13.1) - 2024-08-06

- Update Enom/Provider::updateNameservers() to return the param's NS, to avoid race condition
  where Enom does not immediately return the new NS when a "Get Info" is then called

## [v2.13.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.13.0) - 2024-07-29

- Update library for PHP 8 + Base lib v4

## [v2.12.25](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.25) - 2024-12-05

- Fix OpenSrs getInfo() when nameserver_list is missing
- Remove ClientDeleteProhibited from lock statuses in providers:
  - Hexonet
  - CoccaEpp

## [v2.12.24](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.24) - 2024-11-22

- Update OpenSRS domain availability check to gracefully handle 'Invalid domain syntax' errors"

## [v2.12.23](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.23) - 2024-11-21

- Implement OpenSRS/HRS domain availability check

## [v2.12.22](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.22) - 2024-10-08

- Update TPPWholesaleResponse to gracefully handle HTML responses
- Add `account_id` and `account_option` to TPPWholesale configuration for register() and transfer()

## [v2.12.21](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.21) - 2024-09-27

- Update DomainNameApi add specific error for when registrant contact is not set, and workaround
  for updateRegistrantContact() when other contact types are also missing

## [v2.12.20](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.20) - 2024-09-25

- Update OpenProvider error handling, return api response data in provision result data

## [v2.12.19](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.19) - 2024-09-24

- Fix DomainNameAPI type error in domainInfoToResult()

## [v2.12.18](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.18) - 2024-09-19

- Remove existing order check from TPPWholesale renew()
- Update APPWholesale register() to pass AccountOption EXTERNAL in API call

## [v2.12.17](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.17) - 2024-09-09

- Add `auto_renew` to DomainResult
- Fix LogicBoxes _renewDomain() undefined index error

## [v2.12.16](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.16) - 2024-09-03

- Fix LogicBoxes _getDomain() whois_privacy bool cast

## [v2.12.15](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.15) - 2024-09-02

- Add `whois_privacy` to Register/Transfer params and DomainResult data
- Implement whois_privacy for LogicBoxes, OpenProvider + OpenSRS

## [v2.12.14](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.14) - 2024-09-02

- Update TPPWholesaleApi::checkMultipleDomains()
  -  Ensure only one result per domain, with sensible prioritisation
  -  Return available if the only result is that an order already exists

## [v2.12.13](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.13) - 2024-08-29

- Update TppWholesale renew() to throw an error if a scheduled renewal order already exists

## [v2.12.12](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.12) - 2024-08-20

- Update OpenSRS provider with exception handling for connection errors
- Update OpenSRS getEppCode() to reset EPP code if none set
- Tweak TppWHolesale inactive domain order data error message

## [v2.12.11](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.11) - 2024-08-15

- Implement Synergy Wholesale provider

## [v2.12.10](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.10) - 2024-08-15

- Remove invalid length validation rules from TPP Wholesale configuration

## [v2.12.9](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.9) - 2024-08-13

- Add optional `additional_fields` to RegisterDomainParams
- Implement TPP Wholesale provider

## [v2.12.8](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.8) - 2024-08-06

- Update Enom/Provider::updateNameservers() to return the param's NS, to avoid race condition
  where Enom does not immediately return the new NS when a "Get Info" is then called

## [v2.12.7](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.7) - 2024-07-24

- Update DomainNameApi/Provider::contactParamsToSoap() fix for invalid/empty values

## [v2.12.6](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.6) - 2024-07-24

- Fix Utils::internationalPhoneToEpp() and eppPhoneToInternational() to work with invalid numbers

## [v2.12.5](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.5) - 2024-07-11

- Update CentralNicReseller/Helper/EppHelper::getContactInfo() to return null if object not found

## [v2.12.4](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.4) - 2024-07-02

- Fix EuroDNS unparenthesized ternary operation error

## [v2.12.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.3) - 2024-06-24

- Update EuroDNSApi::getDomainInfo(), ensure id is never empty

## [v2.12.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.2) - 2024-06-24

- Update EuroDNS fix null organisation parameter

## [v2.12.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.1) - 2024-05-31

- Update metaregistrar/php-epp-client dependency to ^1.0.12 to fix EPP extension issue with EurId

## [v2.12.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.12.0) - 2024-05-16

- Implement EurId provider

## [v2.11.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.11.0) - 2024-05-03

- Implement InternetX provider

## [v2.10.5](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.10.5) - 2024-04-15

- Update EnomApi::parseContact() map country code FX to FR

## [v2.10.4](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.10.4) - 2024-04-11

- Revert "OpenSRS providers to only set organisation on contacts when necessary" [v2.10.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.10.2)

## [v2.10.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.10.3) - 2024-04-08

- Fix ConnectReseller empty nameserver host DomainResult error

## [v2.10.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.10.2) - 2024-04-05

- Update ConnectReseller and OpenSRS providers to only set organisation on contacts when necessary

## [v2.10.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.10.1) - 2024-04-05

- Update CoccaEPP createContact() and updateContact() to only set organisation when necessary

## [v2.10.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.10.0) - 2024-04-04

- Add EuroDNS provider

## [v2.9.8](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.8) - 2024-03-15

- Add `disable_whois_privacy` to OpenProvider configuration to disable WHOIS privacy
  for new registrations

## [v2.9.7](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.7) - 2024-03-06

- Fix Enom transfer() issues with 'stuck' orders
  - Exclude transfer orders belonging to other registrars
  - Make additional call to get order data and check transferorderdetail status

## [v2.9.6](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.6) - 2024-02-16

- Update CentralNic getInfo() and transfer() to not return successful if domain belongs to another registrar

## [v2.9.5](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.5) - 2024-02-16

- Update OpenSRS transfer() to pass contact name as org_name if organisation is empty

## [v2.9.4](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.4) - 2024-01-16

- Update Register + Transfer params sld validation rules to forbid underscores

## [v2.9.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.3) - 2024-01-05

- Add .cloud to list of TLDs which don't support WHOIS privacy

## [v2.9.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.2) - 2023-12-22

- Update OpenProvider OTE/sandbox API URL

## [v2.9.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.1) - 2023-12-11

- Add LogicBoxes debug logging flag
- Update LogicBoxes API error handling
  - Catch 403 cloudflare responses
  - Throw errors for failed actions which don't have status 'error'

## [v2.9.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.9.0) - 2023-12-05

- Add HRS provider

## [v2.8.14](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.14) - 2023-10-25

- Update CoccaEpp\\Client handle unknown connection errors with a better error message

## [v2.8.13](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.13) - 2023-10-19

- Add more root TLDs to the unsupported list for WHOIS privacy

## [v2.8.12](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.12) - 2023-10-17

- Update RealtimeRegister transfer() to check if domain is active immediately after initiating transfer,
  necessary for TLDs that can transfer instantly

## [v2.8.11](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.11) - 2023-10-02

- Fix RealtimeRegister transfer() for .nu

## [v2.8.10](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.10) - 2023-09-29

- Update RealtimeRegister transfer()
  - Fix .nl transfers (omit period)
  - Default to admin contact for tech/billing, if missing

## [v2.8.9](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.9) - 2023-09-22

- Fix ConnectReseller _timestampToDateTime()

## [v2.8.8](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.8) - 2023-09-11

- Update LogicBoxes error message formatting; fix truncation issue where input string contains a period
- Update LogicBoxes API error results to include response in result data

## [v2.8.7](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.7) - 2023-08-29

- Fix DacParams sld validation

## [v2.8.6](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.6) - 2023-08-23

- Remove min length rules from RealtimeRegister configuration

## [v2.8.5](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.5) - 2023-08-01

- Fix OpenSRS debug/sandbox requests
- Improve OpenSRS improve auth error message
- Fix OpenSRS GetInfo where contact values are missing
- Fix OpenSRS empty string IPs returned for nameservers

## [v2.8.4](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.4) - 2023-07-17

- Update NameSilo register() to use Utils::tldSupportsWhoisPrivacy()

## [v2.8.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.3) - 2023-07-17

- Add .at to list of TLDs which don't support WHOIS privacy

## [v2.8.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.2) - 2023-07-12

- Loosen ContactResult validation; allow null `country_code`
- Fix DomainNameApi updateRegistrantContact() when admin/tech/billing contacts are already invalid

## [v2.8.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.1) - 2023-07-12

- Update Countries::nameToCode() to match localised + English country names
- Update DomainNameApi contactInfoToResult() to transform country name to code
- Fix duplicate statuses in DomainNameApi domainInfoToResult()

## [v2.8.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.8.0) - 2023-07-11

- Implement InternetBS provider

## [v2.7.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.7.1) - 2023-07-04

- Fix RealtimeRegister provider identifier
- Disable RealtimeRegister `poll()`
- Add RealtimeRegister logo, update description

## [v2.7.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.7.0) - 2023-07-03

- Implement RealtimeRegister provider

## [v2.6.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.6.0) - 2023-06-16

- Implement CentralNic reseller provider
- Relax ContactResult validation rules

## [v2.5.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.5.1) - 2023-06-14

- Add `Utils::tldSupportsLocking()`, disable lock for .io and .de
- Update Namecheap to use new Util method

## [v2.5.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.5.0) - 2023-06-12

- Implement GoDaddy provider
- Simplify obtaining name server hosts from params

## [v2.4.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.4.0) - 2023-06-01

- Implement CentralNic registry provider

## [v2.3.5](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.3.5) - 2023-06-01

- Fix OpenProvider transfer() fallback to default nameservers when NS lookup returns empty

## [v2.3.4](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.3.4) - 2023-05-31

- Update Namecheap contact phone processing to only convert to EPP format when
  required, to avoid errors for existing domains with invalid contact phone numbers
- Update Namecheap API error message formatting
- Update Namecheap renew() function, use reactivate method for expired domains
- Run code formatter

## [v2.3.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.3.3) - 2023-05-24

- Fix Enom transfer() logic to only return success upon transfer completion

## [v2.3.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.3.2) - 2023-05-12

- Fix transfer() contact handling where null is given

## [v2.3.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.3.1) - 2023-05-12

- Update Hexonet updateRegistrantContact() to always create a new contact object
  instead of updating existing handle

## [v2.3.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.3.0) - 2023-05-12

- Add nullable registrant, billing and tech contacts to TransferParams
- Make admin contact nullable in TransferParams
- Update ConnectReseller, Hexonet, LogicBoxes, NameSilo, OpenProvider and OpenSRS
  to use registrant contact data for transfers where available

## [v2.2.3](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.2.3) - 2023-05-10

- Normalize OpenProvider .es state name during contact create/update

## [v2.2.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.2.2) - 2023-04-28

- Fix OpenProvider _getDomain() undefined index errors

## [v2.2.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.2.1) - 2023-04-07

- Add .pt to list of TLDs which don't support WHOIS privacy

## [v2.2.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.2.0) - 2023-04-05

- Require `upmind/provision-provider-base` ^3.7
- Implement Namecheap provider

## [v2.1.0](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.1.0) - 2023-03-15

- Add Example provider as a basic template for creating new providers
- Add more builder methods to result data classes
- Add stateless Demo provider which returns fake data

## [v2.0.2](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.0.2) - 2023-03-06

- Update ConnectReseller _createContact() always pass a CompanyName in
  AddRegistrantContact API call

## [v2.0.1](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.0.1) - 2023-02-21

- Update Nominet EPP connect error handling
- Add missing provider logos

## [v2.0 -](https://github.com/upmind-automation/provision-provider-domain-names/releases/tag/v2.0) 2023-02-17

Initial public release
