<?php

use Gherkish\Examples\ExampleDatasetResolver;
use Gherkish\Examples\ExamplesException;
use Gherkish\Mapping\FeatureMap;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->mappingRoot = sys_get_temp_dir().'/gherkish-mapping-'.bin2hex(random_bytes(6));
    $this->mappingFilesystem = new Filesystem;
    $this->mappingFilesystem->makeDirectory($this->mappingRoot.'/app/Domain', 0777, true);
    $this->mappingFilesystem->makeDirectory($this->mappingRoot.'/tests/Feature', 0777, true);
    $this->mappingFilesystem->put($this->mappingRoot.'/composer.json', '{}');
    $this->mappingFeature = $this->mappingRoot.'/app/Domain/Login.feature';
    $this->featureMap = new FeatureMap($this->mappingRoot);
    $this->mappingTest = $this->mappingRoot.'/tests/Feature/LoginTest.php';
});

afterEach(function () {
    $this->mappingFilesystem->deleteDirectory($this->mappingRoot);
});

function loadFeatureMappingFixture(string $fixture, string $destination): void
{
    $filesystem = new Filesystem;
    $source = __DIR__.'/Fixtures/FeatureMapping/'.$fixture;

    foreach ($filesystem->files($source) as $stub) {
        $name = $stub->getFilename();
        if (str_starts_with($name, 'Expected')) {
            continue;
        }
        $relativePath = match (true) {
            $name === 'Sibling.feature.stub' => 'tests/Feature/Login.feature',
            str_ends_with($name, '.feature.stub') => 'app/Domain/'.basename($name, '.stub'),
            default => 'tests/Feature/'.basename($name, '.stub'),
        };
        $filesystem->copy($stub->getPathname(), $destination.'/'.$relativePath);
    }
}

it('resolves examples through a remote feature declaration', function () {
    /** @Given a remote feature declaring its test file */
    loadFeatureMappingFixture('remote-declaration', $this->mappingRoot);

    /** @When examples are resolved from the test */
    $rows = (new ExampleDatasetResolver)->resolve($this->mappingTest, 2);

    /** @Then the remote Examples rows are returned */
    expect($rows)->toBe([['email' => 'user@test.com']]);
});

it('resolves an explicit feature annotation before a sibling', function () {
    /** @Given a test header pointing to a remote feature and a conflicting sibling */
    loadFeatureMappingFixture('explicit-feature-before-sibling', $this->mappingRoot);

    /** @When examples are resolved from the annotated test */
    $rows = (new ExampleDatasetResolver)->resolve($this->mappingTest, 4);

    /** @Then the explicitly selected feature supplies the rows */
    expect($rows)->toBe([['email' => 'user@test.com']]);
});

it('synchronizes both directions without changing executable code', function () {
    /** @Given a remote feature declaring its test file */
    loadFeatureMappingFixture('bidirectional-sync', $this->mappingRoot);
    $original = file_get_contents($this->mappingTest);

    /** @When feature mappings are synchronized twice */
    $changes = $this->featureMap->synchronize();
    $second = $this->featureMap->synchronize();

    /** @Then the missing header is added once and the test body is preserved */
    expect($changes)->toBe(['tests/Feature/LoginTest.php'])
        ->and(file_get_contents($this->mappingTest))->toContain('// @feature app/Domain/Login.feature', substr($original, 5))
        ->and($second)->toBe([]);
});

it('adds declared tests from test annotations below the feature description', function () {
    /** @Given a test annotated with a remote feature path */
    loadFeatureMappingFixture('annotation-to-declaration', $this->mappingRoot);

    /** @When mappings are synchronized */
    $this->featureMap->synchronize();

    /** @Then the feature lists the test below its description and sync is repeatable */
    expect(file_get_contents($this->mappingFeature))->toBe(file_get_contents(__DIR__.'/Fixtures/FeatureMapping/annotation-to-declaration/ExpectedLogin.feature.stub'))
        ->and($this->featureMap->synchronize())->toBe([]);
});

