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
    Then the command reports success

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

  Scenario: should require a label for multiple examples blocks
    Given a scenario outline with multiple examples blocks
    When the examples dataset is requested without a label
    Then the available labels are reported in the error

  Scenario: should reject an unknown examples label
    Given a scenario outline with labeled examples blocks
    When an examples dataset is requested with an unknown label
    Then the available labels are reported in the error
