<?php

use Gherkish\Arguments\StepArgumentException;
use Gherkish\Arguments\StepArgumentResolver;
use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\FeatureParity\FeatureParityChecker;
use Gherkish\Gherkin\FeatureParser;
use Gherkish\Gherkin\Table;
use Gherkish\Mapping\FeatureMap;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->gherkinRoot = base_path('tests/.gherkin-support-fixtures');
    (new Filesystem)->ensureDirectoryExists($this->gherkinRoot);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->gherkinRoot);
    foreach (['GHERKISH_DIR', 'GHERKISH_FILE', 'GHERKISH_CHECK_STRUCTURE', 'GHERKISH_CHECK_UNMAPPED_TESTS', 'GHERKISH_STRICT'] as $key) {
        putenv($key);
        unset($_ENV[$key]);
    }
    FeatureParityChecker::resetSelection();
});

function gherkinSupportFile(string $name, string $source): string
{
    $path = test()->gherkinRoot.'/'.$name;
    file_put_contents($path, $source);

    return $path;
}

function gherkinSupportTest(string $name, string $steps): string
{
    return "<?php\n".'it'."('".$name."', function () {\n".$steps."\n});\n";
}

it('Validate structure fixtures', function () {
    /** @Given valid and malformed English feature files */
    $fixtures = [
        'missing feature' => ["Scenario: orphan\n  Given data", false],
        'duplicate feature' => ["Feature: one\nFeature: two", false],
        'orphan table' => ["Feature: one\n  | a |", false],
        'orphan string' => ["Feature: one\n  \"\"\"\n  text\n  \"\"\"", false],
        'late background' => ["Feature: one\nScenario: first\n Given data\nBackground:\n Given later", false],
        'duplicate background' => ["Feature: one\nBackground:\n Given data\nBackground:\n Given more", false],
        'missing examples' => ["Feature: one\nScenario Outline: first\n Given <data>", false],
        'wrong row width' => ["Feature: one\nScenario: first\n Given data\n | a | b |\n | c |", false],
        'unclosed string' => ["Feature: one\nScenario: first\n Given data\n \"\"\"\n content", false],
        'wrong case' => ["feature: one\nscenario: first\n given data", false],
        'unsupported language' => ["# language: fr\nFeature: one", false],
        'misplaced tags' => ["Feature: one\n@invalid\nBackground:\n Given data", false],
        'duplicate strings' => ["Feature: one\nScenario: first\n Given data\n \"\"\"\n a\n \"\"\"\n \"\"\"\n b\n \"\"\"", false],
        'empty names and zero steps' => ["Feature:\nScenario:", true],
        'one phase only' => ["Feature: one\nScenario: first\n Then a result", true],
        'no Examples data rows' => ["Feature: one\nScenario Outline: first\n Given <a>\nExamples:\n | a |", true],
        'duplicate table headers are syntax' => ["Feature: one\nScenario: first\n Given data\n | a | a |\n | b | c |", true],
        'comments and descriptions' => ["@feature\nFeature: one\n description\n # comment\n @scenario\n Example: first\n  description\n  * data", true],
        'mapping extension' => ["Feature: one\n @tests:\n - tests/MissingTest.php\n Scenario: first\n  Given data", true],
        // Cucumber PHP v40's grammar permits one multiline argument per step.
        'table and string on one step' => ["Feature: one\nScenario: first\n Given data\n | a |\n \"\"\"\n content\n \"\"\"", false],
        'misplaced mapping' => ["Feature: one\n Scenario: first\n @tests:\n - tests/MissingTest.php", false],
        'empty mapping' => ["Feature: one\n @tests:\n Scenario: first\n Given data", false],
    ];

    /** @When structure validation parses each file */
    $results = [];
    foreach ($fixtures as $name => [$source, $valid]) {
        $results[$name] = (new FeatureParser)->parse(gherkinSupportFile('Grammar.feature', $source), validate: true);
    }

    /** @Then syntax and optional phase policy remain separate */
    foreach ($fixtures as $name => [$source, $valid]) {
        expect($results[$name]->errors === [])->toBe($valid, $name);
    }
});

it('Decode table values without changing their types', function () {
    /** @Given a table with escaped and empty cells */
    $source = <<<'GHERKIN'
Feature: Tables
  Scenario Outline: Escapes
    Given data
    Examples:
      | text | pipe | slash | empty | zero | combined |
      | \nleft\nright\n | a\|b | c\\d | | 0 | \\n\|\\ |
GHERKIN;
    $path = gherkinSupportFile('Escapes.feature', $source);

    /** @When an Examples dataset decodes the table */
    $rows = (new ExampleDatasetResolver)->outlines($path)[0]->examples[0]->rows;

    /** @Then newlines pipes backslashes empty cells and zero are preserved */
    expect($rows)->toBe([['text' => "\nleft\nright\n", 'pipe' => 'a|b', 'slash' => 'c\\d', 'empty' => '', 'zero' => '0', 'combined' => '\\n|\\']]);
});

