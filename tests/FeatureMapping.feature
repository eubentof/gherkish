Feature: Feature and test pairing

  Scenario: resolves examples through a remote feature declaration
    Given a remote feature declaring its test file
    When examples are resolved from the test
    Then the remote Examples rows are returned

  Scenario: resolves an explicit feature annotation before a sibling
    Given a test header pointing to a remote feature and a conflicting sibling
    When examples are resolved from the annotated test
    Then the explicitly selected feature supplies the rows

  Scenario: synchronizes both directions without changing executable code
    Given a remote feature declaring its test file
    When feature mappings are synchronized twice
    Then the missing header is added once and the test body is preserved

  Scenario: adds declared tests from test annotations below the feature description
    Given a test annotated with a remote feature path
    When mappings are synchronized
    Then the feature lists the test below its description and sync is repeatable

  Scenario: combines multiple test and feature mappings
    Given two features and two tests linked in opposite directions
    When mappings are synchronized and examples are resolved
    Then all declared links survive and the matching outline supplies examples

  Scenario: rejects broken mappings before writing files
    Given a valid feature declaration and a test pointing to a missing feature
    When synchronization validates the mappings
    Then it reports the missing file and leaves the valid test unchanged

  Scenario: previews and applies mappings through the sync command
    Given an application with a remote feature declaration
    When the sync command previews and then applies changes
    Then preview writes nothing and applying creates the annotation

  Scenario: synchronizes conventional sibling pairings
    Given a feature beside its conventional test
    When the mappings are synchronized
    Then both files declare the sibling pairing and sync is repeatable

  Scenario: rejects an ambiguous outline across paired features
    Given two declared features with the same outline title
    When examples are resolved for their shared test
    Then ambiguity is reported instead of choosing a feature

  Scenario: rejects a missing explicit feature instead of using a sibling
    Given a missing explicit feature and an existing sibling
    When examples are resolved for the annotated test
    Then the invalid explicit mapping is reported
