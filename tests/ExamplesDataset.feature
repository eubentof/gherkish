Feature: Scenario Outline datasets
  In order to keep test data beside its behavior specification
  As a developer using Pest
  I want Gherkish to expose Examples rows as a dataset

  Scenario Outline: User logs in
    Given a user with email "<email>"
    When they log in with password "<password>"
    Then the result should be "<result>"

    Examples:
      | email            | password | result  |
      | john@test.com    | correct  | success |
      | missing@test.com | anything | failure |
