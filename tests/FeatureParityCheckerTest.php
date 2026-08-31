<?php

use Gherkish\FeatureParity\FeatureParityChecker;
use Gherkish\FeatureParity\FeatureParityResult;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->fixtureRoot = base_path('tests/.feature-parity-fixtures');
    $this->filesystem->deleteDirectory($this->fixtureRoot);
    $this->filesystem->makeDirectory($this->fixtureRoot, 0777, true, true);
});

afterEach(function () {
    putenv('FEATURE_PARITY_DIR');
    unset($_ENV['FEATURE_PARITY_DIR']);
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
    /** @When the package command runs */
    /** @Then it exits successfully */
});
PHP
        );

        /** @When the feature parity command checks their directory */
        $command = $this->artisan('gherkish:check', ['--dir' => $fixture['dir']]);

        /** @Then the command reports success */
        $command
            ->expectsOutputToContain('All 1 documented scenarios are mapped to Pest tests.')
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

function runFeatureParityFixture(string $dir): FeatureParityResult
{
    putenv('FEATURE_PARITY_DIR='.$dir);
    $_ENV['FEATURE_PARITY_DIR'] = $dir;
    FeatureParityChecker::resetSelection();

    $result = FeatureParityChecker::run();

    putenv('FEATURE_PARITY_DIR');
    unset($_ENV['FEATURE_PARITY_DIR']);
    FeatureParityChecker::resetSelection();

    return $result;
}
