<?php

/**
 * A colleague's ask for a case: which case, who wants it, who holds it and why.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

/**
 * A colleague's ask for a case: which case, who wants it, who holds it and why.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
 */
final class TakeoverAsk {

	/**
	 * Constructor.
	 *
	 * @param string $caseId      The case id.
	 * @param string $requestedBy Who wants it.
	 * @param string $holder      Who holds it.
	 * @param string $unit        The holding unit.
	 * @param string $reason      Why they should have it.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $requestedBy,
		public readonly string $holder,
		public readonly string $unit,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
