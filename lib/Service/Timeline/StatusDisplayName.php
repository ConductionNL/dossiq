<?php

/**
 * The name a timeline entry shows for a status
 *
 * A statusType's name when it has one, its id when it has none or the lookup
 * fails, and nothing when there is no id. The timeline is a record of what
 * happened; an unreadable name must not keep a move off it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use Throwable;

/**
 * Reads the name a status is shown under on the timeline.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class StatusDisplayName {
	/**
	 * Constructor.
	 *
	 * @param StatusTypeLookup $statuses Resolves a statusType id to its name.
	 */
	public function __construct(
		private readonly StatusTypeLookup $statuses,
	) {
	}//end __construct()

	/**
	 * The status's trimmed name, else its id, else nothing.
	 *
	 * @param string $statusTypeId The statusType id.
	 *
	 * @return string The name to show.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function of(string $statusTypeId): string {
		if ($statusTypeId === '') {
			return '';
		}

		try {
			$name = trim($this->statuses->nameFor(statusTypeId: $statusTypeId));
		} catch (Throwable $e) {
			$name = '';
		}

		if ($name === '') {
			return $statusTypeId;
		}

		return $name;
	}//end of()
}//end class
