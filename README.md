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
one or more desired labels. Rows are combined in the same order as the labels:

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
})->with(Gherkish::examples('Valid credentials', 'Invalid credentials'));
```

The feature must use the normal same-directory pairing convention, such as
`Login.feature` and `LoginTest.php`. A label is optional when the current
outline contains exactly one `Examples` block and required when it contains
more than one.

With `--check-outline-datasets`, `gherkish:check` also verifies that every
Scenario Outline maps its Examples rows to the matching Pest test. Use
`Gherkish::examples()`, combine labeled blocks with
`Gherkish::examples('First label', 'Second label')`, or provide a static literal
dataset whose values exactly match the Examples table:

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

## Reverse test mapping

Use `--check-unmapped-tests` to also verify parity in the other direction:
every Pest `test()` or `it()` block in the selected scope must map to a Scenario
in its paired feature file or in a feature file that lists the test under
`@tests:`.

If a Pest test intentionally has no feature scenario, place the scoped ignore
comment directly above it:

```php
// @gherkish-ignore-mapping
test('covers an internal implementation detail', function () {
    // This test is excluded only from reverse mapping validation.
});
```

Feature-to-test scenario and step checks still run normally. The ignore comment
only skips the opt-in reverse mapping check for that Pest test.

## Strict scenario structure

Use `--strict` to require every `Scenario` and `Scenario Outline` to contain
effective `Given`, `When`, and `Then` phases. `And` and `But` inherit the phase
established by the preceding primary keyword, but cannot establish a phase when
they appear before any `Given`, `When`, or `Then` step.

Strict structure validation is opt-in and independent from
`--check-outline-datasets` and `--check-unmapped-tests`. It validates only the
steps declared inside each scenario; `Background` steps are not included in
this structure check.

```bash
php artisan gherkish:check --strict
```

## Command options

By default, the checker prints one compact status character per scenario: a
green `.` for covered, a red `F` for failed, or a yellow `S` for skipped.
Failure details are collected after the progress dots. Use `--descriptive` to
show the full scenario, step, and Examples output. The summary includes the
total inner step cases checked across all scenarios and the command duration.

Pass these options to `php artisan gherkish:check`:

| Option | Description | Environment variable |
| --- | --- | --- |
| `--dir=tests/Feature` | Limit the check to feature files and, for reverse validation, Pest tests inside a directory. | `FEATURE_PARITY_DIR` |
| `--feature=tests/Feature/Users/CreateUser.feature` | Check a specific feature file. | `FEATURE_PARITY_FILE` or `FEATURE_PARITY_FEATURE` |
| `--file=tests/Feature/Users/CreateUser.feature` | Alias for `--feature`. | `FEATURE_PARITY_FILE` or `FEATURE_PARITY_FEATURE` |
| `--f=tests/Feature/Users/CreateUser.feature` | Short alias for `--feature`. | `FEATURE_PARITY_FILE` or `FEATURE_PARITY_FEATURE` |
| `--check-outline-datasets` | Validate Scenario Outline datasets against their Examples tables. | `FEATURE_PARITY_CHECK_OUTLINE_DATASETS=1` |
| `--check-unmapped-tests` | Validate that every Pest test maps to a Gherkin scenario. | `FEATURE_PARITY_CHECK_UNMAPPED_TESTS=1` |
| `--strict` | Require every Scenario and Scenario Outline to contain effective Given, When, and Then phases. | `FEATURE_PARITY_STRICT=1` |
| `--descriptive` | Show every scenario, step, and Examples table instead of compact status dots. | — |
| `--snapshot=storage/app/feature-parity.json` | Write the coverage snapshot as JSON. | `FEATURE_PARITY_SNAPSHOT` |

## Development

```bash
composer install
composer test
composer format
```
