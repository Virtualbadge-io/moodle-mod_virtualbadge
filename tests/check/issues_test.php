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

namespace mod_virtualbadge\check;

use core\check\result;
use mod_virtualbadge\local\issuer;

/**
 * Tests for the status check.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_virtualbadge\check\issues
 */
#[\PHPUnit\Framework\Attributes\CoversClass(issues::class)]
final class issues_test extends \advanced_testcase {
    /** @var \stdClass */
    protected \stdClass $instance;

    /** @var \stdClass */
    protected \stdClass $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('apikey', 'key-main', 'mod_virtualbadge');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['fullname' => 'Grundlagen QM']);
        $this->instance = $gen->create_module('virtualbadge', ['course' => $this->course->id, 'name' => 'Zertifikat QM']);
    }

    /**
     * Create an issue for a new participant.
     *
     * @param string $status
     * @param array $record
     */
    protected function create_issue(string $status, array $record = []): void {
        $user = $this->getDataGenerator()->create_and_enrol($this->course);
        $this->getDataGenerator()->get_plugin_generator('mod_virtualbadge')->create_issue(array_merge([
            'virtualbadgeid' => $this->instance->id,
            'userid' => $user->id,
            'status' => $status,
        ], $record));
    }

    public function test_not_configured(): void {
        set_config('apikey', '', 'mod_virtualbadge');
        $this->assertSame(result::NA, (new issues())->get_result()->get_status());
    }

    public function test_ok(): void {
        $this->create_issue(issuer::STATUS_ISSUED);
        $this->create_issue(issuer::STATUS_ISSUED, ['timeissued' => time() - 2 * DAYSECS]);
        $result = (new issues())->get_result();
        $this->assertSame(result::OK, $result->get_status());
        $this->assertStringContainsString('Issued in the last 24 hours: 1.', $result->get_summary());
        $this->assertStringContainsString('All certificates on this site by status.', $result->get_details());
        $this->assertStringNotContainsString('Activities with failed', $result->get_details());
    }

    public function test_retrying_is_a_warning(): void {
        $this->create_issue(issuer::STATUS_RETRYING, ['lasterror' => 'Virtualbadge error 503: unavailable']);
        $result = (new issues())->get_result();
        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('Being retried: 1', $result->get_summary());
        $this->assertStringContainsString('Zertifikat QM', $result->get_details());
        $this->assertStringContainsString('unavailable', $result->get_details());
    }

    public function test_earlier_failure_is_a_warning(): void {
        $this->create_issue(issuer::STATUS_FAILED, ['timemodified' => time() - 2 * DAYSECS, 'lasterror' => 'old']);
        $result = (new issues())->get_result();
        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('Failed earlier and not issued yet: 1.', $result->get_summary());
    }

    public function test_recent_failure_is_an_error(): void {
        $this->create_issue(issuer::STATUS_RETRYING);
        $this->create_issue(issuer::STATUS_FAILED, ['lasterror' => 'Virtualbadge rejected the API key. <b>x</b>']);
        $this->create_issue(issuer::STATUS_FAILED, ['lasterror' => 'Virtualbadge rejected the API key. <b>x</b>']);
        $result = (new issues())->get_result();
        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('Failed in the last 24 hours: 2.', $result->get_summary());
        $this->assertStringContainsString('rejected the API key', $result->get_details());
        $this->assertStringNotContainsString('<b>x</b>', $result->get_details());
    }

    public function test_check_is_registered(): void {
        $checks = \core\check\manager::get_checks('status');
        $this->assertNotEmpty(array_filter($checks, fn($check) => $check instanceof issues));
    }
}
