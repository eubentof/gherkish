Feature: Read step arguments from Pest
  Scenario: Extract users
    Given the following users exist:
      | name   | email              | twitter         |
      | Aslak  | aslak@cucumber.io  | @aslak_hellesoy |
      | Julien | julien@cucumber.io | @jbpros         |
      | Matt   | matt@cucumber.io   | @mattwynne      |
    When the table is read
    Then user values remain strings

  Example: Read a post body
    Given a draft post
      """markdown
      Some Title, Eh?
      ===============
      Here is the first paragraph of my blog post. Lorem ipsum dolor sit amet,
      consectetur adipiscing elit.
      """
    When the title is selected
    Then only the body is returned

  Scenario: Read headerless cells
    Given a shopping list
      | eggs |
      | milk |
    When raw rows are requested
    Then every row is retained

  Scenario: Preserve Doc String formatting
    Given formatted content
      ```text/plain
      Given this is content
        nested

      @tests:
      - not-a-mapping.php
      Scenario: also content
      ```
    When all content is read
    Then indentation and blank lines survive

  Scenario Template: Substitute argument values
    Given an imported user
      | email   | active |
      | <email> | 0      |
    When a personalized message is read
      """
      Hello <email>
      """
    Then both arguments use the selected row
    Scenarios:
      | email            |
      | ada@example.test |
      | lin@example.test |

  Scenario: Resolve a wrapper call
    Given wrapped content
      """
      wrapped
      """
    When the wrapper returns
    Then the caller annotation is used

  Scenario: Select an explicit step
    Given first content
      """
      first
      """
    And second content
      """
      second
      """
    When the second step is selected
    Then explicit lookup returns its content

  Rule: Shared argument setup
    Background:
      Given shared users
        | name |
        | Ada  |

    Scenario: Read shared setup
      Given shared data is available
      When shared data is used
      Then the setup table is available

    Scenario: Reuse shared setup
      Given shared data is available
      When shared data is reused
      Then the same source table is available
