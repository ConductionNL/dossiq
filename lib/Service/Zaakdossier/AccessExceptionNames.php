<?php

/**
 * Dossiq access exception names: the people and groups of an exception, as their display names
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Puts `accessExceptionNames` (people first, then groups, by display name) on every listing row that carries an
 * exception, for the Who can see this card. A row without one gets no key, so the card leaves the line out.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
 */
class AccessExceptionNames {

	/**
	 * Constructor.
	 *
	 * @param IUserManager  $userManager  Display names of people.
	 * @param IGroupManager $groupManager Display names of groups.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The rows, each with the display names of its exception.
	 *
	 * @param array<int, array<string, mixed>> $documents Rows already narrowed to what the caller may see.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
	 */
	public function withNames(array $documents): array {
		$value = new AccessExceptionValue();

		return array_map(
			function (array $document) use ($value): array {
				$exception = $value->exceptionOf(record: $document);
				if ($exception === null) {
					return $document;
				}

				$names = [];
				foreach ($exception['users'] as $uid) {
					$names[] = ($this->userManager->getDisplayName($uid) ?? $uid);
				}

				foreach ($exception['groups'] as $gid) {
					$names[] = ($this->groupManager->get($gid)?->getDisplayName() ?? $gid);
				}

				return [...$document, 'accessExceptionNames' => $names];
			},
			$documents
		);
	}//end withNames()
}//end class
