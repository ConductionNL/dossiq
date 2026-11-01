<?php

/**
 * Dossiq HearingPlan value object.
 *
 * The arguments of one scheduling request, kept together so the record can be built from one value.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Hearing
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
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Hearing;

/**
 * The case, date, chairperson, invitees and extras of one scheduling request.
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class HearingPlan {
	/**
	 * Constructor.
	 *
	 * @param string               $caseId         UUID of the parent case.
	 * @param string               $scheduledDate  ISO-8601 date-time of the hearing.
	 * @param string               $chairpersonId  UUID of the chairperson role.
	 * @param array<int, array>    $invitees       Invitee objects.
	 * @param array<string, mixed> $payload        Optional extras.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $scheduledDate,
		public readonly string $chairpersonId,
		public readonly array $invitees,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class
