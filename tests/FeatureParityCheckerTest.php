<?php

use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;
use Gherkish\FeatureParity\FeatureParityChecker;
use Gherkish\FeatureParity\FeatureParityResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->fixtureRoot = base_path('tests/.feature-parity-fixtures');
    $this->filesystem->deleteDirectory($this->fixtureRoot);
    $this->filesystem->makeDirectory($this->fixtureRoot, 0777, true, true);
});

afterEach(function () {
    putenv('FEATURE_PARITY_DIR');
    unset($_ENV['FEATURE_PARITY_DIR']);
    putenv('FEATURE_PARITY_CHECK_OUTLINE_DATASETS');
    unset($_ENV['FEATURE_PARITY_CHECK_OUTLINE_DATASETS']);
    FeatureParityChecker::resetSelection();
    $this->filesystem->deleteDirectory($this->fixtureRoot);
});

describe('FeatureParityChecker parser', function () {
    it('should report coverage for both Pest helpers', function () {
        /** @Given a feature file containing scenarios for both Pest helpers */
        $fixture = writeFeatureParityFixture(
            'dual-helpers',
            <<<'FEATURE'
Feature: Parser dual helpers
  Scenario: should read tests defined with it helper
    Given the scenario expects simple steps
    When the scenario runs through the parser
    Then the parser notes each step

  Scenario: should read tests defined with test helper
    Given the scenario expects simple steps
    When the scenario runs through the parser
    Then the parser notes each step
FEATURE,
            <<<'PHP'
<?php

it('should read tests defined with it helper', function () {
    visit('/');
    /** @Given the scenario expects simple steps */
    /** @When the scenario runs through the parser */
    /** @Then the parser notes each step */
});

test('should read tests defined with test helper', function () {
    /** @Given the scenario expects simple steps */
    /** @When the scenario runs through the parser */
    /** @Then the parser notes each step */
});
PHP
        );

        /** @And matching Pest tests with step comments */
        expect(file_exists($fixture['testPath']))->toBeTrue();

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);

        /** @Then both scenarios are reported as fully covered */
        $featureSnapshot = reset($snapshot);
        expect($featureSnapshot['scenarios'])->toHaveKeys([
            'should read tests defined with it helper',
            'should read tests defined with test helper',
        ]);

        foreach ($featureSnapshot['scenarios'] as $scenario) {
            expect($scenario['coverage']['missing'])->toBe([]);
        }
    });

    it('should ignore helper names inside other functions', function () {
        /** @Given a feature file that references visit inside a test body */
        $fixture = writeFeatureParityFixture(
            'visit-helper',
            <<<'FEATURE'
Feature: Parser visit helper
  Scenario: should ignore helper names inside other functions
    Given the scenario uses the visit helper inside the Pest test
    When the parser inspects the file for Pest declarations
    Then it should not mis-detect an extra test
FEATURE,
            <<<'PHP'
<?php

it('should ignore helper names inside other functions', function () {
    visit('/careers');
    /** @Given the scenario uses the visit helper inside the Pest test */
    /** @When the parser inspects the file for Pest declarations */
    /** @Then it should not mis-detect an extra test */
});
PHP
        );

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $featureSnapshot = reset($snapshot);

        /** @Then the parser should only register the real Pest test */
        expect(array_keys($featureSnapshot['tests']))->toBe([
            'should ignore helper names inside other functions',
        ]);

        /** @And the scenario should remain fully covered */
        expect($featureSnapshot['scenarios']['should ignore helper names inside other functions']['coverage']['missing'])->toBe([]);
    });

    it('should report missing steps when docblocks are incomplete', function () {
        /** @Given a feature file with a scenario that has undocumented steps */
        $fixture = writeFeatureParityFixture(
            'missing-steps',
            <<<'FEATURE'
Feature: Parser missing steps
  Scenario: should report missing steps when docblocks are incomplete
    Given only the first step is documented
    And the second step lacks documentation
    When the parser inspects the file for missing annotations
    Then the missing steps should be flagged
FEATURE,
            <<<'PHP'
<?php

it('should report missing steps when docblocks are incomplete', function () {
    /** @Given only the first step is documented */
    expect(true)->toBeTrue();
});
PHP
        );

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $featureSnapshot = reset($snapshot);
        $scenario = $featureSnapshot['scenarios']['should report missing steps when docblocks are incomplete'];

        /** @Then the missing steps should appear in the coverage report */
        expect($scenario['coverage']['missing'])->toBe([
            'And the second step lacks documentation',
            'When the parser inspects the file for missing annotations',
            'Then the missing steps should be flagged',
        ]);
    });

    it('should match placeholder titles with dataset values', function () {
        /** @Given a scenario title that contains placeholder tokens */
        $fixture = writeFeatureParityFixture(
            'placeholder-titles',
            <<<'FEATURE'
Feature: Parser placeholder titles
  Scenario: should match "<state>" placeholder titles
    Given placeholders appear in the scenario title
    When the parser compares the title with actual test names
    Then placeholder tokens are treated as flexible text
FEATURE,
            <<<'PHP'
<?php

test('should match "active" placeholder titles', function () {
    /** @Given placeholders appear in the scenario title */
    /** @When the parser compares the title with actual test names */
    /** @Then placeholder tokens are treated as flexible text */
});
PHP
        );

        /** @And a Pest test whose name replaces the placeholder with a dataset value */
        expect($fixture['testPath'])->not->toBeNull();

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $featureSnapshot = reset($snapshot);

        /** @Then the scenario should be considered covered */
        expect($featureSnapshot['scenarios']['should match "<state>" placeholder titles']['coverage']['missing'])->toBe([]);
    });

    it('should ignore background steps when computing coverage', function () {
        /** @Given a feature file with background steps and scenario steps */
        $fixture = writeFeatureParityFixture(
            'background-steps',
            <<<'FEATURE'
Feature: Parser background handling
  Background:
    Given a shared setup step for the feature

  Scenario: scenario-specific path
    When the scenario performs a unique action
    Then only scenario steps should be recorded
FEATURE,
            <<<'PHP'
<?php

it('scenario-specific path', function () {
    /** @When the scenario performs a unique action */
    /** @Then only scenario steps should be recorded */
});
PHP
        );

        /** @And matching Pest tests documenting only scenario steps */
        expect($fixture['testPath'])->not->toBeNull();

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $featureSnapshot = reset($snapshot);
        $scenario = $featureSnapshot['scenarios']['scenario-specific path'];

        /** @Then background steps should not appear in the coverage map */
        expect(array_keys($scenario['steps']))->not->toContain('Given a shared setup step for the feature');
        expect($scenario['coverage']['missing'])->toBe([]);
    });

    it('should match And/But steps with multiline docblocks', function () {
        /** @Given a feature file containing And and But steps */
        $fixture = writeFeatureParityFixture(
            'multiline-docblocks',
            <<<'FEATURE'
Feature: Parser multiline docblocks
  Scenario: multiline docblock handling
    Given the first step is documented plainly
    And the second step uses a multiline docblock that spans multiple lines
    But the third step still needs to match
FEATURE,
            <<<'PHP'
<?php

it('multiline docblock handling', function () {
    /**
     * @Given the first step is documented plainly
     */
    /**
     * @And the second step uses a multiline docblock
     *   that spans multiple lines
     */
    /**
     * @But the third step still needs to match
     */
});
PHP
        );

        /** @And the Pest test documents them using multiline docblocks */
        expect($fixture['testPath'])->not->toBeNull();

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $featureSnapshot = reset($snapshot);
        $scenario = $featureSnapshot['scenarios']['multiline docblock handling'];

        /** @Then all steps should be considered covered */
        expect($scenario['coverage']['missing'])->toBe([]);
    });

    it('should parse scenario outlines while ignoring example rows', function () {
        /** @Given a scenario outline that contains an examples table */
        $fixture = writeFeatureParityFixture(
            'scenario-outlines',
            <<<'FEATURE'
Feature: Parser scenario outlines
  Scenario Outline: outline coverage
    Given outline step for <state>
    When the parser inspects the outline
    Then the outline remains singular

    Examples:
      | state |
      | active |
FEATURE,
            <<<'PHP'
<?php

test('outline coverage', function () {
    /** @Given outline step for <state> */
    /** @When the parser inspects the outline */
    /** @Then the outline remains singular */
});
PHP
        );

        /** @And a Pest test that documents the outline steps using placeholders */
        expect($fixture['testPath'])->not->toBeNull();

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $featureSnapshot = reset($snapshot);
        $scenario = $featureSnapshot['scenarios']['outline coverage'];

        /** @Then the outline steps should be covered exactly once */
        expect(array_keys($scenario['steps']))->toHaveCount(3);
        expect($scenario['coverage']['missing'])->toBe([]);
    });

    it('should reject step docblocks without executable code directly below them', function () {
        /** @Given a Pest test contains step docblocks followed by another step, a comment, or a blank line */
        $fixture = writeFeatureParityFixture(
            'unimplemented-step-docblocks',
            <<<'FEATURE'
Feature: Step implementations
  Scenario: invalid step implementations
    Given a step followed by another step docblock
    When a step is followed by executable code
    And a step followed by a regular comment
    Then a step followed by a blank line
FEATURE,
            <<<'PHP'
<?php

test('invalid step implementations', function () {
    /** @Given a step followed by another step docblock */
    /** @When a step is followed by executable code */
    expect(true)->toBeTrue();
    /** @And a step followed by a regular comment */
    // This comment cannot implement the step.
    expect(true)->toBeTrue();
    /** @Then a step followed by a blank line */

    expect(true)->toBeTrue();
});
PHP
        );

        /** @When the checker runs for that Pest test */
        $result = runFeatureParityFixture($fixture['dir']);

        /** @Then every invalid docblock should report its exact feature step and Pest locations */
        expect($result->errors)->toHaveCount(1);
        expect($result->errors[0]['message'])
            ->toContain('has Pest step docblocks without executable PHP code directly below them')
            ->toContain('Given a step followed by another step docblock')
            ->toContain("Feature step: tests/.feature-parity-fixtures/unimplemented-step-docblocks/Fixture.feature:3\n  Pest docblock: tests/.feature-parity-fixtures/unimplemented-step-docblocks/FixtureTest.php:4")
            ->toContain('And a step followed by a regular comment')
            ->toContain("Feature step: tests/.feature-parity-fixtures/unimplemented-step-docblocks/Fixture.feature:5\n  Pest docblock: tests/.feature-parity-fixtures/unimplemented-step-docblocks/FixtureTest.php:7")
            ->toContain('Then a step followed by a blank line')
            ->toContain("Feature step: tests/.feature-parity-fixtures/unimplemented-step-docblocks/Fixture.feature:6\n  Pest docblock: tests/.feature-parity-fixtures/unimplemented-step-docblocks/FixtureTest.php:10")
            ->not->toContain('Fixture.feature:2')
            ->not->toContain('- When a step is followed by executable code');
    });

    it('should flag missing paired test files', function () {
        /** @Given a feature file without a corresponding Pest test file */
        $fixture = writeFeatureParityFixture(
            'missing-test-file',
            <<<'FEATURE'
Feature: Parser missing test file
  Scenario: orphan scenario
    Given there is no matching Pest test implementation
    When the checker evaluates coverage
    Then it should report missing steps
FEATURE
        );

        /** @When the checker runs */
        $result = runFeatureParityFixture($fixture['dir']);

        /** @Then the result should report a missing test file error */
        expect($result->errors)->toHaveCount(1);
        expect($result->errors[0]['message'])->toContain('paired test file');
    });
});

