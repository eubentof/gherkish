<?php

use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;
use Gherkish\FeatureParity\FeatureParityChecker;
use Gherkish\FeatureParity\FeatureParityResult;
use Gherkish\FeatureParity\GitStagedFileResolver;
use Gherkish\FeatureParity\StagedFileResolver;
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
    putenv('GHERKISH_DIR');
    unset($_ENV['GHERKISH_DIR']);
    putenv('GHERKISH_CHECK_OUTLINE_DATASETS');
    unset($_ENV['GHERKISH_CHECK_OUTLINE_DATASETS']);
    putenv('GHERKISH_CHECK_UNMAPPED_TESTS');
    unset($_ENV['GHERKISH_CHECK_UNMAPPED_TESTS']);
    putenv('GHERKISH_STRICT');
    unset($_ENV['GHERKISH_STRICT']);
    FeatureParityChecker::resetSelection();
    $this->filesystem->deleteDirectory($this->fixtureRoot);
});

describe('FeatureParityChecker parser', function () {
    it('should report coverage for both Pest helpers', function () {
        /** @Given a feature file containing scenarios for both Pest helpers */
        $fixture = writeFeatureParityFixture('dual-helpers');

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
        $fixture = writeFeatureParityFixture('visit-helper');

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
        $fixture = writeFeatureParityFixture('missing-steps');

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
        $fixture = writeFeatureParityFixture('placeholder-titles');

        /** @And a Pest test whose name replaces the placeholder with a dataset value */
        expect($fixture['testPath'])->not->toBeNull();

        /** @When the checker snapshots that feature directory */
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $featureSnapshot = reset($snapshot);

        /** @Then the scenario should be considered covered */
        expect($featureSnapshot['scenarios']['should match "<state>" placeholder titles']['coverage']['missing'])->toBe([]);
    });

    it('should map Background steps to beforeEach', function () {
        /** @Given a feature file with Background steps */
        $fixture = writeFeatureParityFixture('background-steps');

        /** @And a matching Pest beforeEach with implemented step annotations */
        expect($fixture['testPath'])->not->toBeNull();

        /** @When the checker runs and snapshots that feature directory */
        $result = runFeatureParityFixture($fixture['dir']);
        $snapshot = snapshotFeatureParityFixture($fixture['dir']);
        $output = new BufferedOutput;
        Artisan::call('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--long' => true,
            '--no-ansi' => true,
        ], $output);
        $featureSnapshot = reset($snapshot);
        $scenario = $featureSnapshot['scenarios']['scenario-specific path'];

        /** @Then the Background and scenario should be fully covered without duplicating Background cases */
        expect($result->errors)->toBe([]);
        expect($result->cases[0]['steps'])->toHaveCount(2);
        expect($featureSnapshot['background']['coverage']['missing'])->toBe([]);
        expect($featureSnapshot['beforeEach'])->toHaveCount(1);
        expect(array_keys($scenario['steps']))->not->toContain('Given a shared setup step for the feature');
        expect($scenario['coverage']['missing'])->toBe([]);
        expect($output->fetch())
            ->toContain('BACKGROUND  Tests\\.feature-parity-fixtures\\background-steps\\FixtureTest → Parser background handling → Background')
            ->toContain('✓ Given a shared setup step for the feature')
            ->toContain('Scenarios:  1 covered (3 cases)');
    });

    it('should require beforeEach for a feature Background', function () {
        /** @Given a feature file with a Background and a matching scenario test without beforeEach */
        $fixture = writeFeatureParityFixture('background-missing-before-each');

        /** @When the checker runs */
        $result = runFeatureParityFixture($fixture['dir']);

        /** @Then the error should identify the Background and Pest test locations */
        expect($result->errors)->toHaveCount(1);
        expect($result->errors[0]['message'])
            ->toContain('Feature Background requires an applicable Pest beforeEach()')
            ->toContain('Background  tests/.feature-parity-fixtures/background-missing-before-each/Fixture.feature:2')
            ->toContain('Pest test   tests/.feature-parity-fixtures/background-missing-before-each/FixtureTest.php:3');
    });

    it('should keep Background parity independent from phased structure', function () {
        /** @Given a mapped Background supplies the setup phase */
        $fixture = writeFeatureParityFixture('background-steps');

        /** @When the checker validates the feature in phased mode */
        $result = runFeatureParityFixture($fixture['dir'], strict: true);

        /** @Then the Background Given should not satisfy the Scenario structure */
        expect($result->errors)->toHaveCount(1);
        expect($result->errors[0]['message'])->toContain('missing the following required phase: Given');
    });

    it('should reject missing mismatched and unimplemented Background annotations', function () {
        /** @Given Background steps with missing mismatched and unimplemented beforeEach annotations */
        $fixtures = [
            'missing' => writeFeatureParityFixture('background-missing-annotation'),
            'mismatched' => writeFeatureParityFixture('background-mismatched-annotation'),
            'unimplemented' => writeFeatureParityFixture('background-unimplemented-annotation'),
        ];

        /** @When the checker runs for each invalid mapping */
        $results = array_map(
            static fn (array $fixture): FeatureParityResult => runFeatureParityFixture($fixture['dir']),
            $fixtures,
        );

        /** @Then each error should identify the exact Feature and Pest locations */
        expect($results['missing']->errors[0]['message'])
            ->toContain('[Background] Given shared state exists')
            ->toContain('Feature  tests/.feature-parity-fixtures/background-missing-annotation/Fixture.feature:3')
            ->toContain('Pest     not documented in applicable beforeEach at tests/.feature-parity-fixtures/background-missing-annotation/FixtureTest.php:3');
        expect($results['mismatched']->errors[0]['message'])
            ->toContain('[Background] Given shared state exists')
            ->toContain('Feature  tests/.feature-parity-fixtures/background-mismatched-annotation/Fixture.feature:3')
            ->toContain('Pest     not documented in applicable beforeEach at tests/.feature-parity-fixtures/background-mismatched-annotation/FixtureTest.php:3')
            ->toContain('Available Given different shared state exists at tests/.feature-parity-fixtures/background-mismatched-annotation/FixtureTest.php:4');
        expect($results['unimplemented']->errors[0]['message'])
            ->toContain('Given shared state exists')
            ->toContain('Feature step: tests/.feature-parity-fixtures/background-unimplemented-annotation/Fixture.feature:3')
            ->toContain('Pest docblock: tests/.feature-parity-fixtures/background-unimplemented-annotation/FixtureTest.php:4');
    });

    it('should let beforeEach annotations satisfy scenario steps without a Background', function () {
        /** @Given a feature scenario whose Given annotation is implemented in beforeEach */
        $fixture = writeFeatureParityFixture('scenario-step-in-before-each');

        /** @When the checker runs without a feature Background */
        $result = runFeatureParityFixture($fixture['dir']);

        /** @Then the scenario should remain fully covered */
        expect($result->errors)->toBe([]);
        expect($result->successes)->toHaveCount(1);
    });

    it('should isolate beforeEach annotations by describe scope', function () {
        /** @Given sibling describe scopes with different beforeEach annotations */
        $fixture = writeFeatureParityFixture('before-each-scope');

        /** @When the checker maps their scenario tests */
        $result = runFeatureParityFixture($fixture['dir']);

        /** @Then setup annotations should apply only to tests in their own or descendant scope */
        expect($result->successes)->toHaveCount(2);
        expect($result->errors)->toHaveCount(1);
        expect($result->errors[0]['label'])->toContain('sibling scoped scenario');
        expect($result->errors[0]['message'])
            ->toContain('Given first scoped setup')
            ->toContain('Pest     not documented');
    });

    it('should match And/But steps with multiline docblocks', function () {
        /** @Given a feature file containing And and But steps */
        $fixture = writeFeatureParityFixture('multiline-docblocks');

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
        $fixture = writeFeatureParityFixture('scenario-outlines');

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
        $fixture = writeFeatureParityFixture('unimplemented-step-docblocks');

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
        $fixture = writeFeatureParityFixture('missing-test-file');

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

describe('staged feature selection', function () {
    it('should check only staged feature files', function () {
        /** @Given staged covered and unstaged failing feature files */
        $staged = writeFeatureParityFixture('command-success', 'staged-success');
        $unstaged = writeFeatureParityFixture('command-failure', 'unstaged-failure');
        $this->app->instance(StagedFileResolver::class, fakeStagedFileResolver([$staged['featurePath']]));

        /** @When the feature parity command checks staged files */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '--staged' => true,
            '--long' => true,
            '--no-ansi' => true,
        ], $output);

        /** @Then only the staged feature scenarios are checked */
        expect($exitCode)->toBe(0);
        expect($output->fetch())
            ->toContain('should run through Artisan')
            ->not->toContain('should reject an empty step');
        expect($unstaged['featurePath'])->toBeFile();
    });

    it('should resolve a staged conventionally paired Pest test', function () {
        /** @Given a staged Pest test beside its same-basename feature */
        $fixture = writeFeatureParityFixture('command-success', 'staged-paired-test');
        $this->app->instance(StagedFileResolver::class, fakeStagedFileResolver([$fixture['testPath']]));

        /** @When the feature parity command checks staged files */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '-s' => true,
            '-l' => true,
            '--no-ansi' => true,
        ], $output);

        /** @Then the conventionally paired feature is checked */
        expect($exitCode)->toBe(0);
        expect($output->fetch())->toContain('should run through Artisan');
    });

    it('should resolve a staged Pest test through an explicit feature mapping', function () {
        /** @Given a staged Pest test listed by a feature in its Tests section */
        $fixture = writeFeatureParityFixture('command-success', 'staged-explicit-mapping');
        $explicitTestPath = $fixture['dir'].'/ExplicitCommandTest.php';
        $this->filesystem->move($fixture['testPath'], $explicitTestPath);
        $relativeTestPath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $explicitTestPath);
        $feature = $this->filesystem->get($fixture['featurePath']);
        $feature = preg_replace(
            '/^(Feature:[^\r\n]+\R)/',
            "$1\n  @tests:\n  - {$relativeTestPath}\n",
            $feature,
            1,
        );
        $this->filesystem->put($fixture['featurePath'], $feature);
        $this->app->instance(StagedFileResolver::class, fakeStagedFileResolver([$explicitTestPath]));

        /** @When the feature parity command checks staged files */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '-s' => true,
            '-l' => true,
            '--no-ansi' => true,
        ], $output);

        /** @Then the explicitly mapped feature is checked */
        expect($exitCode)->toBe(0);
        expect($output->fetch())
            ->toContain('ExplicitCommandTest')
            ->toContain('should run through Artisan');
    });

    it('should skip when no staged Gherkish files exist', function () {
        /** @Given the staged selection contains no feature or Pest test files */
        $this->app->instance(StagedFileResolver::class, fakeStagedFileResolver([base_path('README.md')]));

        /** @When the feature parity command checks staged files */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '-s' => true,
            '-l' => true,
            '--no-ansi' => true,
        ], $output);

        /** @Then the staged check reports an empty successful selection */
        expect($exitCode)->toBe(0);
        expect($output->fetch())
            ->toContain('No Gherkin scenarios found for selection "staged files".')
            ->toContain('1 skipped');
    });

    it('should limit reverse mapping validation to staged Pest files', function () {
        /** @Given staged and unstaged Pest files without feature mappings */
        $directory = $this->fixtureRoot.'/staged-reverse-mapping';
        $this->filesystem->makeDirectory($directory, 0777, true, true);
        $stagedTestPath = $directory.'/StagedTest.php';
        $this->filesystem->put($stagedTestPath, "<?php\n\ntest('staged orphan', function () {});\n");
        $this->filesystem->put($directory.'/UnstagedTest.php', "<?php\n\ntest('unstaged orphan', function () {});\n");
        $this->app->instance(StagedFileResolver::class, fakeStagedFileResolver([$stagedTestPath]));

        /** @When the staged checker validates reverse test mappings */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '-s' => true,
            '-u' => true,
            '--no-ansi' => true,
        ], $output);

        /** @Then only the staged Pest file is reported as unmapped */
        expect($exitCode)->toBe(1);
        expect($output->fetch())
            ->toContain('staged orphan')
            ->not->toContain('unstaged orphan')
            ->toContain('Tests:      1 unmapped');
    });

    it('should reject staged and explicit path filters together', function () {
        /** @Given a staged selection and an explicit feature directory */
        $fixture = writeFeatureParityFixture('command-success', 'staged-incompatible-selection');

        /** @When the feature parity command checks both selections */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '-s' => true,
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ], $output);

        /** @Then the command reports an incompatible selection failure */
        expect($exitCode)->toBe(1);
        expect($output->fetch())->toContain(
            'The --staged/-s option cannot be combined with --dir, --feature, --file, or --f.'
        );
    });

    it('should discover staged files from the Git index', function () {
        /** @Given a Git repository with staged unstaged and deleted files */
        $repository = sys_get_temp_dir().'/gherkish-staged-'.bin2hex(random_bytes(6));
        $this->filesystem->makeDirectory($repository, 0777, true, true);

        try {
            runGitFixtureCommand($repository, ['init', '--quiet']);
            runGitFixtureCommand($repository, ['config', 'user.email', 'gherkish@test.me']);
            runGitFixtureCommand($repository, ['config', 'user.name', 'Gherkish Tests']);
            $this->filesystem->put($repository.'/deleted.feature', "Feature: Deleted\n");
            runGitFixtureCommand($repository, ['add', 'deleted.feature']);
            runGitFixtureCommand($repository, ['commit', '--quiet', '-m', 'Initial fixture']);
            $stagedPath = $repository.'/staged feature.feature';
            $this->filesystem->put($stagedPath, "Feature: Staged\n");
            $this->filesystem->put($repository.'/unstaged.feature', "Feature: Unstaged\n");
            $this->filesystem->delete($repository.'/deleted.feature');
            runGitFixtureCommand($repository, ['add', 'staged feature.feature', 'deleted.feature']);

            /** @When the staged file resolver reads the Git index */
            $paths = (new GitStagedFileResolver)->resolve($repository);

            /** @Then only existing staged paths are returned */
            expect($paths)->toBe([realpath($stagedPath)]);
        } finally {
            $this->filesystem->deleteDirectory($repository);
        }
    });
});

