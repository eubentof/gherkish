# Gherkish

Gherkish checks that each scenario in your Laravel application's `.feature`
files has a matching Pest test and matching `@Given`, `@When`, `@Then`, `@And`,
and `@But` docblocks.

## Installation

Install the package as a development dependency:

```bash
composer require --dev filipebento/gherkish
```

Laravel discovers the package automatically. Run the checker with:

```bash
php artisan features:check
```

By default, Gherkish recursively discovers `.feature` files below `app/` and
`tests/`. A feature is paired with a test of the same basename in the same
directory:

```text
CreateUser.feature
CreateUserTest.php
```

A feature can instead declare one or more test files:

```gherkin
Feature: Create a user

  @tests:
  - tests/Feature/Users/CreateUserTest.php

  Scenario: should create a user
    Given valid user details
    When the user is created
    Then the user is persisted
```

The Pest description and step docblocks must match:

```php
it('should create a user', function () {
    /** @Given valid user details */
    /** @When the user is created */
    /** @Then the user is persisted */
});
```

## Command options

```bash
php artisan features:check --dir=tests/Feature
php artisan features:check --feature=tests/Feature/Users/CreateUser.feature
php artisan features:check --file=tests/Feature/Users/CreateUser.feature
php artisan features:check --f=tests/Feature/Users/CreateUser.feature
php artisan features:check --snapshot=storage/app/feature-parity.json
```

The equivalent environment variables are `FEATURE_PARITY_DIR`,
`FEATURE_PARITY_FILE` (or `FEATURE_PARITY_FEATURE`), and
`FEATURE_PARITY_SNAPSHOT`.

## Development

```bash
composer install
composer test
composer format
```
