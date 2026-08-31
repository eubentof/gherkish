# Gherkish

Gherkish checks that each scenario in your Laravel application's `.feature`
files has a matching Pest test and matching `@Given`, `@When`, `@Then`, `@And`,
and `@But` docblocks.

## Installation

Install the package as a development dependency:

```bash
composer require --dev eubentof/gherkish
```

Laravel discovers the package automatically. Run the checker with:

```bash
php artisan gherkish:check
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

## Scenario Outline datasets

`Gherkish::examples()` reads the `Examples` table from the Scenario Outline
paired with the current Pest test. Its rows are returned as associative arrays,
ready for Pest's `with()` dataset method:

```gherkin
Scenario Outline: User logs in
  Given a user with email "<email>"
  When they log in with password "<password>"
  Then the result should be "<result>"

  Examples:
    | email            | password | result  |
    | john@test.com    | correct  | success |
    | missing@test.com | anything | failure |
```

```php
use Gherkish\Gherkish;

it('User logs in', function (string $email, string $password, string $result) {
    // ...
})->with(Gherkish::examples());
```

When an outline has multiple `Examples` blocks, give each one a label and pass
the desired label:

```gherkin
Examples: Valid credentials
  | email         | password | result  |
  | john@test.com | correct  | success |

Examples: Invalid credentials
  | email         | password | result  |
  | john@test.com | wrong    | failure |
```

```php
it('User logs in', function (string $email, string $password, string $result) {
    // ...
})->with([
    ...Gherkish::examples('Valid credentials'),
    ...Gherkish::examples('Invalid credentials'),
]);
```

The feature must use the normal same-directory pairing convention, such as
`Login.feature` and `LoginTest.php`. A label is optional when the current
outline contains exactly one `Examples` block and required when it contains
more than one.

## Command options

```bash
php artisan gherkish:check --dir=tests/Feature
php artisan gherkish:check --feature=tests/Feature/Users/CreateUser.feature
php artisan gherkish:check --file=tests/Feature/Users/CreateUser.feature
php artisan gherkish:check --f=tests/Feature/Users/CreateUser.feature
php artisan gherkish:check --snapshot=storage/app/feature-parity.json
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