describe('FeatureParityChecker discovery', function () {
    it('should discover feature files in both tests and app by default', function () {
        /** @Given feature files exist under both default discovery roots */
        $testsFeature = base_path('tests/GherkishDiscovery.feature');
        $appFeature = base_path('app/GherkishDiscovery.feature');
        $this->filesystem->put($testsFeature, "Feature: Tests discovery\n");
        $this->filesystem->put($appFeature, "Feature: App discovery\n");

        /** @When the checker resolves its default feature selection */
        $method = new ReflectionMethod(FeatureParityChecker::class, 'selectedFeaturePaths');
        $paths = $method->invoke(null);

        /** @Then features from tests and app are included */
        expect($paths)
            ->toContain(realpath($testsFeature))
            ->toContain(realpath($appFeature));

        $this->filesystem->delete([$testsFeature, $appFeature]);
    });
});

describe('gherkish:check command', function () {
    it('should register the package command and report successful parity', function () {
        /** @Given a feature and test with matching scenarios and steps */
        $fixture = writeFeatureParityFixture(
            'command-success',
            <<<'FEATURE'
Feature: Command integration
  Scenario: should run through Artisan
    Given a mapped command scenario
    When the package command runs
    Then it exits successfully
FEATURE,
            <<<'PHP'
<?php

it('should run through Artisan', function () {
    /** @Given a mapped command scenario */
    expect(true)->toBeTrue();
    /** @When the package command runs */
    expect(true)->toBeTrue();
    /** @Then it exits successfully */
    expect(true)->toBeTrue();
});
PHP
        );

        /** @When the feature parity command checks their directory */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ]);
        $ansiOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $ansiExitCode = Artisan::call('gherkish:check', ['--dir' => $fixture['dir']], $ansiOutput);

        /** @Then the command reports the scenario in a colored Pest-style heading and its steps as checks */
        $command
            ->expectsOutputToContain('COVERED  Tests\.feature-parity-fixtures\command-success\FixtureTest → Command integration → should run through Artisan')
            ->expectsOutputToContain('✓ Given a mapped command scenario')
            ->expectsOutputToContain('✓ When the package command runs')
            ->expectsOutputToContain('✓ Then it exits successfully')
            ->expectsOutputToContain('Scenarios:  1 covered')
            ->assertSuccessful();
        expect($ansiExitCode)->toBe(0);
        expect($ansiOutput->fetch())
            ->toContain("\e[30;42;1m COVERED \e[39;49;22m")
            ->toContain("\e[32m✓\e[39m Given a mapped command scenario");
    });

    it('should render failed command checks in a Pest-style test file group', function () {
        /** @Given a feature and test with an unimplemented step */
        $fixture = writeFeatureParityFixture(
            'command-failure',
            <<<'FEATURE'
Feature: Command integration
  Scenario: should reject an empty step
    Given a mapped command scenario
    When the package command runs
    Then it exits successfully

  Scenario: should accept implemented steps
    Given another mapped command scenario
    Then its implementation is accepted
FEATURE,
            <<<'PHP'
<?php

test('should reject an empty step', function () {
    /** @Given a mapped command scenario */

    /** @When the package command runs */
    expect(true)->toBeTrue();
    /** @Then it exits successfully */
    expect(true)->toBeTrue();
});

test('should accept implemented steps', function () {
    /** @Given another mapped command scenario */
    expect(true)->toBeTrue();
    /** @Then its implementation is accepted */
    expect(true)->toBeTrue();
});
PHP
        );

        /** @When the feature parity command checks the failing directory */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ]);

        /** @Then the command reports the failed scenario as checks and collects its details at the end */
        $command
            ->expectsOutputToContain('FAIL  Tests\.feature-parity-fixtures\command-failure\FixtureTest → Command integration → should reject an empty step')
            ->expectsOutputToContain('⨯ Given a mapped command scenario')
            ->expectsOutputToContain('✓ When the package command runs')
            ->expectsOutputToContain('✓ Then it exits successfully')
            ->expectsOutputToContain('COVERED  Tests\.feature-parity-fixtures\command-failure\FixtureTest → Command integration → should accept implemented steps')
            ->expectsOutputToContain('✓ Given another mapped command scenario')
            ->expectsOutputToContain('✓ Then its implementation is accepted')
            ->expectsOutputToContain('FAILED  Tests\.feature-parity-fixtures\command-failure\FixtureTest → Command integration → should reject an empty step')
            ->expectsOutputToContain('has Pest step docblocks without executable PHP code directly below them')
            ->expectsOutputToContain('Scenarios:  1 failed, 1 covered')
            ->assertFailed();
    });

    it('should leave scenario outline dataset validation disabled by default', function () {
        /** @Given a scenario outline whose Pest test has no dataset and no validation flag */
        $fixture = writeFeatureParityFixture(
            'outline-validation-disabled',
            <<<'FEATURE'
Feature: State datasets
  Scenario Outline: unchecked states are handled
    Given the state is "<state>"
    Then it is accepted

    Examples:
      | state  |
      | active |
FEATURE,
            <<<'PHP'
<?php

test('unchecked states are handled', function () {
    /** @Given the state is "<state>" */
    expect(true)->toBeTrue();
    /** @Then it is accepted */
    expect(true)->toBeTrue();
});
PHP
        );

        /** @When the checker runs without outline dataset validation */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ]);

        /** @Then the scenario should be covered and its Examples table marked as not validated */
        $command
            ->expectsOutputToContain('COVERED')
            ->expectsOutputToContain('! Examples (validation disabled):')
            ->assertSuccessful();
    });

    it('should render scenario outline examples as a Gherkin table', function () {
        /** @Given a scenario outline with example rows and a matching Pest test */
        $fixture = writeFeatureParityFixture(
            'command-examples',
            <<<'FEATURE'
Feature: Login datasets
  Scenario Outline: User logs in
    Given a user with email "<email>"
    When they log in with password "<password>"
    Then the result should be "<result>"

    Examples:
      | email            | password | result  |
      | john@test.com    | correct  | success |
      | missing@test.com | anything | failure |
FEATURE,
            <<<'PHP'
<?php

test('User logs in', function () {
    /** @Given a user with email "<email>" */
    expect(true)->toBeTrue();
    /** @When they log in with password "<password>" */
    expect(true)->toBeTrue();
    /** @Then the result should be "<result>" */
    expect(true)->toBeTrue();
})->with([
    ['john@test.com', 'correct', 'success'],
    ['missing@test.com', 'anything', 'failure'],
]);
PHP
        );

        /** @When the feature parity command checks the outline directory */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--check-outline-datasets' => true,
            '--no-ansi' => true,
        ]);

        /** @Then the example headers and rows are reported as a Gherkin table */
        $command
            ->expectsOutputToContain('✓ Examples:')
            ->expectsOutputToContain('| email            | password | result  |')
            ->expectsOutputToContain('| john@test.com    | correct  | success |')
            ->expectsOutputToContain('| missing@test.com | anything | failure |')
            ->assertSuccessful();
    });

    it('should require a mapped Pest dataset for scenario outlines', function () {
        /** @Given a scenario outline whose matching Pest test has no dataset */
        $fixture = writeFeatureParityFixture(
            'outline-without-dataset',
            <<<'FEATURE'
Feature: State datasets
  Scenario Outline: state is handled
    Given the state is "<state>"
    Then it is accepted

    Examples:
      | state  |
      | active |
FEATURE,
            <<<'PHP'
<?php

test('state is handled', function () {
    /** @Given the state is "<state>" */
    expect(true)->toBeTrue();
    /** @Then it is accepted */
    expect(true)->toBeTrue();
});
PHP
        );

        /** @When the checker validates the outline dataset */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--check-outline-datasets' => true,
            '--no-ansi' => true,
        ]);

        /** @Then the Examples table should be reported as unmapped */
        $command
            ->expectsOutputToContain('⨯ Examples:')
            ->expectsOutputToContain('must map its Examples table to the Pest test dataset')
            ->expectsOutputToContain('Use ->with(Gherkish::examples())')
            ->assertFailed();
    });

    it('should accept explicit arrays matching scenario outline examples', function () {
        /** @Given a scenario outline whose Pest dataset is a literal array of its example values */
        $fixture = writeFeatureParityFixture(
            'outline-explicit-dataset',
            <<<'FEATURE'
Feature: State datasets
  Scenario Outline: explicit states are handled
    Given the state is "<state>"
    Then the result is "<result>"

    Examples:
      | state    | result   |
      | active   | accepted |
      | inactive | rejected |
FEATURE,
            <<<'PHP'
<?php

test('explicit states are handled', function (string $state, string $result) {
    /** @Given the state is "<state>" */
    expect($state)->not->toBeEmpty();
    /** @Then the result is "<result>" */
    expect($result)->not->toBeEmpty();
})->with([
    ['active', 'accepted'],
    ['inactive', 'rejected'],
]);
PHP
        );

        /** @When the checker validates the explicit outline dataset */
        $result = runFeatureParityFixture($fixture['dir'], checkOutlineDatasets: true);

        /** @Then the literal dataset should cover the Examples table */
        expect($result->errors)->toBe([]);
        expect($result->successes)->toHaveCount(1);
    });

    it('should reject explicit arrays that differ from scenario outline examples', function () {
        /** @Given a scenario outline whose literal Pest dataset contains different values */
        $fixture = writeFeatureParityFixture(
            'outline-mismatched-dataset',
            <<<'FEATURE'
Feature: State datasets
  Scenario Outline: mismatched states are rejected
    Given the state is "<state>"
    Then the result is "<result>"

    Examples:
      | state  | result   |
      | active | accepted |
FEATURE,
            <<<'PHP'
<?php

test('mismatched states are rejected', function (string $state, string $result) {
    /** @Given the state is "<state>" */
    expect($state)->not->toBeEmpty();
    /** @Then the result is "<result>" */
    expect($result)->not->toBeEmpty();
})->with([
    ['inactive', 'rejected'],
]);
PHP
        );

        /** @When the checker validates the mismatched outline dataset */
        $result = runFeatureParityFixture($fixture['dir'], checkOutlineDatasets: true);

        /** @Then the literal dataset should not cover the Examples table */
        expect($result->errors)->toHaveCount(1);
        expect($result->errors[0]['message'])
            ->toContain('must map its Examples table to the Pest test dataset')
            ->toContain('literal array containing the exact example values');
        expect($result->cases[0]['examplesStatus'])->toBe('failed');
    });

    it('should accept labeled Gherkish examples datasets', function () {
        /** @Given a scenario outline with labeled Examples blocks mapped by name */
        $fixture = writeFeatureParityFixture(
            'outline-labeled-dataset',
            <<<'FEATURE'
Feature: State datasets
  Scenario Outline: labeled states are handled
    Given the state is "<state>"
    Then the result is "<result>"

    Examples: Active states
      | state  | result   |
      | active | accepted |

    Examples: Inactive states
      | state    | result   |
      | inactive | rejected |
FEATURE,
            <<<'PHP'
<?php

use Gherkish\Gherkish;

test('labeled states are handled', function (string $state, string $result) {
    /** @Given the state is "<state>" */
    expect($state)->not->toBeEmpty();
    /** @Then the result is "<result>" */
    expect($result)->not->toBeEmpty();
})->with([
    ...Gherkish::examples('Active states'),
    ...Gherkish::examples('Inactive states'),
]);
PHP
        );

        /** @When the checker validates the labeled outline dataset */
        $result = runFeatureParityFixture($fixture['dir'], checkOutlineDatasets: true);

        /** @Then every labeled Examples block should be covered */
        expect($result->errors)->toBe([]);
        expect($result->successes)->toHaveCount(1);
    });

    it('should allow custom outline datasets to opt out of examples validation', function () {
        /** @Given a scenario outline uses a custom dataset with the ignore examples comment */
        $fixture = writeFeatureParityFixture(
            'outline-ignored-custom-dataset',
            <<<'FEATURE'
Feature: State datasets
  Scenario Outline: custom states are handled
    Given the state is "<state>"
    Then it is accepted

    Examples:
      | state  |
      | active |
FEATURE,
            <<<'PHP'
<?php

// @gherkish-ignore-examples
test('custom states are handled', function (string $state) {
    /** @Given the state is "<state>" */
    expect($state)->not->toBeEmpty();
    /** @Then it is accepted */
    expect(true)->toBeTrue();
})->with(customStateDataset());
PHP
        );

        /** @When the checker validates the ignored custom dataset */
        $result = runFeatureParityFixture($fixture['dir'], checkOutlineDatasets: true);
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--check-outline-datasets' => true,
            '--no-ansi' => true,
        ]);

        /** @Then the scenario and steps should be covered without validating its Examples table */
        expect($result->errors)->toBe([]);
        expect($result->successes)->toHaveCount(1);
        expect($result->cases[0]['examplesStatus'])->toBe('ignored');
        $command
            ->expectsOutputToContain('! Examples (validation ignored):')
            ->assertSuccessful();
    });

    it('should return a failure for an invalid selection', function () {
        /** @Given a feature directory that does not exist */
        $missingDirectory = 'missing-gherkish-directory';

        /** @When the feature parity command checks that directory */
        $command = $this->artisan('gherkish:check', ['--dir' => $missingDirectory]);

        /** @Then the command reports a configuration failure */
        $command
            ->expectsOutputToContain('Could not locate directory')
            ->assertFailed();
    });
});

