<?php

/**
 * Dossiq deleted case visibility.
 *
 * Who may see a deleted case in the recycle lens: an administrator, the
 * person who deleted it, or the person it was assigned to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Recycle
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Recycle;

/**
 * Decides whether a caller may see one deleted case; fails closed.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class DeletedCaseVisibility {

	/**
	 * Constructor.
	 *
	 * @param DeletionWindowReader $windowReader Reads the marker and the payload off a row.
	 */
	public function __construct(
		private readonly DeletionWindowReader $windowReader,
	) {
	}//end __construct()

	/**
	 * Whether this caller may see one deleted case in the lens.
	 *
	 * Fails closed at every branch: an unresolved caller, a row with no
	 * assignee and no deleter, and an unreadable payload all deny.
	 *
	 * @param object $entity OpenRegister's object entity.
	 * @param string $userId The caller's user id.
	 * @param bool $isAdmin Whether the caller is an administrator.
	 *
	 * @return bool True when the row belongs in this caller's lens.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function maySee(object $entity, string $userId, bool $isAdmin): bool {
		if ($isAdmin === true) {
			return true;
		}

		if ($userId === '') {
			return false;
		}

		return $this->deletedOrHeldBy(entity: $entity, userId: $userId);
	}//end maySee()

	/**
	 * Whether the user deleted the case, or handled it when it was deleted.
	 *
	 * @param object $entity The deleted case.
	 * @param string $userId The user.
	 *
	 * @return bool True when it was theirs.
	 */
	private function deletedOrHeldBy(object $entity, string $userId): bool {
		$marker = $this->windowReader->marker(entity: $entity);
		if (trim((string)($marker['deletedBy'] ?? '')) === $userId) {
			return true;
		}

		$payload = $this->windowReader->payload(entity: $entity);

		return (trim((string)($payload['assignee'] ?? '')) === $userId);
	}//end deletedOrHeldBy()
}//end class