it('Keep argument payloads isolated from mapping and syntax', function () {
    /** @Given Doc String content containing apparent syntax and mapping metadata */
    $path = gherkinSupportFile('Payload.feature', <<<'GHERKIN'
@featureTag
Feature: Payload
  description
  # a comment
  @scenarioTag
  Scenario: content
    Given text
      """markdown
      @tests:
      - missing.php
      Scenario: fake
      Given fake
      Examples: fake
      Rule: fake
      """
GHERKIN);

    /** @When all consumers parse the feature */
    $document = (new FeatureParser)->parse($path, validate: true);
    $map = FeatureMap::forTest($path);

    /** @Then only the real scenario and metadata are retained */
    expect($document->errors)->toBe([])
        ->and($document->scenarios)->toHaveCount(1)
        ->and($document->scenarios[0]->steps)->toHaveCount(1)
        ->and($document->feature->tags[0]['name'])->toBe('@featureTag')
        ->and($document->scenarios[0]->tags[0]['name'])->toBe('@scenarioTag')
        ->and($document->feature->description)->toContain('description')
        ->and($document->comments)->toHaveCount(1)
        ->and($map->declaredTests($path))->toBe([]);
});

it('Reject ambiguous and missing step arguments', function () {
    /** @Given repeated step text and malformed argument requests */
    $feature = gherkinSupportFile('Lookup.feature', <<<'GHERKIN'
Feature: Lookup
  Scenario: lookup
    Given repeated
      | a |
      | b |
    And repeated
      | a |
      | c |
    Then no argument
GHERKIN);
    $test = gherkinSupportFile('LookupTest.php', gherkinSupportTest('lookup', "    /** @Given repeated */\n    \$data = null;"));
    $resolver = new StepArgumentResolver;

    /** @When requests use an ambiguous selector or the wrong argument type */
    $requests = [
        fn () => $resolver->resolve($test, 4, 'table', 'repeated', null),
        fn () => $resolver->resolve($test, 4, 'docString', null, null),
        fn () => $resolver->resolve($test, 4, 'table', 'no argument', null),
        fn () => $resolver->resolve($test, 4, 'table', 'missing', null),
    ];

    /** @Then every failure is explicit and source located */
    foreach ($requests as $request) {
        expect($request)->toThrow(StepArgumentException::class, $test.':4');
    }
    expect($resolver->resolve($test, 4, 'table', null, null))->toBe([['a'], ['b']]);
});

it('Preserve empty and formatted argument content', function () {
    /** @Given raw tables and titled Doc String bodies */
    $resolver = new StepArgumentResolver;
    $content = "\nTitle\n---\n\n  body\n\n";

    /** @When conversions request raw values full strings and title bodies */
    $raw = $resolver->table([['a', 'a'], ['0', '']], true);
    $body = $resolver->body($content, 'Title');

    /** @Then formatting survives and unsupported headers or titles fail explicitly */
    expect($raw)->toBe([['a', 'a'], ['0', '']])
        ->and($body)->toBe("  body\n\n")
        ->and($resolver->body('', null))->toBe('')
        ->and($resolver->body($content, null))->toBe($content)
        ->and($resolver->table([['header']], false))->toBe([]);
    expect(fn () => $resolver->table([['a', 'a']], false))->toThrow(StepArgumentException::class, 'unique');
    expect(fn () => $resolver->table([['', 'b']], false))->toThrow(StepArgumentException::class, 'non-empty');
    expect(fn () => $resolver->body($content, 'Wrong'))->toThrow(StepArgumentException::class, 'Wrong');
});

