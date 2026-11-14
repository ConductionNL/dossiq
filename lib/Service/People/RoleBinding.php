<?php

/**
 * A person to bind to a generic role on a case, and what the People tab calls them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
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
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

/**
 * A person to bind to a generic role on a case, and what the People tab calls them.
 *
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
 */
final class RoleBinding {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $caseRow     The stored case.
	 * @param string               $caseId      The case id.
	 * @param string               $uid         The person's Nextcloud user id.
	 * @param string               $genericRole The generic role.
	 * @param string               $displayName What the People tab calls them; '' reads their Nextcloud name.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
	 */
	public function __construct(
		public readonly array $caseRow,
		public readonly string $caseId,
		public readonly string $uid,
		public readonly string $genericRole,
		public readonly string $displayName,
	) {
	}//end __construct()
}//end class
