<?php

/**
 * One planned follow-up case, repeating or not
 *
 * The case it follows up, the type and title of the case it plans, when the
 * first one is due, whom the run acts as, and how the series repeats: the
 * input of PlannedFollowUpDocument::build(), carried as one value to the flow
 * document it becomes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use DateTimeImmutable;

/**
 * The fields of one planned follow-up; it never leaves the document builder.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
final class PlannedFollowUp {

	/**
	 * The case the follow-up belongs to.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The planned case's type.
	 *
	 * @var string
	 */
	public string $caseTypeId = '';

	/**
	 * The date the first occurrence is due.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $due;

	/**
	 * The planned case's title.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * The user the run acts as.
	 *
	 * @var string
	 */
	public string $uid = '';

	/**
	 * How the series repeats.
	 *
	 * @var string
	 */
	public string $recurrence = PlannedSeriesCalendar::RECURRENCE_NONE;

	/**
	 * The last date the series may fire on (Y-m-d), or ''.
	 *
	 * @var string
	 */
	public string $until = '';

	/**
	 * How many occurrences the series runs for, or 0.
	 *
	 * @var integer
	 */
	public int $count = 0;
}//end class