it('Validate malformed files before scenario mapping', function () {
    /** @Given a feature without recognized scenarios and a valid paired file */
    $invalid = gherkinSupportFile('Invalid.feature', "Feature: broken\nFeature: duplicate\n");
    $valid = gherkinSupportFile('Valid.feature', "Feature: valid\nScenario: valid\n Then a result\n");
    gherkinSupportFile('ValidTest.php', gherkinSupportTest('valid', " /** @Then a result */\n expect(true)->toBeTrue();"));
    $snapshotPath = $this->gherkinRoot.'/snapshot.json';

    /** @When the structure command validates and snapshots the directory */
    $this->artisan('gherkish:check', ['--dir' => $this->gherkinRoot, '--structure' => true, '--snapshot' => $snapshotPath, '--unmapped' => true])
        ->expectsOutputToContain('[duplicate-feature]')
        ->assertExitCode(1);

    /** @Then valid scenarios are covered and malformed files have file errors only */
    $snapshot = json_decode(file_get_contents($snapshotPath), true);
    $invalidSnapshot = array_values(array_filter($snapshot, fn (array $entry): bool => $entry['structureErrors'] !== []));
    expect($invalidSnapshot)->toHaveCount(1)
        ->and($invalidSnapshot[0]['structureErrors'][0]['path'])->toBe($invalid)
        ->and(getenv('GHERKISH_CHECK_STRUCTURE'))->toBeFalse();
    $this->artisan('gherkish:check', ['--feature' => $valid, '--structure' => true])->assertExitCode(0);
    $this->artisan('gherkish:check', ['--feature' => $valid, '--phased' => true])->assertExitCode(1);
});

it('Isolate Rule backgrounds and wildcard mappings', function () {
    /** @Given feature and Rule backgrounds with scoped Pest implementations */
    $path = gherkinSupportFile('Rules.feature', <<<'GHERKIN'
Feature: Rules
  Background:
    Given global setup
  Rule: first
    Background:
      Given first setup
    Example: first
      * listed
      Then done
  Rule: second
    Background:
      Given second setup
    Scenario: second
      Then done
GHERKIN);
    $php = "<?php\nbeforeEach(function () {\n/** @Given global setup */\n\$a = true;\n});\n";
    foreach (['first', 'second'] as $name) {
        $php .= "describe('".$name."', function () {\nbeforeEach(function () {\n/** @Given ".$name." setup */\n\$a = true;\n});\n";
        $steps = $name === 'first' ? "/** @* listed */\n\$b = true;\n" : '';
        $php .= substr(gherkinSupportTest($name, $steps."/** @Then done */\n\$c = true;"), 6)."\n});\n";
    }
    $test = gherkinSupportFile('RulesTest.php', $php);

    /** @When parity checks and snapshots the Rule groups */
    putenv('GHERKISH_DIR='.$this->gherkinRoot);
    putenv('GHERKISH_CHECK_STRUCTURE=1');
    $result = FeatureParityChecker::run();
    $snapshot = array_values(FeatureParityChecker::snapshot())[0];

    /** @Then each Rule requires only its own Background and the shared feature setup */
    expect($result->hasErrors())->toBeFalse()
        ->and($result->backgrounds)->toHaveCount(3)
        ->and($snapshot['ruleBackgrounds'])->toHaveCount(2);
    file_put_contents($test, str_replace('/** @Given second setup */', '/** @Given first setup */', $php));
    expect(FeatureParityChecker::run()->errors)->toHaveCount(1);
});

it('Synchronize mappings without touching payloads or tags', function () {
    /** @Given a remote tagged Rule feature containing apparent mapping text */
    $feature = gherkinSupportFile('Remote.feature', <<<'GHERKIN'
Feature: remote
  @ruleTag
  Rule: content
    Scenario Outline: remote
      Given text
        """
        @tests:
        - missing.php
        """
      Examples:
        | value |
        | 0 |
GHERKIN);
    $map = FeatureMap::forTest($feature);
    $test = gherkinSupportFile('OtherTest.php', "<?php\n// @feature ".$map->relative($feature)."\n");

    /** @When synchronization runs twice */
    $map->synchronize();
    $again = $map->synchronize();

    /** @Then metadata is inserted before Rule tags and payload text remains unchanged */
    $source = file_get_contents($feature);
    expect($again)->toBe([])
        ->and($source)->toContain("        @tests:\n        - missing.php")
        ->and(strpos($source, $map->relative($test)))->toBeLessThan(strpos($source, '@ruleTag'))
        ->and((new FeatureParser)->parse($feature, validate: true)->errors)->toBe([]);
});

it('Resolve nested callbacks without borrowing unrelated annotations', function () {
    /** @Given a callback containing strings and completed nested closures */
    gherkinSupportFile('Nested.feature', "Feature: Nested\nScenario: nested\n Given content\n \"\"\"\n right\n \"\"\"\n");
    $steps = <<<'PHP'
    $decoy = 'not a step annotation';
    /** @Given content */
    $value = array_map(function ($item) {
        return $item;
    }, []);
    $unrelated = function () {
        /** @Given wrong */
        return false;
    };
    $value = null;
PHP;
    $test = gherkinSupportFile('NestedTest.php', gherkinSupportTest('nested', $steps));

    /** @When argument lookup uses the final callback line */
    $content = (new StepArgumentResolver)->resolve($test, 12, 'docString', null, null);

    /** @Then only the enclosing step annotation determines the argument */
    expect($content)->toBe('right');
});

