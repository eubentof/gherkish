Feature: English Gherkin support and validation

  Scenario: Validate structure fixtures
    Given valid and malformed English feature files
    When structure validation parses each file
    Then syntax and optional phase policy remain separate

  Scenario: Decode table values without changing their types
    Given a table with escaped and empty cells
    When an Examples dataset decodes the table
    Then newlines pipes backslashes empty cells and zero are preserved

  Scenario: Keep argument payloads isolated from mapping and syntax
    Given Doc String content containing apparent syntax and mapping metadata
    When all consumers parse the feature
    Then only the real scenario and metadata are retained

  Scenario: Reject ambiguous and missing step arguments
    Given repeated step text and malformed argument requests
    Given repeated
    When requests use an ambiguous selector or the wrong argument type
    Then every failure is explicit and source located

  Scenario: Preserve empty and formatted argument content
    Given raw tables and titled Doc String bodies
    When conversions request raw values full strings and title bodies
    Then formatting survives and unsupported headers or titles fail explicitly

  Scenario: Validate malformed files before scenario mapping
    Given a feature without recognized scenarios and a valid paired file
    Then a result
    When the structure command validates and snapshots the directory
    Then valid scenarios are covered and malformed files have file errors only

  Scenario: Isolate Rule backgrounds and wildcard mappings
    Given feature and Rule backgrounds with scoped Pest implementations
    Given global setup
    Given ".$name." setup
    Then done
    When parity checks and snapshots the Rule groups
    Then each Rule requires only its own Background and the shared feature setup
    Given second setup
    Given first setup

  Scenario: Synchronize mappings without touching payloads or tags
    Given a remote tagged Rule feature containing apparent mapping text
    When synchronization runs twice
    Then metadata is inserted before Rule tags and payload text remains unchanged

  Scenario: Resolve nested callbacks without borrowing unrelated annotations
    Given a callback containing strings and completed nested closures
    Given content
    Given wrong
    When argument lookup uses the final callback line
    Then only the enclosing step annotation determines the argument

  Scenario: Require explicit outline values and preserve literal text
    Given an outline and ordinary scenario with angle brackets
    Given content
    When values are supplied explicitly or omitted
    Then zero remains text and unresolved placeholders report the missing key

  Scenario: Keep structure selection and environment settings independent
    Given a staged malformed feature and an unselected valid feature
    When structure validation is enabled through its environment variable
    Then only selected files fail and command overrides restore the environment

  Scenario: Decode Doc String fences and normalize line endings
    Given a typed Doc String with escaped fences and extra indentation
    When the parser reads both supported delimiters
    Then fences content types indentation and empty strings are represented faithfully

  Scenario: Require explicit selectors for nonshared Background arguments
    Given setup inherited by scenarios with different Background owners
    Given content
    When an inferred lookup crosses the Rule boundary
    Then the helper requires a scenario selector and accepts explicit disambiguation