describe('Gherkish examples datasets', function () {
    it('should return rows from a single examples block without a label', function () {
        /** @Given a scenario outline with one examples block */
        $fixture = writeFeatureParityFixture(
            'single-examples-dataset',
            <<<'FEATURE'
Feature: Login
  Scenario Outline: User logs in
    Given a user with email "<email>"
    When they log in with password "<password>"
    Then the result should be "<result>"

    Examples:
      | email            | password | result  |
      | john@test.com    | correct  | success |
      | missing@test.com | anything | failure |
FEATURE,
            <<<'PHP'
<?php

use Gherkish\Gherkish;

return Gherkish::examples();
PHP,
        );

        /** @When the examples dataset is requested without a label */
        $rows = require $fixture['testPath'];

        /** @Then every example row is returned as an associative array */
        expect($rows)->toBe([
            ['email' => 'john@test.com', 'password' => 'correct', 'result' => 'success'],
            ['email' => 'missing@test.com', 'password' => 'anything', 'result' => 'failure'],
        ]);
    });

    it('should select an examples block by label', function () {
        /** @Given a scenario outline with multiple labeled examples blocks */
        $fixture = writeLoginExamplesFixture('labeled-examples-dataset');

        /** @When an examples dataset is requested by label */
        $rows = (new ExampleDatasetResolver)->resolve($fixture['testPath'], 4, 'Invalid credentials');

        /** @Then only rows from the matching examples block are returned */
        expect($rows)->toBe([
            ['email' => 'john@test.com', 'password' => 'wrong', 'result' => 'failure'],
            ['email' => 'jane@test.com', 'password' => 'wrong', 'result' => 'failure'],
        ]);
    });

    it('should require a label for multiple examples blocks', function () {
        /** @Given a scenario outline with multiple examples blocks */
        $fixture = writeLoginExamplesFixture('required-examples-label');

        /** @When the examples dataset is requested without a label */
        $resolve = fn () => (new ExampleDatasetResolver)->resolve($fixture['testPath'], 4);

        /** @Then the available labels are reported in the error */
        expect($resolve)->toThrow(
            ExamplesException::class,
            'Pass one of these labels to Gherkish::examples(): "Valid credentials", "Invalid credentials"',
        );
    });

    it('should reject an unknown examples label', function () {
        /** @Given a scenario outline with labeled examples blocks */
        $fixture = writeLoginExamplesFixture('unknown-examples-label');

        /** @When an examples dataset is requested with an unknown label */
        $resolve = fn () => (new ExampleDatasetResolver)->resolve($fixture['testPath'], 4, 'Missing label');

        /** @Then the available labels are reported in the error */
        expect($resolve)->toThrow(
            ExamplesException::class,
            'Available labels: "Valid credentials", "Invalid credentials"',
        );
    });
});