it('Require explicit outline values and preserve literal text', function () {
    /** @Given an outline and ordinary scenario with angle brackets */
    gherkinSupportFile('Values.feature', <<<'GHERKIN'
Feature: Values
  Scenario Outline: outline
    Given content
      """
      <value>
      """
    Examples:
      | value |
      | 0 |
  Example: ordinary
    Given content
      """
      <literal>
      """
GHERKIN);
    $test = gherkinSupportFile('ValuesTest.php', gherkinSupportTest('outline', "/** @Given content */\n\$value = null;"));
    $resolver = new StepArgumentResolver;

    /** @When values are supplied explicitly or omitted */
    $value = $resolver->resolve($test, 4, 'docString', null, null, ['value' => '0']);
    $literal = $resolver->resolve($test, 4, 'docString', 'content', 'ordinary');

    /** @Then zero remains text and unresolved placeholders report the missing key */
    expect($value)->toBe('0')->and($literal)->toBe('<literal>');
    expect(fn () => $resolver->resolve($test, 4, 'docString', null, null))->toThrow(StepArgumentException::class, '<value>');
});

it('Keep structure selection and environment settings independent', function () {
    /** @Given a staged malformed feature and an unselected valid feature */
    $invalid = gherkinSupportFile('Staged.feature', "Feature: one\nFeature: two\n");
    gherkinSupportFile('Untouched.feature', "Feature: untouched\n");
    FeatureParityChecker::selectStagedPaths([$invalid]);

    /** @When structure validation is enabled through its environment variable */
    putenv('GHERKISH_CHECK_STRUCTURE=1');
    $result = FeatureParityChecker::run();

    /** @Then only selected files fail and command overrides restore the environment */
    expect($result->structureErrors)->toHaveCount(1)->and($result->structureErrors[0]['path'])->toBe($invalid);
    FeatureParityChecker::resetSelection();
    $this->artisan('gherkish:check', ['--feature' => $invalid])->assertExitCode(1);
    expect(getenv('GHERKISH_CHECK_STRUCTURE'))->toBe('1');
});

it('Decode Doc String fences and normalize line endings', function () {
    /** @Given a typed Doc String with escaped fences and extra indentation */
    $source = <<<'GHERKIN'
Feature: Strings
  Scenario: strings
    Given content
      """text/plain
      \"\"\"
        nested
      """
    And other content
      ```text/plain
      \`\`\`
      ```
    Then empty content
      """
      """
GHERKIN;
    $path = gherkinSupportFile('Strings.feature', str_replace("\n", "\r\n", $source));

    /** @When the parser reads both supported delimiters */
    $document = (new FeatureParser)->parse($path, validate: true);
    $steps = $document->scenarios[0]->steps;

    /** @Then fences content types indentation and empty strings are represented faithfully */
    expect($document->errors)->toBe([])
        ->and($steps[0]->docString->content)->toBe("\"\"\"\n  nested")
        ->and($steps[0]->docString->contentType)->toBe('text/plain')
        ->and($steps[1]->docString->content)->toBe('```')
        ->and($steps[2]->docString->content)->toBe('');
});

it('Require explicit selectors for nonshared Background arguments', function () {
    /** @Given setup inherited by scenarios with different Background owners */
    gherkinSupportFile('Setup.feature', <<<'GHERKIN'
Feature: Setup
  Rule: first
    Background:
      Given content
        """
        first
        """
    Scenario: first
      Then done
  Rule: second
    Scenario: second
      Then done
GHERKIN);
    $source = "<?php\nbeforeEach(function () {\n/** @Given content */\n\$value = null;\n});\n";
    $source .= substr(gherkinSupportTest('first', ''), 6).substr(gherkinSupportTest('second', ''), 6);
    $test = gherkinSupportFile('SetupTest.php', $source);
    $resolver = new StepArgumentResolver;

    /** @When an inferred lookup crosses the Rule boundary */
    $request = fn () => $resolver->resolve($test, 4, 'docString', null, null);

    /** @Then the helper requires a scenario selector and accepts explicit disambiguation */
    expect($request)->toThrow(StepArgumentException::class, 'not shared')
        ->and($resolver->resolve($test, 4, 'docString', null, 'first'))->toBe('first');
});
