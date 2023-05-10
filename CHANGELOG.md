# Changelog

All notable changes to the package will be documented in this file.

## v2.2.3 2023-05-10

- Normalize OpenProvider .es state name during contact create/update

## v2.2.2 2023-04-28

- Fix OpenProvider _getDomain() undefined index errors

## v2.2.1 2023-04-07

- Add .pt to list of TLDs which don't support WHOIS privacy

## v2.2.0 2023-04-05

- Require `upmind/provision-provider-base` ^3.7
- Implement Namecheap provider

## v2.1.0 2023-03-15

- Add Example provider as a basic template for creating new providers
- Add more builder methods to result data classes
- Add stateless Demo provider which returns fake data

## v2.0.2 - 2023-03-06

- Update ConnectReseller _createContact() always pass a CompanyName in
  AddRegistrantContact API call

## v2.0.1 - 2023-02-21

- Update Nominet EPP connect error handling
- Add missing provider logos

## v2.0 - 2023-02-17

Initial public release
