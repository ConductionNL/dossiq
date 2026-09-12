<?php

/**
 * Test stub for OCA\OpenRegister\Db\TaskInboxCriteria.
 *
 * Loaded when the real class is absent (dossiq's unit suite runs without
 * OpenRegister installed). Self-skips via a class_exists() guard, so an
 * installed openregister always wins.
 *
 * The full constructor is mirrored, not a subset, because dossiq calls it
 * with NAMED arguments: `uid`, `isAdmin`, `scope`, `isTerminal` and
 * `objectUuid`. A named-argument call site is a caller like any other, so a
 * stub that renamed or dropped one would let a live-breaking call pass here.
 * StubApiDriftTest pins that against the real class.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Stubs\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

if (class_exists(TaskInboxCriteria::class) === false) {
	/**
	 * Stub class for TaskInboxCriteria — used only in standalone unit tests.
	 */
	final class TaskInboxCriteria {

		/**
		 * Tasks assigned to the caller.
		 */
		public const SCOPE_ASSIGNED = 'assigned';

		/**
		 * Unclaimed tasks in the caller's candidate pools.
		 */
		public const SCOPE_POOLED = 'pooled';

		/**
		 * Tasks the caller watches.
		 */
		public const SCOPE_WATCHED = 'watched';

		/**
		 * Everything the caller may see.
		 */
		public const SCOPE_ALL = 'all';

		/**
		 * Sort by the effective deadline.
		 */
		public const SORT_DUE = 'dueAt';

		/**
		 * Sort by priority.
		 */
		public const SORT_PRIORITY = 'priority';

		/**
		 * Sort by creation time.
		 */
		public const SORT_CREATED = 'created';

		/**
		 * Constructor.
		 *
		 * @param string             $uid            The calling user.
		 * @param array<int, string> $groupIds       The caller's group ids.
		 * @param boolean            $isAdmin        Whether the caller is an administrator.
		 * @param string             $scope          One of the SCOPE_* values.
		 * @param array<int, string> $states         Restrict to these states.
		 * @param boolean|null       $isTerminal     Restrict on terminality, or null for both.
		 * @param string|null        $priority       Restrict to one priority.
		 * @param string|null        $objectUuid     Restrict to tasks anchored to this object.
		 * @param string|null        $runUuid        Restrict to one flow run's tasks.
		 * @param DateTime|null      $overdueAt      Only tasks due strictly before this instant.
		 * @param DateTime|null      $dueAfter       Only tasks due at or after this instant.
		 * @param DateTime|null      $dueBefore      Only tasks due strictly before this instant.
		 * @param string             $sort           One of the SORT_* values.
		 * @param boolean            $sortDescending Whether to invert the sort.
		 *
		 * @return void
		 */
		public function __construct(
			public readonly string $uid,
			public readonly array $groupIds = [],
			public readonly bool $isAdmin = false,
			public readonly string $scope = self::SCOPE_ASSIGNED,
			public readonly array $states = [],
			public readonly ?bool $isTerminal = null,
			public readonly ?string $priority = null,
			public readonly ?string $objectUuid = null,
			public readonly ?string $runUuid = null,
			public readonly ?DateTime $overdueAt = null,
			public readonly ?DateTime $dueAfter = null,
			public readonly ?DateTime $dueBefore = null,
			public readonly string $sort = self::SORT_DUE,
			public readonly bool $sortDescending = false,
		) {

		}//end __construct()

		/**
		 * Every stored `assignee` value that means "this caller".
		 *
		 * @return array<int, string> The names, without duplicates.
		 */
		public function assigneeNames(): array {
			$names = [$this->uid, 'user:' . $this->uid];

			foreach ($this->groupIds as $groupId) {
				$groupId = trim((string)$groupId);
				if ($groupId !== '') {
					$names[] = 'group:' . $groupId;
				}
			}

			return array_values(array_unique($names));

		}//end assigneeNames()
	}//end class
}//end if
