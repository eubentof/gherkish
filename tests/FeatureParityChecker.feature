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

  Scenario: should map Background steps to beforeEach
    Given a feature file with Background steps
    And a matching Pest beforeEach with implemented step annotations
    When the checker runs and snapshots that feature directory
    Then the Background and scenario should be fully covered without duplicating Background cases

  Scenario: should require beforeEach for a feature Background
    Given a feature file with a Background and a matching scenario test without beforeEach
    When the checker runs
    Then the error should identify the Background and Pest test locations

  Scenario: should keep Background parity independent from phased structure
    Given a mapped Background supplies the setup phase
    When the checker validates the feature in phased mode
    Then the Background Given should not satisfy the Scenario structure

  Scenario: should reject missing mismatched and unimplemented Background annotations
    Given Background steps with missing mismatched and unimplemented beforeEach annotations
    When the checker runs for each invalid mapping
    Then each error should identify the exact Feature and Pest locations

  Scenario: should let beforeEach annotations satisfy scenario steps without a Background
    Given a feature scenario whose Given annotation is implemented in beforeEach
    When the checker runs without a feature Background
    Then the scenario should remain fully covered

  Scenario: should isolate beforeEach annotations by describe scope
    Given sibling describe scopes with different beforeEach annotations
    When the checker maps their scenario tests
    Then setup annotations should apply only to tests in their own or descendant scope

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

  Scenario: should check only staged feature files
    Given staged covered and unstaged failing feature files
    When the feature parity command checks staged files
    Then only the staged feature scenarios are checked

  Scenario: should resolve a staged conventionally paired Pest test
    Given a staged Pest test beside its same-basename feature
    When the feature parity command checks staged files
    Then the conventionally paired feature is checked

  Scenario: should resolve a staged Pest test through an explicit feature mapping
    Given a staged Pest test listed by a feature in its Tests section
    When the feature parity command checks staged files
    Then the explicitly mapped feature is checked

  Scenario: should skip when no staged Gherkish files exist
    Given the staged selection contains no feature or Pest test files
    When the feature parity command checks staged files
    Then the staged check reports an empty successful selection

  Scenario: should limit reverse mapping validation to staged Pest files
    Given staged and unstaged Pest files without feature mappings
    When the staged checker validates reverse test mappings
    Then only the staged Pest file is reported as unmapped

  Scenario: should reject staged and explicit path filters together
    Given a staged selection and an explicit feature directory
    When the feature parity command checks both selections
    Then the command reports an incompatible selection failure

  Scenario: should discover staged files from the Git index
    Given a Git repository with staged unstaged and deleted files
    When the staged file resolver reads the Git index
    Then only existing staged paths are returned

  Scenario: should register the package command and report successful parity
    Given a feature and test with matching scenarios and steps
    When the feature parity command checks their directory
    Then the command reports the scenario in a colored Pest-style heading and its steps as checks

  Scenario: should render compact status dots by default
    Given a feature and test with matching scenarios and steps
    When the feature parity command checks their directory in compact mode
    Then the command reports a status dot, total inner cases, and duration without long-form checks

  Scenario: should retain failure details in compact mode
    Given a feature and test with an unimplemented step
    When the feature parity command checks the failing directory in compact mode
    Then the command reports compact statuses and collects failure details at the end

  Scenario: should render Symfony console tags as literal text
    Given feature output contains placeholders matching Symfony console styles
    When the feature parity command renders long output
    Then the placeholders should remain literal without activating console styles

  Scenario: should render failed command checks in a Pest-style test file group
    Given a feature and test with an unimplemented step
    When the feature parity command checks the failing directory
    Then the command reports the failed scenario as checks and collects its details at the end

  Scenario: should group missing case mappings with relative locations
    Given a feature scenario with incomplete Pest case mappings
    When the feature parity command renders the missing case details
    Then the output groups case statuses with relative Feature and Pest locations

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

  Scenario: should leave phased scenario structure validation disabled by default
    Given mapped scenarios that do not contain every Given When and Then phase
    When the checker runs without phased scenario structure validation
    Then the structurally incomplete scenarios should remain covered

  Scenario: should accept complete scenario structure in phased mode
    Given mapped scenarios and outlines containing Given When and Then phases
    And additional steps that inherit an established phase
    When the checker runs with phased scenario structure validation
    Then every structurally complete scenario should remain covered

  Scenario: should reject incomplete scenario structure in phased mode
    Given mapped scenarios and outlines missing Given When or Then phases
    And leading secondary keywords without an established phase
    When the checker runs with phased scenario structure validation
    Then each incomplete structure should report its missing phases and feature location

  Scenario: should keep phased mode independent from other optional checks
    Given phased scenarios with unchecked outline datasets and unmapped Pest tests
    When the checker runs with only phased scenario structure validation
    Then outline dataset and reverse mapping validation should remain disabled

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