describe('gherkish:check command', function () {
    it('should register the package command and report successful parity', function () {
        /** @Given a feature and test with matching scenarios and steps */
        $fixture = writeFeatureParityFixture('command-success');

        /** @When the feature parity command checks their directory */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--long' => true,
            '--no-ansi' => true,
        ]);
        $ansiOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $ansiExitCode = Artisan::call('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--long' => true,
        ], $ansiOutput);

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

    it('should render compact status dots by default', function () {
        /** @Given a feature and test with matching scenarios and steps */
        $fixture = writeFeatureParityFixture('command-success');

        /** @When the feature parity command checks their directory in compact mode */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ], $output);

        /** @Then the command reports a status dot, total inner cases, and duration without long-form checks */
        expect($exitCode)->toBe(0);
        expect($output->fetch())
            ->toContain("  .\n")
            ->toContain('Scenarios:  1 covered (3 cases)')
            ->toMatch('/Duration:\s+\d+\.\d{2}s/')
            ->not->toContain('Given a mapped command scenario')
            ->not->toContain('COVERED');
    });

    it('should retain failure details in compact mode', function () {
        /** @Given a feature and test with an unimplemented step */
        $fixture = writeFeatureParityFixture('command-failure');

        /** @When the feature parity command checks the failing directory in compact mode */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ], $output);

        /** @Then the command reports compact statuses and collects failure details at the end */
        expect($exitCode)->toBe(1);
        expect($output->fetch())
            ->toContain("  F.\n")
            ->toContain('MISSING  Tests\\.feature-parity-fixtures\\command-failure\\FixtureTest')
            ->toContain('has Pest step docblocks without executable PHP code directly below them')
            ->toContain('Scenarios:  1 missing, 1 covered')
            ->not->toContain('⨯ Given a mapped command scenario');
    });

    it('should render Symfony console tags as literal text', function () {
        /** @Given feature output contains placeholders matching Symfony console styles */
        $fixture = writeFeatureParityFixture('console-markup');

        /** @When the feature parity command renders long output */
        $plainOutput = new BufferedOutput;
        $plainExitCode = Artisan::call('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--long' => true,
            '--no-ansi' => true,
        ], $plainOutput);
        $ansiOutput = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $ansiExitCode = Artisan::call('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--long' => true,
        ], $ansiOutput);

        /** @Then the placeholders should remain literal without activating console styles */
        expect($plainExitCode)->toBe(0);
        expect($plainOutput->fetch())
            ->toContain('Console <info> output → should keep <question> and <error> placeholders literal')
            ->toContain('Given the <question> placeholder remains literal')
            ->toContain('Then the <error> placeholder remains literal')
            ->toContain('Examples: <comment> values (validation disabled):')
            ->toContain('| <question> | <error> |');

        expect($ansiExitCode)->toBe(0);
        expect($ansiOutput->fetch())
            ->not->toContain("\e[37;41m")
            ->not->toContain("\e[30;46m");
    });

    it('should render failed command checks in a Pest-style test file group', function () {
        /** @Given a feature and test with an unimplemented step */
        $fixture = writeFeatureParityFixture('command-failure');

        /** @When the feature parity command checks the failing directory */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--long' => true,
            '--no-ansi' => true,
        ]);

        /** @Then the command reports the failed scenario as checks and collects its details at the end */
        $command
            ->expectsOutputToContain('MISSING  Tests\.feature-parity-fixtures\command-failure\FixtureTest → Command integration → should reject an empty step')
            ->expectsOutputToContain('⨯ Given a mapped command scenario')
            ->expectsOutputToContain('✓ When the package command runs')
            ->expectsOutputToContain('✓ Then it exits successfully')
            ->expectsOutputToContain('COVERED  Tests\.feature-parity-fixtures\command-failure\FixtureTest → Command integration → should accept implemented steps')
            ->expectsOutputToContain('✓ Given another mapped command scenario')
            ->expectsOutputToContain('✓ Then its implementation is accepted')
            ->expectsOutputToContain('has Pest step docblocks without executable PHP code directly below them')
            ->expectsOutputToContain('Scenarios:  1 missing, 1 covered')
            ->assertFailed();
    });

    it('should group missing case mappings with relative locations', function () {
        /** @Given a feature scenario with incomplete Pest case mappings */
        $fixture = writeFeatureParityFixture('missing-steps');

        /** @When the feature parity command renders the missing case details */
        $output = new BufferedOutput;
        $exitCode = Artisan::call('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ], $output);

        /** @Then the output groups case statuses with relative Feature and Pest locations */
        expect($exitCode)->toBe(1);
        expect($output->fetch())
            ->toContain('Scenario is missing one or more mapped Pest cases.')
            ->toContain('Case mapping (1 documented, 3 missing)')
            ->toContain('Feature  tests/.feature-parity-fixtures/missing-steps/Fixture.feature:3')
            ->toContain('Pest     tests/.feature-parity-fixtures/missing-steps/FixtureTest.php:4')
            ->toContain('Pest     not documented')
            ->not->toContain(base_path());
    });

    it('should leave scenario outline dataset validation disabled by default', function () {
        /** @Given a scenario outline whose Pest test has no dataset and no validation flag */
        $fixture = writeFeatureParityFixture('outline-validation-disabled');

        /** @When the checker runs without outline dataset validation */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--long' => true,
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
        $fixture = writeFeatureParityFixture('command-examples');

        /** @When the feature parity command checks the outline directory */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--outlined' => true,
            '--long' => true,
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
        $fixture = writeFeatureParityFixture('outline-without-dataset');

        /** @When the checker validates the outline dataset */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '-o' => true,
            '-l' => true,
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
        $fixture = writeFeatureParityFixture('outline-explicit-dataset');

        /** @When the checker validates the explicit outline dataset */
        $result = runFeatureParityFixture($fixture['dir'], checkOutlineDatasets: true);

        /** @Then the literal dataset should cover the Examples table */
        expect($result->errors)->toBe([]);
        expect($result->successes)->toHaveCount(1);
    });

    it('should reject explicit arrays that differ from scenario outline examples', function () {
        /** @Given a scenario outline whose literal Pest dataset contains different values */
        $fixture = writeFeatureParityFixture('outline-mismatched-dataset');

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
        $fixture = writeFeatureParityFixture('outline-labeled-dataset');

        /** @When the checker validates the labeled outline dataset */
        $result = runFeatureParityFixture($fixture['dir'], checkOutlineDatasets: true);

        /** @Then every labeled Examples block should be covered */
        expect($result->errors)->toBe([]);
        expect($result->successes)->toHaveCount(1);
    });

    it('should allow custom outline datasets to opt out of examples validation', function () {
        /** @Given a scenario outline uses a custom dataset with the ignore examples comment */
        $fixture = writeFeatureParityFixture('outline-ignored-custom-dataset');

        /** @When the checker validates the ignored custom dataset */
        $result = runFeatureParityFixture($fixture['dir'], checkOutlineDatasets: true);
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--outlined' => true,
            '--long' => true,
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

    it('should leave reverse test mapping validation disabled by default', function () {
        /** @Given a selected directory contains a Pest test without a matching feature scenario */
        $fixture = writeFeatureParityFixture('unmapped-tests');

        /** @When the checker runs without reverse test mapping validation */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--no-ansi' => true,
        ]);

        /** @Then the unmatched Pest test should not fail the check */
        $command
            ->doesntExpectOutputToContain('unmapped Pest test')
            ->assertSuccessful();
    });

    it('should leave phased scenario structure validation disabled by default', function () {
        /** @Given mapped scenarios that do not contain every Given When and Then phase */
        $fixture = writeFeatureParityFixture('strict-scenario-structure');

        /** @When the checker runs without phased scenario structure validation */
        $result = runFeatureParityFixture($fixture['dir']);

        /** @Then the structurally incomplete scenarios should remain covered */
        expect($result->errors)->toBe([]);
        expect($result->successes)->toHaveCount(4);
    });

    it('should accept complete scenario structure in phased mode', function () {
        /** @Given mapped scenarios and outlines containing Given When and Then phases */
        $fixture = writeFeatureParityFixture('strict-scenario-structure');

        /** @And additional steps that inherit an established phase */
        expect(file_get_contents($fixture['featurePath']))
            ->toContain('And another setup step')
            ->toContain('But another action is also performed');

        /** @When the checker runs with phased scenario structure validation */
        $result = runFeatureParityFixture($fixture['dir'], strict: true);

        /** @Then every structurally complete scenario should remain covered */
        expect($result->successes)->toContain('Strict scenario structure -> complete scenario structure');
    });

    it('should reject incomplete scenario structure in phased mode', function () {
        /** @Given mapped scenarios and outlines missing Given When or Then phases */
        $fixture = writeFeatureParityFixture('strict-scenario-structure');

        /** @And leading secondary keywords without an established phase */
        expect(file_get_contents($fixture['featurePath']))->toContain('And an orphaned secondary setup step');

        /** @When the checker runs with phased scenario structure validation */
        $result = runFeatureParityFixture($fixture['dir'], strict: true);

        /** @Then each incomplete structure should report its missing phases and feature location */
        expect($result->errors)->toHaveCount(3);
        expect($result->errors[0]['message'])
            ->toContain('is missing the following required phase: Given')
            ->toContain('tests/.feature-parity-fixtures/strict-scenario-structure/Fixture.feature:9');
        expect($result->errors[1]['message'])
            ->toContain('is missing the following required phase: When')
            ->toContain('tests/.feature-parity-fixtures/strict-scenario-structure/Fixture.feature:14');
        expect($result->errors[2]['message'])
            ->toContain('Scenario Outline "outline without an effective Then phase"')
            ->toContain('is missing the following required phase: Then')
            ->toContain('tests/.feature-parity-fixtures/strict-scenario-structure/Fixture.feature:19');
    });

    it('should keep phased mode independent from other optional checks', function () {
        /** @Given phased scenarios with unchecked outline datasets and unmapped Pest tests */
        $fixture = writeFeatureParityFixture('strict-independent-checks');

        /** @When the checker runs with only phased scenario structure validation */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '-p' => true,
            '--long' => true,
            '--no-ansi' => true,
        ]);

        /** @Then outline dataset and reverse mapping validation should remain disabled */
        $command
            ->expectsOutputToContain('Examples (validation disabled)')
            ->doesntExpectOutputToContain('unmapped Pest test')
            ->assertSuccessful();
    });

    it('should report unmapped Pest tests while honoring the mapping ignore comment', function () {
        /** @Given a selected directory contains mapped, unmapped, and mapping-ignored tests */
        $fixture = writeFeatureParityFixture('unmapped-tests');

        /** @When the checker validates reverse test mappings */
        $command = $this->artisan('gherkish:check', [
            '--dir' => $fixture['dir'],
            '--unmapped' => true,
            '--no-ansi' => true,
        ]);

        /** @Then only the non-ignored unmapped Pest test should fail */
        $command
            ->expectsOutputToContain('Pest test "unmapped Pest test"')
            ->expectsOutputToContain('// @gherkish-ignore-mapping')
            ->expectsOutputToContain('Tests:      1 unmapped')
            ->doesntExpectOutputToContain('Pest test "intentionally unmapped Pest test"')
            ->assertFailed();
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
        $fixture = writeFeatureParityFixture('single-examples-dataset');

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

    it('should combine multiple examples blocks by label', function () {
        /** @Given a scenario outline with multiple labeled examples blocks */
        $fixture = writeFeatureParityFixture('combined-examples-dataset');

        /** @When an examples dataset is requested with multiple labels */
        $rows = require $fixture['testPath'];

        /** @Then rows from every selected block are returned in label order */
        expect($rows)->toBe([
            ['email' => 'john@test.com', 'result' => 'failure'],
            ['email' => 'jane@test.com', 'result' => 'failure'],
            ['email' => 'john@test.com', 'result' => 'success'],
            ['email' => 'jane@test.com', 'result' => 'success'],
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
            'Pass one or more of these labels to Gherkish::examples(): "Valid credentials", "Invalid credentials"',
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

function writeFeatureParityFixture(string $fixture, ?string $case = null): array
{
    $filesystem = new Filesystem;
    $case ??= $fixture;
    $stubDir = __DIR__.'/Fixtures/FeatureParity/'.$fixture;
    $featureStub = $stubDir.'/Fixture.feature.stub';
    $testStub = $stubDir.'/FixtureTest.php.stub';

    if (! $filesystem->exists($featureStub)) {
        throw new InvalidArgumentException(sprintf('Feature parity fixture "%s" does not exist.', $fixture));
    }

    $featureContent = $filesystem->get($featureStub);
    $testContent = $filesystem->exists($testStub) ? $filesystem->get($testStub) : null;
    $dir = base_path('tests/.feature-parity-fixtures/'.$case);
    $filesystem->deleteDirectory($dir);
    $filesystem->makeDirectory($dir, 0777, true, true);

    $featurePath = $dir.'/Fixture.feature';
    $testPath = $testContent !== null ? $dir.'/FixtureTest.php' : null;

    $filesystem->put($featurePath, rtrim($featureContent).PHP_EOL);
    if ($testPath !== null) {
        $filesystem->put($testPath, rtrim($testContent).PHP_EOL);
    }

    foreach (glob($stubDir.'/*Test.php.stub') ?: [] as $additionalTestStub) {
        if ($additionalTestStub === $testStub) {
            continue;
        }

        $additionalTestPath = $dir.'/'.basename($additionalTestStub, '.stub');
        $filesystem->put($additionalTestPath, rtrim($filesystem->get($additionalTestStub)).PHP_EOL);
    }

    return [
        'dir' => $dir,
        'featurePath' => $featurePath,
        'testPath' => $testPath,
    ];
}

function snapshotFeatureParityFixture(string $dir): array
{
    putenv('GHERKISH_DIR='.$dir);
    $_ENV['GHERKISH_DIR'] = $dir;
    FeatureParityChecker::resetSelection();

    $snapshot = FeatureParityChecker::snapshot();

    putenv('GHERKISH_DIR');
    unset($_ENV['GHERKISH_DIR']);
    FeatureParityChecker::resetSelection();

    return $snapshot;
}

function runFeatureParityFixture(
    string $dir,
    bool $checkOutlineDatasets = false,
    bool $strict = false,
): FeatureParityResult {
    putenv('GHERKISH_DIR='.$dir);
    $_ENV['GHERKISH_DIR'] = $dir;
    if ($checkOutlineDatasets) {
        putenv('GHERKISH_CHECK_OUTLINE_DATASETS=1');
        $_ENV['GHERKISH_CHECK_OUTLINE_DATASETS'] = '1';
    }
    if ($strict) {
        putenv('GHERKISH_STRICT=1');
        $_ENV['GHERKISH_STRICT'] = '1';
    }
    FeatureParityChecker::resetSelection();

    $result = FeatureParityChecker::run();

    putenv('GHERKISH_DIR');
    unset($_ENV['GHERKISH_DIR']);
    putenv('GHERKISH_CHECK_OUTLINE_DATASETS');
    unset($_ENV['GHERKISH_CHECK_OUTLINE_DATASETS']);
    putenv('GHERKISH_CHECK_UNMAPPED_TESTS');
    unset($_ENV['GHERKISH_CHECK_UNMAPPED_TESTS']);
    putenv('GHERKISH_STRICT');
    unset($_ENV['GHERKISH_STRICT']);
    FeatureParityChecker::resetSelection();

    return $result;
}

/**
 * @param  string[]  $paths
 */
function fakeStagedFileResolver(array $paths): StagedFileResolver
{
    return new class($paths) implements StagedFileResolver
    {
        /**
         * @param  string[]  $paths
         */
        public function __construct(private readonly array $paths) {}

        public function resolve(string $projectPath): array
        {
            return $this->paths;
        }
    };
}

/**
 * @param  string[]  $arguments
 */
function runGitFixtureCommand(string $repository, array $arguments): void
{
    $pipes = [];
    $process = proc_open(array_merge(['git', '-C', $repository], $arguments), [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Git for staged-file fixture.');
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        throw new RuntimeException('Git fixture command failed: '.trim((string) $error));
    }
}

function writeLoginExamplesFixture(string $case): array
{
    return writeFeatureParityFixture('login-examples', $case);
}
