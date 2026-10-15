# Gherkin support, step arguments, and structure validation

Status: approved and implemented.
Reviewed: 2026-10-15.

## Goal

Make English Gherkin files reliable inputs to Gherkish, expose step arguments to Pest tests, and add an opt-in structure check. Preserve the library's role as a Pest parity checker; do not introduce a Cucumber execution engine.

Implementation includes the helpers, English syntax handling, scoped backgrounds, and opt-in structure validation described below.

## Reference and current implementation

The audit compares the [Gherkin reference](https://cucumber.io/docs/gherkin/reference/) with the current checkout. The [upstream grammar](https://github.com/cucumber/gherkin/blob/main/gherkin.berp) and [parser architecture](https://github.com/cucumber/gherkin/blob/main/README.md#architecture) inform parser design. Pin the upstream revision used by implementation fixtures rather than following `main` implicitly.

Repository evidence:

| Area | Implementation |
| --- | --- |
| Public API | `src/Gherkish.php` exposes only `examples()` |
| Feature parsing and parity | `src/FeatureParity/FeatureParityChecker.php`, especially `parseFeature()` and `normalizeStepSignature()` |
| Outline parsing and table decoding | `src/Examples/ExampleDatasetResolver.php`, especially `outlines()` and `parseTableRow()` |
| Parsed models | `FeatureDoc`, `ScenarioDoc`, `StepDoc`, `ScenarioOutline`, `ExampleBlock` |
| Pairing and synchronization | `src/Mapping/FeatureMap.php` |
| CLI and reporting | `src/Console/CheckFeaturesCommand.php`, `FeatureParityResult` |

### Coverage audit

| Reference capability | Current behavior | Planned treatment |
| --- | --- | --- |
| Feature and Scenario | Recognized; placement and multiplicity are not validated | Validate with the new flag |
| Given, When, Then, And, But | Parsed and mapped; optional phase coverage check exists | Preserve existing contracts |
| Example alias | Not recognized as a scenario boundary | Add alias |
| Scenario Template alias | Not recognized as an outline | Add alias |
| Scenarios alias for Examples | Not recognized; parser instead accepts nonstandard singular `Example:` as an Examples block | Add correct alias; preserve legacy behavior only without structure validation |
| Asterisk steps | Ignored by feature and PHP annotation parsing | Add explicit `@*` mapping |
| Rule | No model or scope; scenarios are flattened | Add grouping and scoped Background coverage |
| Feature Background | Checked against applicable `beforeEach()` | Retain; extend to Rule scope |
| Descriptions and comments | Usually skipped; not retained as structured metadata | Parse safely and retain on owning nodes |
| Tags | Ignored; no ownership or inheritance | Retain and validate placement; execution filtering deferred |
| Multiple labeled Examples | Supported by `examples()` and optional dataset parity | Preserve existing label-selection behavior |
| Outline substitution | Dataset rows and flexible title/step matching exist; no expanded step-argument values | Add explicit substitution for extracted arguments |
| Step Data Tables | Discarded; no public extraction helper | Add `dataTable()` |
| Table escapes | Examples decode escaped pipes and backslashes; escaped newline remains literal | Share and correct table decoding |
| Doc Strings | No delimiter state, content, content type, or extraction API | Add `docs()` and prevent content being parsed as steps |
| Structural errors | Unknown lines can be ignored; outline parser exceptions are swallowed by `parseFeature()` | Add file-level diagnostics |
| Localisation | English-oriented regexes | Out of scope |

Silently skipping syntax is not support. In particular, text beginning with a step keyword inside a Doc String can currently become a false step, and Rule backgrounds can leak into the single feature-wide Background collection.

Cucumber step-definition matching and automatic argument injection are outside this library's architecture. Keep keyword-sensitive PHP annotation matching and Pest-owned execution. A general Cucumber DataTable transformation API, tag expressions, hooks, and reporting formats are deferred.

## Public step-argument API

Proposed signatures, using named arguments for optional selectors:

```php
/** @return list<array<string, string>>|list<list<string>> */
public static function dataTable(
    ?string $step = null,
    ?string $scenario = null,
    array $values = [],
    bool $raw = false,
): array;

public static function docs(
    ?string $title = null,
    ?string $step = null,
    ?string $scenario = null,
    array $values = [],
): string;
```

`values` is `array<string, string>` containing outline replacements. It is optional for arguments without placeholders. These helpers read feature content; they do not execute steps, create records, or inject callback arguments.

### Data Tables

```gherkin
Scenario: Import users
  Given the following users exist:
    | name | email            |
    | Ada  | ada@example.test |
    | Lin  | lin@example.test |
```

```php
it('Import users', function () {
    /** @Given the following users exist: */
    $users = Gherkish::dataTable();
});
```

Expected result:

```php
[
    ['name' => 'Ada', 'email' => 'ada@example.test'],
    ['name' => 'Lin', 'email' => 'lin@example.test'],
]
```

The default treats the first row as headers, matching the associative convention of `examples()`. Header names must be nonempty and unique; all values remain strings, including numbers, booleans, and empty cells. A header-only table returns `[]`.

`Gherkish::dataTable(raw: true)` returns every row, including the first, as a list of string lists. This supports headerless tables, duplicate first-row values, and single-column lists without imposing an associative schema on valid Gherkin. Rows must have consistent widths in both modes.

Keep step tables separate from Examples tables. Share cell decoding, never their ownership or header semantics.

### Doc Strings and the requested title lookup

```gherkin
Scenario: Create a post
  Given a draft post
    """markdown
    Some Title, Eh?
    ===============
    Here is the first paragraph of my blog post. Lorem ipsum dolor sit amet,
    consectetur adipiscing elit.
    """
```

```php
it('Create a post', function () {
    /** @Given a draft post */
    $body = Gherkish::docs('Some Title, Eh?');
});
```

The result is exactly:

```text
Here is the first paragraph of my blog post. Lorem ipsum dolor sit amet,
consectetur adipiscing elit.
```

`docs()` without a title returns the full dedented string, including the heading and underline. The title overload is a Gherkish convenience, not standard Doc String semantics.

For this first version, title extraction supports a leading Markdown Setext heading: the first nonblank line must equal the supplied title after surrounding whitespace is removed; the next line must contain only three or more `=` or `-` characters, with optional surrounding whitespace. Remove that heading, its underline, and immediately following blank separator lines. Return the remaining body without globally trimming it. Do not search arbitrary text, remove later headings, or interpret HTML/ATX Markdown. Missing or mismatched headings raise an actionable exception.

Support both standard fence styles and optional content-type metadata. Preserve content type internally without interpreting JSON or Markdown automatically. Dedent against the opening fence, preserve additional indentation and blank lines, normalize line endings to `\n`, and exclude delimiter lines. Escaped delimiters must remain content. A valid empty Doc String returns `''`.

### Deterministic lookup

Reuse `FeatureMap::featuresForTest()` for the calling test file. Use the call's source location and PHP tokens to locate its containing `it()`/`test()` or `beforeEach()` and the nearest preceding Gherkish step annotation in that same callback. The annotation's scope ends at the next step annotation or the callback boundary. Never select a step by global table position.

Within a test callback, narrow scenarios using the declared Pest title and existing title-matching rules. Resolve the annotation against that scenario's own steps and applicable Background steps. Explicit `step` selects by step text; `scenario` selects a scenario title and overrides inferred title selection. Both selectors must resolve uniquely across paired features; no first-match fallback.

Inside `beforeEach()`, limit candidates to mapped scenarios whose Pest tests inherit that setup scope. An omitted scenario selector is allowed only when they share the same owning Background step node. Otherwise require `scenario:`. A helper wrapper can rely on a resolvable caller frame; calls without a resolvable annotated test/setup location must use explicit selectors from a calling test file. Calls outside test context fail clearly.

Missing feature mappings, ambiguous scenarios or steps, missing arguments, and requesting the wrong argument type throw a dedicated `StepArgumentException` with PHP and feature locations plus candidate selectors. Do not return an empty value to mask lookup failure. Keep `ExamplesException` compatibility for `examples()`.

### Outline arguments

```php
it('Import a user', function (string $email) {
    /** @Given the following user exists */
    $users = Gherkish::dataTable(values: ['email' => $email]);
})->with(Gherkish::examples());
```

Replace `<header>` in table cells and Doc String content using the explicitly supplied row values, after syntax decoding and before title extraction. Do not guess the active Pest dataset row or inspect callback local variables. Missing replacement keys in outline arguments raise an exception naming the placeholder. Extra keys are harmless. Literal angle brackets in ordinary scenarios remain unchanged. An outline needing literal `<...>` prose can supply that key's literal text explicitly.

## Opt-in structure validation

Add `--structure` and `GHERKISH_CHECK_STRUCTURE=1`, default off. No short alias is necessary.

```bash
php artisan gherkish:check --structure
php artisan gherkish:check --structure --feature=tests/Users.feature
php artisan gherkish:check --staged --structure --outlined --phased --unmapped
```

The flag runs file validation before parity for each selected feature. It uses the existing directory, file, and staged selection contracts, including working-tree reads for staged selections. Invalid selected files fail even when they contain no recognized scenario or have no matching Pest file. Continue with other files, but skip parity for a malformed file to avoid misleading secondary failures.

`--structure` checks English syntax and structural ownership. `--phased` retains its separate Given/When/Then coverage policy; valid syntax alone does not require all three phases. `--outlined` retains dataset parity, and neither ignore directive suppresses structure diagnostics.

### Validation contract

Enforce one Feature and legal container ordering; recognize aliases; limit each Feature/Rule to its preceding Background; assign steps, arguments, Examples, tags, descriptions, and comments to legal owners. Reject orphan arguments, inconsistent table widths, unclosed or mismatched fences, duplicate arguments of the same kind, and misplaced sections. Preserve source locations for errors. The pinned PHP v40.0.0 grammar permits one multiline argument per step. Compatibility parsing can retain both argument kinds, but structure validation rejects their simultaneous use.

Grammar acceptance and Gherkish policy must remain distinct. Require an outline to have an Examples block as the documented Gherkish structure policy. Do not invent requirements for nonempty names, a minimum number of steps, nonempty Examples data rows, unique scenario titles, unique example headers, or all three phases as syntax rules. Associative extraction and existing Examples datasets can reject unsupported header shapes independently. Unused Examples columns are allowed; unresolved outline placeholders used by extraction receive helper errors.

Use canonical English spelling in structure mode. Preserve current case-insensitive keyword handling and legacy singular Examples spelling in compatibility mode. Non-English `# language:` directives produce a specific unsupported-language diagnostic under `--structure`; translation is deferred.

Support the existing `@tests:` extension explicitly: extract only feature-level mapping sections outside argument payloads, validate their path-list shape, then mask their characters while preserving every newline and column for grammar validation. This extension must never be mistaken for a standard tag or valid scenario-level metadata. Existing root-relative path resolution remains unchanged. `gherkish:sync` must preserve tables, Doc Strings, tags, and Rule boundaries.

Add `structureErrors` to results and snapshots with `path`, one-based `line`, `column`, stable `code`, and `message`. Report file failures separately from scenario counts, include them in `hasErrors()`, and return exit code 1. Compact and long output both show actionable diagnostics; long output includes Rule names for Background groups. Keep old snapshot fields; add optional Rule identity and new diagnostic fields.

Example proposed output:

```text
INVALID tests/Users.feature:12:5 [unclosed-doc-string]
Doc String opened here has no closing delimiter.
```

## Implementation plan

### 1. Establish one parser boundary

Introduce a shared parsed document under `src/Gherkin/`, with Feature, Rule, Background, Scenario, Examples, Step, Table, DocString, Tag, and location data. Preserve original keywords and canonical node kinds. Replace the separate feature/outline scans with adapters over this document, retaining existing public DTO constructors where possible through optional fields.

First evaluate the upstream PHP parser behind this boundary against the declared PHP 8.2 and Laravel/Pest support matrix. If compatible, prefer it to a second grammar implementation; pin its supported version range and record the fixture revision. If adoption forces a platform increase or cannot reproduce existing compatibility mode, keep a stateful compatibility reader behind the same boundary and use the grammar parser for `--structure`. Do not silently expand the supported platform requirement. Implementation decision: use `cucumber/gherkin` `^40.0` (PHP >=8.2), with compatibility reading retained. Grammar baseline: v40.0.0, revision `c3b8380d208970273d059d7758cb1c0decd916e5`.

Use one shared table decoder for Examples and step tables. Add newline decoding with parity tests for existing escaped pipes/backslashes and combined escapes. No parser or mapping scanner may interpret payload lines as syntax or metadata.

### 2. Complete English node handling

Add aliases, asterisk steps, Rule ownership, tags, and descriptions. Map feature backgrounds to all scenarios and Rule backgrounds only to their descendants. Require applicable Pest setup to cover feature steps followed by Rule steps, while retaining `describe()` scope enforcement. Report each scoped Background once, with Rule context.

Use `/** @* item text */` for explicit wildcard annotations. A wildcard maps to a wildcard without guessing a Given/When/Then phase; in `--phased`, it inherits an established phase and cannot establish one. Preserve the existing policy that Background steps do not satisfy scenario phase coverage.

### 3. Implement argument resolution and helpers

Extract reusable PHP source-location/title/annotation resolution from the existing Examples and parity machinery. Add a `StepArgumentResolver`, `StepArgumentException`, and the two public methods. Implement associative/raw tables, full strings, title extraction, explicit selectors, Background resolution, and explicit outline replacement. Keep payload reading separate from static parity; the checker must not execute PHP to obtain arguments.

### 4. Wire structure checks into selection and reporting

Extend `CheckFeaturesCommand`, environment capture/restoration, result aggregation, snapshots, and `FeatureParityChecker::run()`. Validate before test-path resolution and before empty-scenario early returns. Do not swallow parser errors in structure mode. Compatibility mode must retain old accepted inputs while safely handling the newly supported constructs.

### 5. Document and verify

Update README APIs, supported grammar, title convention, selector failures, raw tables, outline values, flag/environment defaults, and compatibility notes. Add matching `.feature` scenarios and Pest tests following the repository's own parity conventions. Publish no artifacts as part of implementation.

## Acceptance and verification

| Area | Required evidence |
| --- | --- |
| Regression | Existing Examples, mapping/sync, Background, CLI, and parity suites remain green |
| Tables | User-shaped associative result; raw/headerless/single-column data; empty cells; header-only table; duplicate/empty headers; uneven widths; all escapes and combined escapes |
| Doc Strings | Full content and exact requested title-body result; both fences; content type; indentation; empty content; preserved blank lines; escaped fences; wrong title |
| Payload isolation | Content resembling Given, Scenario, Examples, Rule, tags, or `@tests:` never creates nodes or mappings |
| Lookup | Multiple scenarios/features; repeated step text; explicit selectors; nested callbacks; wrappers; shared setup; missing/wrong argument; actionable ambiguity |
| Outlines | Multiple labeled datasets remain compatible; table/string substitution; missing keys; strings such as `0`; ordinary literal angle brackets |
| English grammar | Each alias; wildcard annotations; Rule backgrounds isolated from siblings; feature plus Rule setup; tags/descriptions/comments retained |
| Validation | Invalid files fail without recognized scenarios; duplicate/misplaced sections; orphan payloads; bad fences/rows; policy versus grammar distinctions |
| Integration | Flag/env independently enabled; environment restored between invocations; path/staged selection; malformed and valid files in one run; old ignore directives do not bypass syntax |
| Reporting and sync | File-level errors/counts and snapshot additions; original locations after masking; sync idempotence and payload preservation |

After implementation run `composer test`, `vendor/bin/pint --test`, `just check --structure --outlined --phased --unmapped`, and `git diff --check`. Run supported dependency-matrix jobs before release. Dependency-matrix validation remains a release gate; local validation uses the installed PHP/dependency set.

## Compatibility and release note proposal

New extraction helpers, English aliases, Rule handling, and opt-in structure validation are intended as a backward-compatible feature release. Existing `examples()` label rules, pairing, annotations, flags, and environment names remain available. Structure checks can reject previously tolerated malformed files only when enabled. Correct newline decoding and prevention of payload misparsing are observable fixes and must be called out in release notes. A new dependency must preserve the declared platform matrix; snapshot changes are additive.

Release note: Adds step Data Table and Doc String extraction, including title-based body lookup, broader English Gherkin support, and optional `.feature` structure validation. Existing checks remain compatible; strict structure validation is opt-in. Structure validation remains opt-in.
