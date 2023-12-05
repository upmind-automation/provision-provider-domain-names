# Changelog

All notable changes to the package will be documented in this file.

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
