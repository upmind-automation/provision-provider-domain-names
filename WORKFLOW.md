# Workflow

This document outlines the development guidelines and basic workflow for implementing
new provision providers.

Ensure you also read the guidelines on [CONTRIBUTING](CONTRIBUTING.md).

## Resources

Links/resources to familiarize yourself with before you begin.

- [Upmind Provision Provider Base](https://github.com/upmind-automation/provision-provider-base#readme)
  - README explains the structure of Upmind provision providers; classes, responsibilities etc
- [Upmind Provision Workbench](https://github.com/upmind-automation/provision-workbench#readme)
  - A local development tool which provides a convenient UI for creating and managing provision configurations, running and inspecting provision functions etc

## Requirements

These are the acceptance criteria for new providers.

- Copy the `src/Example/` directory as a basic template for the new provider
- Implement the Configuration DTO class
- Implement PSR-3 debug logging of all API requests + responses
- Implement all provider functions where possible. E.g., where polling is not possible it’s fine to throw an error like “Operation not supported”
- Throw (or re-throw) normal/expected errors (e.g., data/state/auth issues) as a ProvisionFunctionError using $this->errorResult() - any other exceptions will be considered unexpected and wrapped in a generic error with a benign message
- Result messages and error messages must be ‘safe’ for end users/customers to read (not contain potentially sensitive information such as  credentials or references to code/classes/files etc) but should still be reasonably helpful - furthermore for domain names we should not expose the provider/registry name
- Additional information or helpful metadata can be returned in successful result debug or  error data/debug
- Any changes to `src/Category.php` or in `src/Data/` must be discussed with Upmind
- Any new 3rd-party dependencies/libraries must be approved by Upmind

## Workflow

Follow the below steps to create a new provider using Namecheap as an example:

1. Install the Upmind Provision Workbench
2. Fork this [repository](https://github.com/upmind-automation/provision-provider-domain-names)
3. Clone your fork into the `local/` directory where you have installed the provision workbench and run `composer install upmind/provision-provider-domain-names:@dev` - it will install from your fork in local/
4. In your fork of upmind/provision-provider-domain-names copy the `src/Example` directory to create `src/Namecheap` and update the namespace on files under `src/Namecheap`
5. Update the sample Configuration class for Namecheap API credentials (api_key, sandbox, debug)
6. Bind your new provider to the provision registry in `src/LaravelServiceProvider.php`
7. In the provision workbench terminal re-cache your local provision registry by running `php artisan upmind:provision:cache`
8. In the provision workbench UI (typically http://127.0.0.1:9000) create a Namecheap provision configuration
9. Now you can run provision functions (also known as provision requests) via the workbench UI as you develop them
10. When complete, submit a PR back to this [repository](https://github.com/upmind-automation/provision-provider-domain-names)