it('combines multiple test and feature mappings', function () {
    /** @Given two features and two tests linked in opposite directions */
    loadFeatureMappingFixture('multiple-mappings', $this->mappingRoot);

    /** @When mappings are synchronized and examples are resolved */
    $this->featureMap->synchronize();
    $rows = (new ExampleDatasetResolver)->resolve($this->mappingTest, count(file($this->mappingTest)));

    /** @Then all declared links survive and the matching outline supplies examples */
    expect($this->featureMap->annotatedFeatures($this->mappingTest))->toHaveCount(2)
        ->and($this->featureMap->declaredTests($this->mappingFeature))->toHaveCount(2)
        ->and($rows)->toBe([['email' => 'user@test.com']])
        ->and($this->featureMap->synchronize())->toBe([]);
});

it('rejects broken mappings before writing files', function () {
    /** @Given a valid feature declaration and a test pointing to a missing feature */
    loadFeatureMappingFixture('broken-mapping', $this->mappingRoot);
    $original = file_get_contents($this->mappingTest);

    /** @When synchronization validates the mappings */
    $sync = fn () => $this->featureMap->synchronize();

    /** @Then it reports the missing file and leaves the valid test unchanged */
    expect($sync)->toThrow(ExamplesException::class, 'app/Missing.feature')
        ->and(file_get_contents($this->mappingTest))->toBe($original);
});

it('previews and applies mappings through the sync command', function () {
    /** @Given an application with a remote feature declaration */
    loadFeatureMappingFixture('sync-command', $this->mappingRoot);
    $previous = base_path();
    $this->app->setBasePath($this->mappingRoot);

    try {
        /** @When the sync command previews and then applies changes */
        $preview = Artisan::call('gherkish:sync', ['--dry-run' => true]);
        $unchanged = ! str_contains(file_get_contents($this->mappingTest), '@feature');
        $applied = Artisan::call('gherkish:sync');

        /** @Then preview writes nothing and applying creates the annotation */
        expect($preview)->toBe(0)->and($unchanged)->toBeTrue()->and($applied)->toBe(0)
            ->and(file_get_contents($this->mappingTest))->toContain('// @feature app/Domain/Login.feature');
    } finally {
        $this->app->setBasePath($previous);
    }
});

it('synchronizes conventional sibling pairings', function () {
    /** @Given a feature beside its conventional test */
    loadFeatureMappingFixture('sibling-pairing', $this->mappingRoot);
    $sibling = $this->mappingRoot.'/tests/Feature/Login.feature';

    /** @When the mappings are synchronized */
    $this->featureMap->synchronize();

    /** @Then both files declare the sibling pairing and sync is repeatable */
    expect($this->featureMap->declaredTests($sibling))->toBe([realpath($this->mappingTest)])
        ->and($this->featureMap->annotatedFeatures($this->mappingTest))->toBe([realpath($sibling)])
        ->and($this->featureMap->synchronize())->toBe([]);
});

it('rejects an ambiguous outline across paired features', function () {
    /** @Given two declared features with the same outline title */
    loadFeatureMappingFixture('ambiguous-outline', $this->mappingRoot);

    /** @When examples are resolved for their shared test */
    $resolve = fn () => (new ExampleDatasetResolver)->resolve($this->mappingTest, 2);

    /** @Then ambiguity is reported instead of choosing a feature */
    expect($resolve)->toThrow(ExamplesException::class, 'must match exactly one Scenario Outline');
});

it('rejects a missing explicit feature instead of using a sibling', function () {
    /** @Given a missing explicit feature and an existing sibling */
    loadFeatureMappingFixture('missing-explicit-feature', $this->mappingRoot);

    /** @When examples are resolved for the annotated test */
    $resolve = fn () => (new ExampleDatasetResolver)->resolve($this->mappingTest, 3);

    /** @Then the invalid explicit mapping is reported */
    expect($resolve)->toThrow(ExamplesException::class, 'app/Missing.feature');
});
