<?php

/**
 * Dossiq CaseVisit.
 *
 * A reader opening a case: OpenRegister's audit trail mapper the visit is
 * written to, the case entity and the reader's user id.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * A reader on a case, and the audit trail the visit is written to.
 *
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
 */
final class CaseVisit {

	/**
	 * Constructor.
	 *
	 * @param object $mapper   OpenRegister's audit trail mapper.
	 * @param object $case     The case entity.
	 * @param string $readerId The reader's user id.
	 */
	public function __construct(
		public readonly object $mapper,
		public readonly object $case,
		public readonly string $readerId,
	) {
	}//end __construct()
}//end class
