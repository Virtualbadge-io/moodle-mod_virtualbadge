@mod @mod_virtualbadge
Feature: The activity needs an API key
  In order to issue certificates
  As an administrator
  I need to enter the API key of the Virtualbadge organisation

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: Without an API key the activity form shows a notice
    Given I log in as "teacher1"
    When I add a "virtualbadge" activity to course "Course 1" section "1"
    Then I should see "The Virtualbadge API key is not configured"

  Scenario: The administrator sees the API settings
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > Virtualbadge" in site administration
    Then I should see "API key"
    And I should see "API address"
    And I should see "Click to enter text"

  Scenario: A configured API key is not shown
    Given the following config values are set as admin:
      | apikey | test-key | mod_virtualbadge |
    And I log in as "admin"
    When I navigate to "Plugins > Activity modules > Virtualbadge" in site administration
    Then I should see "(Set and encrypted)"
    And I should not see "test-key"
