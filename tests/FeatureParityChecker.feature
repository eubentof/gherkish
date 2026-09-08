Feature: FeatureParityChecker parsing
  In order to trust the feature coverage report
  As a developer maintaining scenarios
  I want the parser to correctly map Pest tests to Gherkin steps

  Scenario: should report coverage for both Pest helpers
    Given a feature file containing scenarios for both Pest helpers
    And matching Pest tests with step comments
    When the checker snapshots that feature directory
    Then both scenarios are reported as fully covered

  Scenario: should ignore helper names inside other functions
    Given a feature file that references visit inside a test body
    When the checker snapshots that feature directory
    Then the parser should only register the real Pest test
    And the scenario should remain fully covered

  Scenario: should report missing steps when docblocks are incomplete
    Given a feature file with a scenario that has undocumented steps
    When the checker snapshots that feature directory
    Then the missing steps should appear in the coverage report

  Scenario: should match placeholder titles with dataset values
    Given a scenario title that contains placeholder tokens
    And a Pest test whose name replaces the placeholder with a dataset value
    When the checker snapshots that feature directory
    Then the scenario should be considered covered

  Scenario: should ignore background steps when computing coverage
    Given a feature file with background steps and scenario steps
    And matching Pest tests documenting only scenario steps
    When the checker snapshots that feature directory
    Then background steps should not appear in the coverage map

  Scenario: should match And/But steps with multiline docblocks
    Given a feature file containing And and But steps
    And the Pest test documents them using multiline docblocks
    When the checker snapshots that feature directory
    Then all steps should be considered covered

  Scenario: should parse scenario outlines while ignoring example rows
    Given a scenario outline that contains an examples table
    And a Pest test that documents the outline steps using placeholders
    When the checker snapshots that feature directory
    Then the outline steps should be covered exactly once

  Scenario: should reject step docblocks without executable code directly below them
    Given a Pest test contains step docblocks followed by another step, a comment, or a blank line
    When the checker runs for that Pest test
    Then every invalid docblock should report its exact feature step and Pest locations

  Scenario: should flag missing paired test files
    Given a feature file without a corresponding Pest test file
    When the checker runs
    Then the result should report a missing test file error

  Scenario: should discover feature files in both tests and app by default
    Given feature files exist under both default discovery roots
    When the checker resolves its default feature selection
    Then features from tests and app are included

  Scenario: should register the package command and report successful parity
    Given a feature and test with matching scenarios and steps
    When the feature parity command checks their directory
    Then the command reports the scenario in a colored Pest-style heading and its steps as checks

  Scenario: should render compact status dots by default
    Given a feature and test with matching scenarios and steps
    When the feature parity command checks their directory in compact mode
    Then the command reports a status dot, total inner cases, and duration without descriptive checks

  Scenario: should retain failure details in compact mode
    Given a feature and test with an unimplemented step
    When the feature parity command checks the failing directory in compact mode
    Then the command reports compact statuses and collects failure details at the end

  Scenario: should render Symfony console tags as literal text
    Given feature output contains placeholders matching Symfony console styles
    When the feature parity command renders descriptive output
    Then the placeholders should remain literal without activating console styles

  Scenario: should render failed command checks in a Pest-style test file group
    Given a feature and test with an unimplemented step
    When the feature parity command checks the failing directory
    Then the command reports the failed scenario as checks and collects its details at the end

  Scenario: should render scenario outline examples as a Gherkin table
    Given a scenario outline with example rows and a matching Pest test
    When the feature parity command checks the outline directory
    Then the example headers and rows are reported as a Gherkin table

  Scenario: should require a mapped Pest dataset for scenario outlines
    Given a scenario outline whose matching Pest test has no dataset
    When the checker validates the outline dataset
    Then the Examples table should be reported as unmapped

  Scenario: should leave scenario outline dataset validation disabled by default
    Given a scenario outline whose Pest test has no dataset and no validation flag
    When the checker runs without outline dataset validation
    Then the scenario should be covered and its Examples table marked as not validated

  Scenario: should accept explicit arrays matching scenario outline examples
    Given a scenario outline whose Pest dataset is a literal array of its example values
    When the checker validates the explicit outline dataset
    Then the literal dataset should cover the Examples table

  Scenario: should reject explicit arrays that differ from scenario outline examples
    Given a scenario outline whose literal Pest dataset contains different values
    When the checker validates the mismatched outline dataset
    Then the literal dataset should not cover the Examples table

  Scenario: should accept labeled Gherkish examples datasets
    Given a scenario outline with labeled Examples blocks mapped by name
    When the checker validates the labeled outline dataset
    Then every labeled Examples block should be covered

  Scenario: should allow custom outline datasets to opt out of examples validation
    Given a scenario outline uses a custom dataset with the ignore examples comment
    When the checker validates the ignored custom dataset
    Then the scenario and steps should be covered without validating its Examples table

  Scenario: should leave reverse test mapping validation disabled by default
    Given a selected directory contains a Pest test without a matching feature scenario
    When the checker runs without reverse test mapping validation
    Then the unmatched Pest test should not fail the check

  Scenario: should report unmapped Pest tests while honoring the mapping ignore comment
    Given a selected directory contains mapped, unmapped, and mapping-ignored tests
    When the checker validates reverse test mappings
    Then only the non-ignored unmapped Pest test should fail

  Scenario: should return a failure for an invalid selection
    Given a feature directory that does not exist
    When the feature parity command checks that directory
    Then the command reports a configuration failure

  Scenario: should return rows from a single examples block without a label
    Given a scenario outline with one examples block
    When the examples dataset is requested without a label
    Then every example row is returned as an associative array

  Scenario: should select an examples block by label
    Given a scenario outline with multiple labeled examples blocks
    When an examples dataset is requested by label
    Then only rows from the matching examples block are returned

  Scenario: should combine multiple examples blocks by label
    Given a scenario outline with multiple labeled examples blocks
    When an examples dataset is requested with multiple labels
    Then rows from every selected block are returned in label order

  Scenario: should require a label for multiple examples blocks
    Given a scenario outline with multiple examples blocks
    When the examples dataset is requested without a label
    Then the available labels are reported in the error

  Scenario: should reject an unknown examples label
    Given a scenario outline with labeled examples blocks
    When an examples dataset is requested with an unknown label
    Then the available labels are reported in the error
