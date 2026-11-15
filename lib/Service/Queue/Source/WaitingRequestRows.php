<?php

/**
 * Which request rows still wait on someone, and where a row opens.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue\Source;

/**
 * The open-row test and the case route the advice and consultation sources share.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
final class WaitingRequestRows {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $closedStatuses The statuses that take a row off the queue.
	 */
	public function __construct(
		private readonly array $closedStatuses,
	) {
	}//end __construct()

	/**
	 * Whether a row with an id is still waiting: its status is not a closed one.
	 *
	 * @param string               $id  The row's id, '' when it has none.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function isWaiting(string $id, array $row): bool {
		$status = strtolower(trim((string)($row['status'] ?? '')));

		return $id !== '' && in_array($status, $this->closedStatuses, true) === false;
	}//end isWaiting()

	/**
	 * Where a row opens: the given case route, or the case list when the row names no case.
	 *
	 * @param string               $case      The case id, '' when the row names none.
	 * @param array<string, mixed> $caseRoute The route into the case.
	 *
	 * @return array<string, mixed> The route.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public static function routeOf(string $case, array $caseRoute): array {
		if ($case === '') {
			return ['name' => 'Cases'];
		}

		return $caseRoute;
	}//end routeOf()
}//end class
