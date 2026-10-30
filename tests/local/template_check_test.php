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

namespace mod_virtualbadge\local;

/**
 * Tests for the template check.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_virtualbadge\local\template_check
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template_check::class)]
final class template_check_test extends \advanced_testcase {
    /**
     * Activity record as stored.
     *
     * @return \stdClass
     */
    protected function instance(): \stdClass {
        return (object) [
            'org' => 'main',
            'templateid' => 'cert_1',
            'fieldmap' => json_encode(['Ort' => ['source' => 'coursefield:ort', 'value' => '']]),
        ];
    }

    /**
     * API client returning the given templates.
     *
     * @param array|null $templates null to simulate an unreachable API
     */
    protected function api_with(?array $templates): void {
        $mock = $this->createMock(api_client::class);
        if ($templates === null) {
            $mock->method('templates')->willThrowException(new api_exception(0, 'down'));
        } else {
            $mock->method('templates')->willReturn($templates);
        }
        \core\di::set(api_client::class, $mock);
    }

    public function test_matching_template(): void {
        $this->api_with([['id' => 'cert_1', 'name' => 'ZPP', 'type' => 'certificate', 'fields' => ['Ort']]]);
        $this->assertNull(template_check::problems($this->instance()));
    }

    public function test_new_fields(): void {
        $this->api_with([['id' => 'cert_1', 'name' => 'ZPP', 'type' => 'certificate', 'fields' => ['Ort', 'Dauer', 'Start']]]);
        $this->assertSame(['missing' => ['Dauer', 'Start']], template_check::problems($this->instance()));
    }

    public function test_template_no_longer_active(): void {
        $this->api_with([['id' => 'cert_2', 'name' => 'Other', 'type' => 'badge', 'fields' => []]]);
        $this->assertSame(['unavailable' => true], template_check::problems($this->instance()));
    }

    public function test_unreachable_api_shows_nothing(): void {
        $this->api_with(null);
        $this->assertNull(template_check::problems($this->instance()));
    }
}
