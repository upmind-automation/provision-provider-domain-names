# Changelog

All notable changes to the package will be documented in this file.

## v2.3.4 2023-05-31

- Update Namecheap contact phone processing to only convert to EPP format when
  required, to avoid errors for existing domains with invalid contact phone numbers
- Update Namecheap API error message formatting
- Update Namecheap renew() function, use reactivate method for expired domains
- Run code formatter

## v2.3.3 2023-05-24

- Fix Enom transfer() logic to only return success upon transfer completion

## v2.3.2 2023-05-12

- Fix transfer() contact handling where null is given

## v2.3.1 2023-05-12

- Update Hexonet updateRegistrantContact() to always create a new contact object
  instead of updating existing handle

## v2.3.0 2023-05-12

- Add nullable registrant, billing and tech contacts to TransferParams
- Make admin contact nullable in TransferParams
- Update ConnectReseller, Hexonet, LogicBoxes, NameSilo, OpenProvider and OpenSRS
  to use registrant contact data for transfers where available

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
