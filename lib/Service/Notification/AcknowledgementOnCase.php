<?php

/**
 * What a case says about its acknowledgement of receipt.
 *
 * Whether the case is still a draft, and the acknowledgement duty block it
 * carries. Read from the case row as the store gives it; nothing here writes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
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
 * @spec openspec/specs/burger-notifications/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

/**
 * Reads the acknowledgement facts off a case row.
 *
 * @spec openspec/specs/burger-notifications/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AcknowledgementOnCase {

	/**
	 * Whether the case is still a draft in OpenRegister.
	 *
	 * @param array<string, mixed> $case The case row, with its `@self` envelope when the store gives one.
	 *
	 * @return bool True for a draft.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isDraft(array $case): bool {
		$self = ($case['@self'] ?? null);

		return (is_array($self) === true && ($self['status'] ?? null) === 'draft');
	}//end isDraft()

	/**
	 * The duty block on a case row.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed> The duty, empty when the case carries none.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function duty(array $case): array {
		$duty = ($case['acknowledgementDuty'] ?? null);
		if (is_array($duty) === false) {
			return [];
		}

		return $duty;
	}//end duty()
}//end class
