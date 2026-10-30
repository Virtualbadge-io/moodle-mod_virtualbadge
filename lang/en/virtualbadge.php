<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for mod_virtualbadge.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action_issuenew'] = 'Issue new certificate';
$string['action_reissue'] = 'Issue again';
$string['action_release'] = 'Release';
$string['action_resendemail'] = 'Send email again';
$string['action_retry'] = 'Retry issuing';
$string['apierror'] = 'Virtualbadge error {$a->status}: {$a->detail}';
$string['apiheading'] = 'Virtualbadge API';
$string['apiheading_desc'] = 'API key and address of your Virtualbadge.io organisation. The API key is shown in the Virtualbadge admin panel under Account Settings, Integration.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'API key of the main organisation.';
$string['apikeyorg'] = 'API key: {$a}';
$string['apinotconfigured'] = 'No API key is configured for this organisation.';
$string['apiratelimit'] = 'Hourly limit of calls to Virtualbadge reached. The certificate is sent later automatically.';
$string['apiunauthorised'] = 'Virtualbadge rejected the API key.';
$string['apiurl'] = 'API address';
$string['apiurl_desc'] = 'Address of the Virtualbadge API. Change only if requests are routed through a proxy.';
$string['cachedef_ratelimit'] = 'Calls to the Virtualbadge API per hour';
$string['cachedef_templates'] = 'Templates from Virtualbadge';
$string['categorymap'] = 'Course categories';
$string['categorymap_desc'] = 'One mapping per line: <code>category = organisation key</code>, the main organisation has the key <code>main</code>. The category is given by its ID number or its id. Courses use the mapping of their category or the nearest parent category, otherwise the main organisation. Trainers can only issue with the organisation of their course, unless they have the capability mod/virtualbadge:chooseorg.';
$string['categorymap_invalidline'] = 'Invalid line: {$a}';
$string['categorymap_unknowncategory'] = 'Unknown course category: {$a}';
$string['categorymap_unknownorg'] = 'Unknown organisation: {$a}';
$string['check_count'] = 'Certificates';
$string['check_details'] = 'Activities with failed or retried certificates. The status list of each activity shows the error per participant; after fixing the cause, use "Retry issuing" there.';
$string['check_failed'] = 'Failed in the last 24 hours: {$a}.';
$string['check_failedearlier'] = 'Failed earlier and not issued yet: {$a}.';
$string['check_issued'] = 'Issued in the last 24 hours: {$a}.';
$string['check_issues'] = 'Virtualbadge certificates';
$string['check_lasterror'] = 'Latest error';
$string['check_notconfigured'] = 'No Virtualbadge API key is configured.';
$string['check_overview'] = 'All certificates on this site by status.';
$string['check_retrying'] = 'Being retried: {$a} (for example Virtualbadge not reachable or hourly limit reached).';
$string['completiondetail:issued'] = 'Receive the certificate';
$string['completionissued'] = 'Certificate issued';
$string['completionissued_help'] = 'The activity is complete once the certificate has been issued. If the activity is also a course completion criterion and the certificate is issued on course completion, it is issued as soon as all other criteria are met.';
$string['condition_approval'] = 'Issued after course completion once a trainer has released it.';
$string['condition_completion'] = 'Issued on course completion.';
$string['condition_grade'] = 'Issued when the course total reaches {$a} %.';
$string['condition_gradeitem'] = 'Issued when {$a->item} reaches {$a->grade} %.';
$string['condition_manual'] = 'Issued after release by a trainer.';
$string['conditionmet'] = 'Condition met';
$string['coursecompletedon'] = 'Course completed {$a}';
$string['editsettings'] = 'Edit settings';
$string['emailsentinfo'] = 'Email requested again {$a->count} times, last on {$a->time}';
$string['eventcertificateissued'] = 'Certificate issued';
$string['eventcertificatereissued'] = 'Certificate replaced';
$string['eventemailresent'] = 'Certificate email requested again';
$string['eventissuefailed'] = 'Issuing a certificate failed';
$string['eventissueskipped'] = 'Certificate not issued';
$string['fieldvaluemissing'] = 'Field "{$a->field}": {$a->source} is empty for this participant.';
$string['fieldvaluemissing_course'] = 'Field "{$a->field}": {$a->source} is empty in the settings of this course.';
$string['gradeitem'] = 'Grade';
$string['gradeitem_course'] = 'Course total';
$string['gradeitem_help'] = 'Course total or a single grade item of the course, for example the final quiz. The certificate is issued as soon as this grade reaches the minimum grade.';
$string['inprogress_learner'] = 'Your certificate is being created. The link will appear here shortly.';
$string['invalidsource'] = 'Field "{$a}" uses a source that is not available on this site. Map the field again in the activity settings.';
$string['issuedon'] = 'issued on {$a}';
$string['issueselected'] = 'Issue to selected';
$string['issuing'] = 'Certificate or badge';
$string['mainorg'] = 'Main organisation';
$string['mingrade'] = 'Minimum grade (%)';
$string['mingrade_help'] = 'The certificate is issued as soon as the chosen grade reaches this percentage.';
$string['mingrade_invalid'] = 'Enter a value greater than 0 and at most 100.';
$string['modulename'] = 'Virtualbadge';
$string['modulename_help'] = 'Issues certificates and badges in Virtualbadge.io and shows the link to participants.';
$string['modulenameplural'] = 'Virtualbadges';
$string['noparticipants'] = 'No participants can receive this certificate.';
$string['notconfigured'] = 'The Virtualbadge API key is not configured. Please contact the site administrator.';
$string['opencertificate'] = 'Open certificate';
$string['openvalidation'] = 'Validation page';
$string['openwallet'] = 'Add to wallet';
$string['org'] = 'Organisation';
$string['org_help'] = 'Organisation in Virtualbadge that issues the certificate. The organisation of the course category is used, other organisations only with the capability to choose them.';
$string['orgheading'] = 'Further organisations';
$string['orgheading_desc'] = 'Only needed if certificates are issued by several Virtualbadge organisations, for example sub-organisations with their own API key.';
$string['orgs'] = 'Organisations';
$string['orgs_desc'] = 'One organisation per line: <code>key|name</code> (key: letters, digits, hyphen, underscore). After saving, a field for the API key of each organisation appears below.';
$string['pluginadministration'] = 'Virtualbadge administration';
$string['pluginname'] = 'Virtualbadge';
$string['privacy:metadata:issues'] = 'Certificates issued per user.';
$string['privacy:metadata:issues:certificateurl'] = 'Personal link to the certificate';
$string['privacy:metadata:issues:completedat'] = 'Time the condition was met';
$string['privacy:metadata:issues:status'] = 'Status of the issue';
$string['privacy:metadata:issues:timeissued'] = 'Time of issue';
$string['privacy:metadata:issues:userid'] = 'User who receives the certificate';
$string['privacy:metadata:issues:validationurl'] = 'Public validation link';
$string['privacy:metadata:virtualbadge'] = 'Data sent to Virtualbadge.io to issue a certificate or badge. Deleting data in Moodle does not revoke issued certificates in Virtualbadge.';
$string['privacy:metadata:virtualbadge:dynamic_fields'] = 'Values of the template fields as mapped in the activity, for example course data, grade or profile fields';
$string['privacy:metadata:virtualbadge:email'] = 'Email address';
$string['privacy:metadata:virtualbadge:full_name'] = 'First and last name';
$string['privacy:metadata:virtualbadge:issue_date'] = 'Date of completion';
$string['privacy:metadata:virtualbadge:metadata'] = 'Reference to the Moodle site, activity and user';
$string['ratelimit'] = 'Calls per hour';
$string['ratelimit_desc'] = 'Maximum number of calls per hour the plugin sends to Virtualbadge with this API key. Use the limit agreed with Virtualbadge for your organisation. Further certificates wait and are sent automatically in the next hour. 0: no limit in the plugin, only the limit of Virtualbadge applies.';
$string['ratelimitorg'] = 'Calls per hour: {$a}';
$string['refreshtemplates'] = 'Reload templates';
$string['reissue_confirm'] = 'Issue a new certificate with the current data? The current certificate becomes invalid in Virtualbadge. This uses one credit.';
$string['reissue_done'] = 'A new certificate is being issued. The previous one becomes invalid.';
$string['reissue_failed'] = 'Issuing again failed, the current certificate stays valid: {$a}';
$string['reissue_notpossible'] = 'This certificate cannot be issued again.';
$string['reissue_unchanged'] = 'Not issued again: the data has not changed since the last issue, the current certificate stays valid. Correct the data in Moodle first, or use "Send email again" for a new email.';
$string['release_done'] = 'The certificate has been released.';
$string['release_notpossible'] = 'The certificate could not be released. It may already exist, the participant has not completed the course yet, or cannot receive it.';
$string['releaseall'] = 'Release all {$a}';
$string['releaseall_info'] = '{$a} participants have completed the course and wait for release.';
$string['releaseselected'] = 'Release selected';
$string['releaseselected_done'] = '{$a} certificates have been queued.';
$string['replacedinfo'] = 'Replaces an earlier certificate';
$string['resendemail_done'] = 'Virtualbadge sends the email with the certificate link again.';
$string['resendemail_notpossible'] = 'The email cannot be sent again for this certificate.';
$string['resetissues'] = 'Delete issue records';
$string['resetissues_help'] = 'Deletes the records of issued certificates in Moodle. Certificates already issued in Virtualbadge are not revoked and stay valid.';
$string['retroactive'] = 'Issue to {$a} participants';
$string['retroactive_done'] = '{$a} certificates have been queued.';
$string['retroactive_info'] = '{$a} participants already meet the condition but have no certificate yet.';
$string['retry_done'] = 'Issuing is tried again.';
$string['retry_notpossible'] = 'Issuing cannot be tried again.';
$string['selectparticipant'] = 'Select {$a}';
$string['sendemail'] = 'Email from Virtualbadge';
$string['sendemail_help'] = 'Virtualbadge sends participants an email with the link to their certificate.';
$string['source_course_category'] = 'Course category';
$string['source_course_enddate'] = 'Course end date';
$string['source_course_fullname'] = 'Course name';
$string['source_course_idnumber'] = 'Course ID number';
$string['source_course_shortname'] = 'Course short name';
$string['source_course_startdate'] = 'Course start date';
$string['source_issue_completiondate'] = 'Completion date';
$string['source_issue_grade'] = 'Course grade (%)';
$string['source_issue_maxpoints'] = 'Maximum course total (points)';
$string['source_issue_points'] = 'Course total (points)';
$string['source_itempercent'] = '{$a} (%)';
$string['source_itempoints'] = '{$a} (points)';
$string['source_text'] = 'Fixed text';
$string['sourcegroup_course'] = 'Course and completion';
$string['sourcegroup_grades'] = 'Grade items';
$string['sourcegroup_text'] = 'Same for all participants';
$string['sourcegroup_user'] = 'Participant';
$string['status'] = 'Status';
$string['status_deleted'] = 'Deleted in Virtualbadge';
$string['status_failed'] = 'Error';
$string['status_issued'] = 'Issued';
$string['status_none'] = 'Not issued';
$string['status_pending'] = 'Being created';
$string['status_queued'] = 'Queued';
$string['status_retrying'] = 'Retrying';
$string['status_revoked'] = 'Revoked';
$string['statuslist'] = 'Participants';
$string['task_checkstatus'] = 'Check status of pending certificates';
$string['task_issue'] = 'Issue certificate';
$string['task_verify'] = 'Check issued certificates in Virtualbadge';
$string['template'] = 'Template';
$string['template_help'] = 'Template in Virtualbadge used for the certificate or badge.';
$string['templatechanged'] = 'The template "{$a->template}" has fields that are not mapped in this activity: {$a->fields}. Certificates cannot be issued until the settings are updated.';
$string['templatefield'] = 'Field "{$a}"';
$string['templatefield_help'] = 'Where the value for this field of the template comes from. Fixed text is the same for every participant and may contain placeholders: {firstname}, {lastname}, {fullname}, {email}, {course}, {courseshortname}, {courseidnumber}, {coursestartdate}, {courseenddate}, {completiondate}, {grade}, {points}, {maxpoints}, {profile:shortname}, {coursefield:shortname}. Participant fields are read from the profile, course fields from the course settings when the certificate is issued. If such a value is empty, the certificate is not sent and the status list shows an error. Custom profile fields and custom course fields created by the administrator appear in the list automatically.';
$string['templatefieldtext'] = 'Text for "{$a}"';
$string['templatesunavailable'] = 'Templates could not be loaded: {$a}';
$string['templateunavailable'] = 'The template "{$a}" is no longer active in Virtualbadge. Certificates cannot be issued.';
$string['timeissued'] = 'Issued';
$string['trigger_approval'] = 'Course completion, after release by a trainer';
$string['trigger_completion'] = 'Course completion';
$string['trigger_grade'] = 'Minimum grade';
$string['trigger_manual'] = 'Manual release by a trainer';
$string['triggertype'] = 'Issue on';
$string['triggertype_help'] = 'Course completion: when the course is completed. Course completion, after release by a trainer: the status list shows who has completed the course, a trainer releases the certificate; the completion date is used. Minimum grade: when the course total or a chosen grade item, for example the final quiz, reaches the minimum grade. Manual: a trainer releases the certificate at any time, the release date is used.';
$string['type_badge'] = 'Badge';
$string['type_certificate'] = 'Certificate';
$string['virtualbadge:addinstance'] = 'Add a Virtualbadge activity';
$string['virtualbadge:chooseorg'] = 'Choose any Virtualbadge organisation for an activity';
$string['virtualbadge:manage'] = 'Manage issued certificates';
$string['virtualbadge:receive'] = 'Receive a certificate';
$string['virtualbadge:view'] = 'View Virtualbadge activity';
$string['virtualbadge:viewstatus'] = 'View the status list of all participants';
