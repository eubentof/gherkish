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
    $details = ['name' => 'Ada'];

    /** @When the user is created */
    $user = User::create($details);

    /** @Then the user is persisted */
    expect($user->exists)->toBeTrue();
});
```

Each step docblock must have executable PHP code on the line directly below
it. Another step docblock, a regular comment, or a blank line is not considered
an implementation and causes `gherkish:check` to fail.

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

With `--check-outline-datasets`, `gherkish:check` also verifies that every
Scenario Outline maps its Examples rows to the matching Pest test. Use
`Gherkish::examples()`, combine labeled
blocks with `Gherkish::examples('Label')`, or provide a static literal dataset
whose values exactly match the Examples table:

```php
test('User logs in', function (string $email, string $password, string $result) {
    // ...
})->with([
    ['john@test.com', 'correct', 'success'],
    ['missing@test.com', 'anything', 'failure'],
]);
```

Gherkish does not execute dynamic dataset providers while checking parity.
Datasets that cannot be verified statically should use `Gherkish::examples()`.
If a custom provider is required, place the scoped ignore comment directly
above the Pest test:

```php
// @gherkish-ignore-examples
test('User logs in', function (string $email, string $password, string $result) {
    // Scenario and step parity are still checked.
})->with(customLoginDataset());
```

This skips only Examples-to-dataset validation. The checker still validates
the scenario description, step docblocks, and executable code below each step.

## Command options

Pass these options to `php artisan gherkish:check`:

| Option | Description | Environment variable |
| --- | --- | --- |
| `--dir=tests/Feature` | Limit the check to feature files inside a directory. | `FEATURE_PARITY_DIR` |
| `--feature=tests/Feature/Users/CreateUser.feature` | Check a specific feature file. | `FEATURE_PARITY_FILE` or `FEATURE_PARITY_FEATURE` |
| `--file=tests/Feature/Users/CreateUser.feature` | Alias for `--feature`. | `FEATURE_PARITY_FILE` or `FEATURE_PARITY_FEATURE` |
| `--f=tests/Feature/Users/CreateUser.feature` | Short alias for `--feature`. | `FEATURE_PARITY_FILE` or `FEATURE_PARITY_FEATURE` |
| `--check-outline-datasets` | Validate Scenario Outline datasets against their Examples tables. | `FEATURE_PARITY_CHECK_OUTLINE_DATASETS=1` |
| `--snapshot=storage/app/feature-parity.json` | Write the coverage snapshot as JSON. | `FEATURE_PARITY_SNAPSHOT` |

## Development

```bash
composer install
composer test
composer format
```