function writeFeatureParityFixture(string $case, string $featureContent, ?string $testContent = null): array
{
    $filesystem = new Filesystem;
    $dir = base_path('tests/.feature-parity-fixtures/'.$case);
    $filesystem->deleteDirectory($dir);
    $filesystem->makeDirectory($dir, 0777, true, true);

    $featurePath = $dir.'/Fixture.feature';
    $testPath = $testContent !== null ? $dir.'/FixtureTest.php' : null;

    file_put_contents($featurePath, rtrim($featureContent).PHP_EOL);
    if ($testPath !== null) {
        file_put_contents($testPath, rtrim($testContent).PHP_EOL);
    }

    return [
        'dir' => $dir,
        'featurePath' => $featurePath,
        'testPath' => $testPath,
    ];
}

function snapshotFeatureParityFixture(string $dir): array
{
    putenv('FEATURE_PARITY_DIR='.$dir);
    $_ENV['FEATURE_PARITY_DIR'] = $dir;
    FeatureParityChecker::resetSelection();

    $snapshot = FeatureParityChecker::snapshot();

    putenv('FEATURE_PARITY_DIR');
    unset($_ENV['FEATURE_PARITY_DIR']);
    FeatureParityChecker::resetSelection();

    return $snapshot;
}

function runFeatureParityFixture(string $dir, bool $checkOutlineDatasets = false): FeatureParityResult
{
    putenv('FEATURE_PARITY_DIR='.$dir);
    $_ENV['FEATURE_PARITY_DIR'] = $dir;
    if ($checkOutlineDatasets) {
        putenv('FEATURE_PARITY_CHECK_OUTLINE_DATASETS=1');
        $_ENV['FEATURE_PARITY_CHECK_OUTLINE_DATASETS'] = '1';
    }
    FeatureParityChecker::resetSelection();

    $result = FeatureParityChecker::run();

    putenv('FEATURE_PARITY_DIR');
    unset($_ENV['FEATURE_PARITY_DIR']);
    putenv('FEATURE_PARITY_CHECK_OUTLINE_DATASETS');
    unset($_ENV['FEATURE_PARITY_CHECK_OUTLINE_DATASETS']);
    FeatureParityChecker::resetSelection();

    return $result;
}

function writeLoginExamplesFixture(string $case): array
{
    return writeFeatureParityFixture(
        $case,
        <<<'FEATURE'
Feature: Login
  Scenario Outline: User registers
    Given a new user with email "<email>"
    When they register
    Then registration succeeds

    Examples:
      | email         |
      | new@test.com  |

  Scenario Outline: User logs in
    Given a user with email "<email>"
    When they log in with password "<password>"
    Then the result should be "<result>"

    Examples: Valid credentials
      | email         | password | result  |
      | john@test.com | correct  | success |
      | jane@test.com | correct  | success |

    Examples: Invalid credentials
      | email         | password | result  |
      | john@test.com | wrong    | failure |
      | jane@test.com | wrong    | failure |
FEATURE,
        <<<'PHP'
<?php

it('User logs in', function (string $email, string $password, string $result) {
})->with(Gherkish::examples('Valid credentials'));
PHP,
    );
}
