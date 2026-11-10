<?php

/**
 * One check whether a user may take a decision under a mandate
 *
 * Who decides, which decision on which case, on which day, and what the
 * conflict-of-interest step said about the family relation: the state
 * MandaatCheckService::isAuthorized() builds once and its steps read.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The state of one mandate check; it never leaves the service that built it.
 *
 * @spec openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md
 */
final class MandateCheck {

	/**
	 * The deciding user.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The decision type.
	 *
	 * @var string
	 */
	public string $decisionType = '';

	/**
	 * The case id.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The case properties the conditions read (amount, caseType, subdelegation).
	 *
	 * @var array<string, mixed>
	 */
	public array $caseProperties = [];

	/**
	 * The day of the decision.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $decisionDate;

	/**
	 * Whether the family relation step ran, carried on every verdict after the conflict check.
	 *
	 * @var array<string, mixed>
	 */
	public array $relation = [];
}//end class
