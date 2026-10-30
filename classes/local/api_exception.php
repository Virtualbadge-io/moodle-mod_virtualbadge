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
 * Error returned by the Virtualbadge API or raised while calling it.
 *
 * @package    mod_virtualbadge
 * @copyright  2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \moodle_exception {
    /** @var int HTTP status, 0 for network errors */
    public int $status;

    /** @var int|null seconds to wait before the next attempt */
    public ?int $retryafter;

    /**
     * Constructor.
     *
     * @param int $status
     * @param string $detail
     * @param int|null $retryafter
     */
    public function __construct(int $status, string $detail, ?int $retryafter = null) {
        $this->status = $status;
        $this->retryafter = $retryafter;
        parent::__construct('apierror', 'mod_virtualbadge', '', (object) ['status' => $status, 'detail' => $detail]);
    }

    /**
     * Whether a later attempt can succeed without changes.
     *
     * @return bool
     */
    public function is_temporary(): bool {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
