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

namespace mod_virtualbadge\task;

use mod_virtualbadge\local\api_client;
use mod_virtualbadge\local\api_exception;
use mod_virtualbadge\local\issuer;

/**
 * Tests for the issue and status tasks.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_virtualbadge\task\issue_certificate
 * @covers     \mod_virtualbadge\task\check_status
 * @covers     \mod_virtualbadge\task\verify_issues
 * @covers     \mod_virtualbadge\local\issuer
 * @covers     \mod_virtualbadge\event\certificate_issued
 * @covers     \mod_virtualbadge\event\issue_failed
 * @covers     \mod_virtualbadge\event\certificate_reissued
 * @covers     \mod_virtualbadge\event\email_resent
 */
#[\PHPUnit\Framework\Attributes\CoversClass(issue_certificate::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(check_status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(verify_issues::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(issuer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_virtualbadge\event\certificate_issued::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_virtualbadge\event\issue_failed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_virtualbadge\event\certificate_reissued::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_virtualbadge\event\email_resent::class)]
final class issue_certificate_test extends \advanced_testcase {
    /** @var \stdClass */
    protected \stdClass $course;

    /** @var \stdClass */
    protected \stdClass $student;

    /** @var \stdClass */
    protected \stdClass $instance;

    /** @var \stdClass */
    protected \stdClass $issue;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1, 'fullname' => 'Grundlagen QM']);
        $this->student = $gen->create_and_enrol($this->course, 'student', [
            'firstname' => 'Sophie',
            'lastname' => 'Hartmann',
            'email' => 'sophie@example.org',
        ]);
        $this->instance = $gen->create_module('virtualbadge', [
            'course' => $this->course->id,
            'triggertype' => 'manual',
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionissued' => 1,
        ]);
        $cm = get_fast_modinfo($this->course)->get_cm($this->instance->cmid);
        $this->issue = issuer::queue($this->instance, $cm, $this->student->id, 'manual', make_timestamp(2026, 10, 24, 12));
    }

    /**
     * Replace the API client in the DI container.
     *
     * @param array|null $fields template fields, null for no active template
     * @return \PHPUnit\Framework\MockObject\MockObject&api_client
     */
    protected function mock_api(?array $fields = ['kurs', 'note']) {
        $mock = $this->createMock(api_client::class);
        $templates = $fields === null ? [] : [[
            'id' => 'cert_test',
            'name' => 'Test template',
            'type' => 'certificate',
            'fields' => $fields,
            'slots' => ['signature' => 'signature-ceo'],
        ]];
        $mock->method('templates')->willReturn($templates);
        \core\di::set(api_client::class, $mock);
        return $mock;
    }

    /**
     * Run the issue task of the queued issue.
     */
    protected function run_issue_task(): void {
        $task = new issue_certificate();
        $task->set_custom_data(['issueid' => $this->issue->id]);
        $task->execute();
    }

    /**
     * Current issue record.
     *
     * @return \stdClass
     */
    protected function issue(): \stdClass {
        global $DB;
        return $DB->get_record('virtualbadge_issues', ['id' => $this->issue->id]);
    }

    public function test_issued(): void {
        $api = $this->mock_api();
        $api->expects($this->once())->method('issue')
            ->with('main', 'cert_test', $this->callback(function (array $recipient): bool {
                $fields = (array) $recipient['dynamic_fields'];
                return $recipient['email'] === 'sophie@example.org'
                    && $recipient['full_name'] === 'Sophie Hartmann'
                    && $recipient['issue_date'] === '2026-10-24'
                    && $fields['kurs'] === 'Grundlagen QM'
                    && $fields['signature'] === 'signature-ceo'
                    && $recipient['metadata'] === ['moodle_ref' => $this->issue->reference];
            }), false)
            ->willReturn([
                'id' => 'recp_1',
                'status' => 'issued',
                'certificate_url' => 'https://app.example.org/a?token=t',
                'validation_url' => 'https://www.example.org/v/1',
                'wallet_url' => null,
            ]);
        $api->expects($this->never())->method('find');

        $sink = $this->redirectEvents();
        $this->run_issue_task();
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_virtualbadge\event\certificate_issued);
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertEquals($this->student->id, reset($events)->relateduserid);

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_ISSUED, $issue->status);
        $this->assertSame('recp_1', $issue->remoteid);
        $this->assertSame('https://app.example.org/a?token=t', $issue->certificateurl);
        $this->assertNotEmpty($issue->timeissued);

        $cm = get_fast_modinfo($this->course)->get_cm($this->instance->cmid);
        $completion = new \completion_info($this->course);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $this->student->id)->completionstate);
    }

    public function test_special_characters_and_year_end(): void {
        global $DB;
        $this->setTimezone('Europe/Berlin');
        $DB->update_record('user', (object) [
            'id' => $this->student->id,
            'firstname' => " Seán  O'Brien-Müller",
            'lastname' => 'Weiß-Đorđević   Şahin ',
            'email' => 'vorname.nachname+zertifikat@example-domain.de',
        ]);
        $DB->set_field('virtualbadge_issues', 'completedat', make_timestamp(2026, 12, 31, 23, 30), ['id' => $this->issue->id]);
        $api = $this->mock_api([]);
        $api->expects($this->once())->method('issue')
            ->with('main', 'cert_test', $this->callback(fn(array $r) =>
                $r['full_name'] === "Seán O'Brien-Müller Weiß-Đorđević Şahin"
                && $r['email'] === 'vorname.nachname+zertifikat@example-domain.de'
                && $r['issue_date'] === '2026-12-31'), false)
            ->willReturn(['id' => 'recp_1', 'status' => 'issued', 'certificate_url' => 'https://a']);

        $this->run_issue_task();
        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);
    }

    public function test_pending_schedules_status_check(): void {
        $api = $this->mock_api();
        $api->method('issue')->willReturn(['id' => 'recp_1', 'status' => 'pending', 'certificate_url' => 'https://a']);
        $api->expects($this->once())->method('status')->with('main', 'recp_1')
            ->willReturn(['id' => 'recp_1', 'status' => 'issued']);

        $this->run_issue_task();

        $this->assertSame(issuer::STATUS_PENDING, $this->issue()->status);
        $tasks = \core\task\manager::get_adhoc_tasks(check_status::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertGreaterThan(time(), $task->get_next_run_time());
        $this->assertLessThanOrEqual(time() + issuer::STATUS_DELAYS[0], $task->get_next_run_time());

        $task->execute();
        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);
    }

    public function test_temporary_error_is_retried_without_duplicate(): void {
        $api = $this->mock_api();
        $api->expects($this->once())->method('issue')->willThrowException(new api_exception(502, 'upstream error'));
        $api->expects($this->once())->method('find')->with('main', 'cert_test', $this->issue->reference)
            ->willReturn(['id' => 'recp_1', 'status' => 'issued']);

        try {
            $this->run_issue_task();
            $this->fail('Exception expected');
        } catch (api_exception $e) {
            $this->assertSame(502, $e->status);
        }
        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_RETRYING, $issue->status);
        $this->assertEquals(1, $issue->attempts);
        $this->assertStringContainsString('upstream error', $issue->lasterror);

        $this->run_issue_task();
        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);
        $this->assertSame('recp_1', $this->issue()->remoteid);
    }

    public function test_permanent_error_fails(): void {
        $api = $this->mock_api();
        $api->method('issue')->willThrowException(new api_exception(400, 'invalid'));

        $sink = $this->redirectEvents();
        $this->run_issue_task();
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_virtualbadge\event\issue_failed);
        $sink->close();
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertStringContainsString('invalid', $event->other['error']);
        $this->assertStringContainsString('invalid', $event->get_description());

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_FAILED, $issue->status);
        $this->assertStringContainsString('invalid', $issue->lasterror);
    }

    public function test_gives_up_after_max_attempts(): void {
        global $DB;
        $DB->set_field('virtualbadge_issues', 'attempts', issuer::MAX_ATTEMPTS - 1, ['id' => $this->issue->id]);
        $api = $this->mock_api();
        $api->method('find')->willReturn(null);
        $api->method('issue')->willThrowException(new api_exception(429, 'rate limit'));

        $this->run_issue_task();

        $this->assertSame(issuer::STATUS_FAILED, $this->issue()->status);
    }

    public function test_resend_after_failure(): void {
        $api = $this->mock_api();
        $calls = 0;
        $api->method('issue')->willReturnCallback(function () use (&$calls): array {
            if ($calls++ === 0) {
                throw new api_exception(404, 'unknown certificate');
            }
            return ['id' => 'recp_2', 'status' => 'issued'];
        });
        $this->run_issue_task();
        $this->assertSame(issuer::STATUS_FAILED, $this->issue()->status);

        $this->assertTrue(issuer::retry($this->issue()));
        $this->run_issue_task();

        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);
        $this->assertFalse(issuer::retry($this->issue()));
    }

    public function test_inactive_template_fails_without_call(): void {
        $api = $this->mock_api(null);
        $api->expects($this->never())->method('issue');

        $this->run_issue_task();

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_FAILED, $issue->status);
        $this->assertStringContainsString('Test template', $issue->lasterror);
    }

    public function test_unmapped_template_field_fails_without_call(): void {
        $api = $this->mock_api(['kurs', 'note', 'Ort']);
        $api->expects($this->never())->method('issue');

        $this->run_issue_task();

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_FAILED, $issue->status);
        $this->assertStringContainsString('Ort', $issue->lasterror);
    }

    public function test_missing_participant_value_fails_without_call(): void {
        global $DB;
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'personalnr', 'name' => 'Personalnummer',
        ]);
        $DB->set_field('virtualbadge', 'fieldmap', json_encode([
            'Personalnummer' => ['source' => 'profile:personalnr', 'value' => ''],
        ]), ['id' => $this->instance->id]);
        $api = $this->mock_api(['Personalnummer']);
        $api->expects($this->never())->method('issue');

        $this->run_issue_task();

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_FAILED, $issue->status);
        $this->assertStringContainsString('Personalnummer', $issue->lasterror);
    }

    /**
     * Mark the queued issue as issued.
     *
     * @param string $remoteid
     */
    protected function set_issued(string $remoteid = 'recp_old'): void {
        global $DB;
        $DB->update_record('virtualbadge_issues', (object) [
            'id' => $this->issue->id,
            'status' => issuer::STATUS_ISSUED,
            'remoteid' => $remoteid,
            'certificateurl' => 'https://app.example.org/old',
            'timeissued' => time() - 3600,
        ]);
    }

    public function test_reissue_replaces_certificate(): void {
        global $DB;
        $DB->set_field('virtualbadge', 'sendemail', 1, ['id' => $this->instance->id]);
        $this->set_issued();
        $DB->set_field('virtualbadge_issues', 'emailsent', 3, ['id' => $this->issue->id]);
        $api = $this->mock_api();
        $api->expects($this->never())->method('issue');
        $api->expects($this->once())->method('replace')
            ->with('main', 'recp_old', $this->callback(fn(array $r) => $r['full_name'] === 'Sophie Hartmann'))
            ->willReturn(['id' => 'recp_new', 'status' => 'pending', 'certificate_url' => 'https://app.example.org/new']);
        $api->expects($this->once())->method('send_email')->with('main', 'recp_new')->willReturn(['id' => 'recp_new']);

        $this->assertTrue(issuer::reissue($this->issue()));
        $this->assertSame(issuer::STATUS_QUEUED, $this->issue()->status);
        $sink = $this->redirectEvents();
        $this->run_issue_task();
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_virtualbadge\event\certificate_reissued
        ));
        $sink->close();

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_PENDING, $issue->status);
        $this->assertSame('recp_new', $issue->remoteid);
        $this->assertSame('recp_old', $issue->previousremoteid);
        $this->assertNull($issue->replaceremoteid);
        $this->assertEquals(0, $issue->emailsent);
        $this->assertSame('https://app.example.org/new', $issue->certificateurl);
        $this->assertCount(1, $events);
        $this->assertSame(['oldremoteid' => 'recp_old', 'newremoteid' => 'recp_new'], $events[0]->other);
    }

    public function test_reissue_after_interruption_does_not_replace_twice(): void {
        $this->set_issued();
        $api = $this->mock_api();
        $calls = 0;
        $api->method('replace')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            throw new api_exception(0, 'timeout');
        });
        $api->method('status')->with('main', 'recp_old')->willReturn(['id' => 'recp_old', 'status' => 'revoked',
            'email_status' => 'voided']);
        $api->expects($this->once())->method('find_replacement')
            ->with('main', 'cert_test', 'sophie@example.org', 'recp_old')
            ->willReturn(['id' => 'recp_new', 'status' => 'issued']);

        issuer::reissue($this->issue());
        try {
            $this->run_issue_task();
            $this->fail('Exception expected');
        } catch (api_exception $e) {
            $this->assertSame(issuer::STATUS_RETRYING, $this->issue()->status);
        }
        $this->run_issue_task();

        $this->assertSame(1, $calls);
        $this->assertSame('recp_new', $this->issue()->remoteid);
        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);
    }

    public function test_reissue_with_unchanged_data_keeps_certificate(): void {
        $this->set_issued();
        $api = $this->mock_api();
        $api->expects($this->once())->method('replace')->willThrowException(new api_exception(
            400,
            '{"recipient":["Please provide at least one different value to update the recipient."]}'
        ));
        $api->expects($this->never())->method('send_email');

        issuer::reissue($this->issue());
        $this->run_issue_task();

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_ISSUED, $issue->status);
        $this->assertSame('recp_old', $issue->remoteid);
        $this->assertNull($issue->replaceremoteid);
        $this->assertNull($issue->previousremoteid);
        $this->assertSame(get_string('reissue_unchanged', 'mod_virtualbadge'), $issue->lasterror);
        $this->assertTrue(issuer::reissue($issue));
    }

    public function test_reissue_needs_issued_certificate(): void {
        $this->assertFalse(issuer::reissue($this->issue()));
    }

    public function test_resend_email(): void {
        $this->set_issued();
        $api = $this->mock_api();
        $api->expects($this->once())->method('send_email')->with('main', 'recp_old')->willReturn(['id' => 'recp_old']);

        $sink = $this->redirectEvents();
        $this->assertTrue(issuer::resend_email($this->issue()));
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_virtualbadge\event\email_resent);
        $sink->close();

        $issue = $this->issue();
        $this->assertEquals(1, $issue->emailsent);
        $this->assertNotEmpty($issue->timeemailsent);
        $this->assertSame(issuer::STATUS_ISSUED, $issue->status);
        $this->assertCount(1, $events);
    }

    public function test_resend_email_limits(): void {
        global $DB;
        $api = $this->mock_api();
        $api->expects($this->never())->method('send_email');
        $this->assertFalse(issuer::resend_email($this->issue()));

        $this->set_issued();
        $DB->set_field('virtualbadge_issues', 'emailsent', issuer::MAX_EMAILS, ['id' => $this->issue->id]);
        $this->assertFalse(issuer::resend_email($this->issue()));
    }

    public function test_issued_issue_is_not_sent_again(): void {
        global $DB;
        $DB->set_field('virtualbadge_issues', 'status', issuer::STATUS_ISSUED, ['id' => $this->issue->id]);
        $api = $this->mock_api();
        $api->expects($this->never())->method('issue');

        $this->run_issue_task();
    }

    public function test_verify_marks_deleted_certificate(): void {
        $this->set_issued();
        $api = $this->mock_api();
        $api->method('is_configured')->willReturn(true);
        $api->expects($this->once())->method('status')->with('main', 'recp_old')
            ->willThrowException(new api_exception(404, 'not found'));

        $this->expectOutputString("Checked 1 certificates.\nChecked 0 certificates.\n");
        (new verify_issues())->execute();
        (new verify_issues())->execute();

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_DELETED, $issue->status);
        $this->assertNotEmpty($issue->timechecked);
        $this->assertFalse(issuer::reissue($issue));

        $api->expects($this->once())->method('issue')->willReturn(['id' => 'recp_2', 'status' => 'issued']);
        $this->assertTrue(issuer::retry($issue));
        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_QUEUED, $issue->status);
        $this->assertNull($issue->remoteid);
        $this->assertNull($issue->certificateurl);
        $this->run_issue_task();
        $this->assertSame('recp_2', $this->issue()->remoteid);
        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);
    }

    public function test_verify_follows_replacement_in_virtualbadge(): void {
        $this->set_issued();
        $api = $this->mock_api();
        $api->method('is_configured')->willReturn(true);
        $api->method('status')->willReturn(['id' => 'recp_old', 'status' => 'revoked', 'email_status' => 'voided']);
        $api->expects($this->once())->method('find_replacement')
            ->with('main', 'cert_test', 'sophie@example.org', 'recp_old')
            ->willReturn(['id' => 'recp_new', 'status' => 'issued', 'certificate_url' => 'https://app.example.org/new']);

        $this->assertSame(1, issuer::verify_issued());

        $issue = $this->issue();
        $this->assertSame(issuer::STATUS_ISSUED, $issue->status);
        $this->assertSame('recp_new', $issue->remoteid);
        $this->assertSame('recp_old', $issue->previousremoteid);
        $this->assertSame('https://app.example.org/new', $issue->certificateurl);
    }

    public function test_verify_keeps_valid_certificate_and_stops_at_limit(): void {
        $this->set_issued();
        $api = $this->mock_api();
        $api->method('is_configured')->willReturn(true);
        $api->expects($this->once())->method('status')->willThrowException(new api_exception(429, 'limit'));

        $this->assertSame(0, issuer::verify_issued());
        $this->assertEmpty($this->issue()->timechecked);
        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);

        $api = $this->mock_api();
        $api->method('is_configured')->willReturn(true);
        $api->expects($this->once())->method('status')->willReturn(['id' => 'recp_old', 'status' => 'issued']);
        $this->assertSame(1, issuer::verify_issued());
        $this->assertSame(0, issuer::verify_issued());
        $this->assertSame(issuer::STATUS_ISSUED, $this->issue()->status);
        $this->assertNotEmpty($this->issue()->timechecked);
    }

    public function test_resend_email_to_deleted_certificate(): void {
        $this->set_issued();
        $api = $this->mock_api();
        $api->method('send_email')->willThrowException(new api_exception(404, 'not found'));

        $this->assertFalse(issuer::resend_email($this->issue()));
        $this->assertSame(issuer::STATUS_DELETED, $this->issue()->status);
    }
}
