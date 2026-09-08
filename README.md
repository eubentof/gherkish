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

With `--outlined` (or `-o`), `gherkish:check` also verifies that every
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

## Background and beforeEach mapping

Background parity is part of the default check and is independent from
`--phased`. When a feature declares a `Background`, every matching scenario
test must have an applicable Pest `beforeEach()` whose step docblocks map all
Background steps. As with scenario steps, each mapped docblock must have
executable PHP directly below it.

```gherkin
Background:
  Given an authenticated administrator

Scenario: User opens the dashboard
  When the dashboard is requested
  Then the dashboard is displayed
```

```php
beforeEach(function () {
    /** @Given an authenticated administrator */
    $this->actingAs(User::factory()->admin()->create());
});

test('User opens the dashboard', function () {
    /** @When the dashboard is requested */
    $response = $this->get('/dashboard');

    /** @Then the dashboard is displayed */
    $response->assertOk();
});
```

Applicable `beforeEach()` step annotations also participate in normal scenario
mapping when the feature has no `Background`. This allows a `Given` declared in
a Scenario to be implemented once in shared Pest setup.

Scope follows Pest `describe()` nesting: a top-level `beforeEach()` applies to
every test in the file, while a setup inside `describe()` applies only to tests
inside that describe block and its nested descendants. It does not apply to a
sibling describe block.

Snapshots expose Background coverage once in the feature-level `background`
entry and list parsed setup annotations under `beforeEach`. Background steps
are not duplicated in each scenario's step cases. Descriptive command output
renders one `BACKGROUND` group per feature, and summary case totals count each
Background step once.

This changes the previous Background behavior: existing feature files whose
Background steps were ignored must move their matching annotations and
implementations into an applicable `beforeEach()`.

## Reverse test mapping

Use `--unmapped` (or `-u`) to also verify parity in the other direction:
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

## Phased scenario structure

Use `--phased` or `-p` to require every `Scenario` and `Scenario Outline` to
contain effective `Given`, `When`, and `Then` phases. `And` and `But` inherit the phase
established by the preceding primary keyword, but cannot establish a phase when
they appear before any `Given`, `When`, or `Then` step.

Phased structure validation is opt-in and independent from
`--outlined` and `--unmapped`. It validates only the
steps declared inside each scenario; `Background` steps are not included in
this structure check.

```bash
php artisan gherkish:check --phased
```

## Command options

By default, the checker prints one compact status character per scenario: a
green `.` for covered, a red `F` for failed, or a yellow `S` for skipped.
Failure details are collected after the progress dots. Use `--long` or `-l` to
show the full scenario, step, and Examples output. The summary includes the
total inner step cases checked across all scenarios and the command duration.

Pass these options to `php artisan gherkish:check`:

| Option | Description | Environment variable |
| --- | --- | --- |
| `--dir=tests/Feature` | Limit the check to feature files and, for reverse validation, Pest tests inside a directory. | `GHERKISH_DIR` |
| `--feature=tests/Feature/Users/CreateUser.feature` | Check a specific feature file. | `GHERKISH_FILE` or `GHERKISH_FEATURE` |
| `--file=tests/Feature/Users/CreateUser.feature` | Alias for `--feature`. | `GHERKISH_FILE` or `GHERKISH_FEATURE` |
| `--f=tests/Feature/Users/CreateUser.feature` | Short alias for `--feature`. | `GHERKISH_FILE` or `GHERKISH_FEATURE` |
| `--staged`, `-s` | Check only staged feature files and features paired with staged Pest tests. Cannot be combined with a path filter. | — |
| `--outlined`, `-o` | Validate Scenario Outline datasets against their Examples tables. | `GHERKISH_CHECK_OUTLINE_DATASETS=1` |
| `--unmapped`, `-u` | Validate that every Pest test maps to a Gherkin scenario. | `GHERKISH_CHECK_UNMAPPED_TESTS=1` |
| `--phased`, `-p` | Require every Scenario and Scenario Outline to contain effective Given, When, and Then phases. | `GHERKISH_STRICT=1` |
| `--long`, `-l` | Show every scenario, step, and Examples table instead of compact status dots. | — |
| `--snapshot=storage/app/feature-parity.json` | Write the coverage snapshot as JSON. | `GHERKISH_SNAPSHOT` |

Configuration variables use the `GHERKISH_` prefix. Replace any previous
`FEATURE_PARITY_*` variables in local environments and CI configuration.

Use `--staged` or `-s` in pre-commit workflows to select added, copied, modified, and
renamed files from the Git index:

```bash
php artisan gherkish:check --staged
```

Staged `.feature` files are checked against their mapped Pest files. Staged PHP
test files select same-basename features and features that list them under
`@tests:`. Deleted files, feature files outside the application's `app/` and
`tests/` roots, and unlinked tests outside those roots are ignored. The checker
validates the current working tree contents of each selected file and succeeds
with a skipped selection when no relevant files are staged.

## Development

```bash
composer install
composer test
composer format
```
