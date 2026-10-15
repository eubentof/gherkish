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

## Synchronizing feature and test paths

A test can point directly to a feature anywhere in the project using a header
comment, before imports or test definitions (paths are relative to the project
root):

```php
<?php

// @feature app/Domain/Users/CreateUser.feature

use Gherkish\Gherkish;
```

Repeat `@feature` for a test file that implements multiple features. You can
also use `/** @feature app/Domain/Users/CreateUser.feature */`.

Synchronize both sides before running the checker or tests:

```bash
php artisan gherkish:sync --dry-run
php artisan gherkish:sync
php artisan gherkish:check
```

The command scans `app/` and `tests/`, combines existing feature `@tests:`
declarations and test header `@feature` annotations, and fills in missing links
on both sides. Features without an explicit `@tests:` section also include their
existing sibling test. New `@tests:` sections appear below the feature description,
before Background or scenarios. Existing declarations are retained; sync does
not infer pairings from scenario titles or remove obsolete links. Remove obsolete
links on both sides before syncing. Missing declared files fail validation before
any files are written. Repeating the command makes no further changes.

`Gherkish::examples()` resolves header annotations first, then the conventional
sibling feature, then features under `app/` and `tests/` whose `@tests:` declaration
names the calling test. With multiple features, the Pest title must identify one
Scenario Outline unambiguously. Sync is optional for dataset resolution: the
written annotations provide a persistent direct lookup without a separate cache
file or pre-run hook. Run sync after adding test-only annotations so the parity
checker also receives their corresponding `@tests:` declarations.

Existing sibling pairings and `Gherkish::examples()` calls remain compatible.
Synchronization edits source files only when explicitly invoked.

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
| `--structure` | Validate English Gherkin syntax and feature structure. | `GHERKISH_CHECK_STRUCTURE=1` |
| `--phased`, `-p` | Require every Scenario and Scenario Outline to contain effective Given, When, and Then phases. | `GHERKISH_STRICT=1` |
| `--long`, `-l` | Show every scenario, step, and Examples table instead of compact status dots. | — |
| `--snapshot=storage/app/feature-parity.json` | Write the coverage snapshot as JSON. | `GHERKISH_SNAPSHOT` |

Configuration variables use the `GHERKISH_` prefix. Replace any previous
`FEATURE_PARITY_*` variables in local environments and CI configuration.

## Step Data Tables and Doc Strings

Use `Gherkish::dataTable()` beneath a mapped step annotation to read its table.
The first row supplies unique, non-empty headers; subsequent rows become
associative arrays. Values remain strings, including empty cells and `0`:

```gherkin
Scenario: Import users
  Given the following users exist:
    | name | email            |
    | Ada  | ada@example.test |
```

```php
it('Import users', function () {
    /** @Given the following users exist: */
    $users = Gherkish::dataTable();
    // [['name' => 'Ada', 'email' => 'ada@example.test']]
});
```

`Gherkish::dataTable(raw: true)` returns every row as a list, including the
first row. Use it for headerless tables or repeated first-row values. Tables
must have consistent row widths. A header-only associative table returns `[]`.
Both step tables and Examples decode `\n`, `\|`, and `\\` escapes.

Use `Gherkish::docs()` for the full dedented Doc String beneath a step.
Triple quotes and triple backticks, escaped fences, and optional content types
are supported. Line endings become `\n`; extra indentation and blank lines
are preserved. Content types are stored without automatic deserialization.

```gherkin
Scenario: Read a post
  Given a draft post
    """markdown
    Some Title, Eh?
    ===============

    The first paragraph.
    """
```

```php
it('Read a post', function () {
    /** @Given a draft post */
    $body = Gherkish::docs('Some Title, Eh?'); // 'The first paragraph.'
});
```

The optional title selects a leading Markdown Setext heading: the title line
must be followed by at least three `=` or `-` characters. The heading,
underline, and following blank separator lines are removed. Remaining body
formatting is preserved. Missing or mismatched titles raise an exception.
Without a title, the heading remains part of the returned string.

Both helpers use the containing Pest test, feature pairing, and nearest
preceding step annotation. Calls inside helper functions can use the calling
test's annotation. For explicit selection, pass step text and, when necessary,
a scenario title:

```php
Gherkish::dataTable(step: 'the following users exist:', scenario: 'Import users');
Gherkish::docs(step: 'a draft post', scenario: 'Read a post');
```

Selectors must identify one source step across the paired features. Missing,
ambiguous, or wrong-type arguments raise `Gherkish\Arguments\StepArgumentException`.
An empty argument is distinct from a missing argument. Helpers require a Pest
test or `beforeEach()` callback. Shared setup can infer a Background argument
only when all applicable scenarios resolve to the same source step; otherwise
pass `scenario:` explicitly.

Scenario Outline arguments use explicit string replacements from the active
dataset row. Pass `values:`; helpers do not inspect callback variables:

```php
it('Import a user', function (string $email) {
    /** @Given an imported user */
    $users = Gherkish::dataTable(values: ['email' => $email]);
})->with(Gherkish::examples());
```

The table or Doc String can contain `<email>`. Missing replacement keys fail
clearly. Ordinary scenarios preserve literal angle brackets.

## English Gherkin structure

Gherkish recognizes `Example`, `Scenario Template`, and `Scenarios` as aliases
for `Scenario`, `Scenario Outline`, and `Examples`. Asterisk steps map to
`/** @* step text */` annotations. They inherit a phase for `--phased` and
cannot establish one. Rule backgrounds apply only to their Rule's scenarios;
feature backgrounds still apply to every scenario. Applicable `beforeEach()`
annotations must implement both backgrounds. Background groups are reported
once per scope, and snapshots include Rule names and locations.

Tags, descriptions, and comments are retained by the shared parser. Tag
filtering and Cucumber step execution are outside this library's scope.

Enable syntax and structural validation independently of phase coverage:

```bash
php artisan gherkish:check --structure
php artisan gherkish:check --staged --structure --outlined --phased --unmapped
```

`--structure` or `GHERKISH_CHECK_STRUCTURE=1` checks selected English feature
files before parity. It is off by default. It validates grammar, section
placement, table widths, fenced arguments, and the feature-level `@tests:`
extension. Gherkish additionally requires one Feature and an Examples block
for each outline. An outline header may have no data rows. Valid syntax does
not require all three phases; `--phased` remains a separate policy.

Malformed files fail even if they contain no recognized scenario. The command
continues checking other files and returns exit code 1. Mapping and Examples
ignore directives do not suppress structure errors. Both output modes display
file, line, column, and error code; snapshots add a `structureErrors` array per
file and a `ruleBackgrounds` collection without removing existing fields.

Validation uses `cucumber/gherkin` `^40.0`, compatible with PHP 8.2, and requires
`ext-mbstring`. The grammar baseline is upstream PHP v40.0.0, revision
`c3b8380d208970273d059d7758cb1c0decd916e5`. That grammar permits one multiline
argument per step. Non-English language directives are unsupported. Structure
mode requires canonical English keyword spelling; compatibility mode retains
case-insensitive keywords and the legacy singular Examples spelling when a
table follows it. Use `Examples:` or `Scenarios:` for new outlines.

These additions preserve existing public helper and pairing contracts.
Correct newline escape decoding and isolation of Doc String payloads fix
previous parsing behavior. Rule Background coverage is now enforced against
its applicable setup. Structure validation is opt-in; use it to find previously
tolerated malformed files before enabling it in CI.

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
