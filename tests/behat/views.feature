@mod @mod_virtualbadge
Feature: Participants see their certificate and trainers see the status list
  In order to hand out certificates from Virtualbadge
  As a trainer
  I need to see who received a certificate and send failed ones again

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
      | teacher2 | Nina      | Assistant | teacher2@example.com |
      | student1 | Sam       | Issued   | student1@example.com |
      | student2 | Sara      | Failed   | student2@example.com |
      | student3 | Simon     | Waiting  | student3@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | teacher        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "activities" exist:
      | activity     | name                | course | idnumber | templatename        |
      | virtualbadge | Course certificate  | C1     | vb1      | Course certificate  |
    And the following "mod_virtualbadge > issues" exist:
      | virtualbadge | user     | status | lasterror                  |
      | vb1          | student1 | issued |                            |
      | vb1          | student2 | failed | Virtualbadge error 400: x  |

  Scenario: A participant with a certificate sees the links
    When I am on the "Course certificate" "virtualbadge activity" page logged in as "student1"
    Then I should see "Issued"
    And I should see "Open certificate"
    And I should see "Validation page"
    And I should not see "Participants" in the "region-main" "region"

  Scenario: A participant without a certificate sees the condition
    When I am on the "Course certificate" "virtualbadge activity" page logged in as "student3"
    Then I should see "Not issued"
    And I should see "Issued on course completion."
    And I should not see "Open certificate"

  Scenario: A trainer sees the status list and sends a failed certificate again
    When I am on the "Course certificate" "virtualbadge activity" page logged in as "teacher1"
    Then I should see "Participants"
    And "Sam Issued" row "Status" column of "generaltable" table should contain "Issued"
    And "Sara Failed" row "Status" column of "generaltable" table should contain "Error"
    And "Simon Waiting" row "Status" column of "generaltable" table should contain "Not issued"
    And I should see "Virtualbadge error 400: x"
    And "Sam Issued" row "Actions" column of "generaltable" table should contain "Send email again"
    And "Sam Issued" row "Actions" column of "generaltable" table should contain "Issue again"
    When I press "Retry issuing"
    Then I should see "Issuing is tried again."
    And "Sara Failed" row "Status" column of "generaltable" table should contain "Queued"

  Scenario: A non-editing teacher sees the status list without actions
    When I am on the "Course certificate" "virtualbadge activity" page logged in as "teacher2"
    Then I should see "Participants"
    And "Sam Issued" row "Status" column of "generaltable" table should contain "Issued"
    And I should see "Virtualbadge error 400: x"
    And I should not see "Send email again"
    And I should not see "Issue again"
    And I should not see "Retry issuing"

  Scenario: A trainer queues a new certificate that replaces the current one
    When I am on the "Course certificate" "virtualbadge activity" page logged in as "teacher1"
    And I click on "Issue again" "button" in the "Sam Issued" "table_row"
    Then I should see "A new certificate is being issued. The previous one becomes invalid."
    And "Sam Issued" row "Status" column of "generaltable" table should contain "Queued"

  Scenario: Release after course completion is only offered to participants who completed the course
    Given the following "activities" exist:
      | activity     | name                 | course | idnumber | templatename        | triggertype |
      | virtualbadge | Approved certificate | C1     | vb2      | Approved certificate | approval    |
    When I am on the "Approved certificate" "virtualbadge activity" page logged in as "student3"
    Then I should see "Issued after course completion once a trainer has released it."
    And I log out
    When I am on the "Approved certificate" "virtualbadge activity" page logged in as "teacher1"
    Then "Simon Waiting" row "Status" column of "generaltable" table should contain "Not issued"
    And "Simon Waiting" row "Actions" column of "generaltable" table should not contain "Release"

  Scenario: A trainer releases selected participants
    Given the following "activities" exist:
      | activity     | name         | course | idnumber | templatename | triggertype |
      | virtualbadge | Manual badge | C1     | vb2      | Manual badge | manual      |
    When I am on the "Manual badge" "virtualbadge activity" page logged in as "teacher1"
    And I set the field "Select Simon Waiting" to "1"
    And I set the field "Select Sara Failed" to "1"
    And I press "Release selected"
    Then I should see "2 certificates have been queued."
    And "Simon Waiting" row "Status" column of "generaltable" table should contain "Queued"
    And "Sara Failed" row "Status" column of "generaltable" table should contain "Queued"
    And "Sam Issued" row "Status" column of "generaltable" table should contain "Not issued"
