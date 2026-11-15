<?php

/**
 * Contract of CaseReceiptDates
 *
 * The public surface of CaseReceiptDates that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of CaseReceiptDates.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseReceiptDatesInterface {

	/**
	 * The case's start date.
	 *
	 * @var string
	 */
	public const START_DATE = 'startDate';

	/**
	 * The fields to write onto a new case, or [] when it carries them all.
	 *
	 * `startDate` is written when the case has none. It is the receipt day,
	 * unless the term starts on a later day than the request came in: then it
	 * is the day the term starts (decision 181, Q11). The case's `deadline` is
	 * `startDate` plus the processing term, so a request sent on Sunday counts
	 * from the Monday its confirmation names, not from the Sunday before it.
	 * The case type's `receiptDate` question is answered with the receipt day
	 * when the type declares it and the case does not answer it; the answer is
	 * appended to the case's own `properties`, the one place a case type's
	 * answers live (friendly-case-create-form REQ-FCF-003).
	 *
	 * @param array<string, mixed> $case         The case, as just created.
	 * @param string               $termStartsAt The moment the case's term starts, or '' when no calendar answered.
	 *
	 * @return array<string, mixed> The fields to write.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-dossiq-sets-the-receipt-and-start-dates-when-a-case-is-created-req-term-042
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function changesFor(array $case, string $termStartsAt = ''): array;

	/**
	 * The start date dossiq gives a case, whatever start date it carries.
	 *
	 * For the save that sends a draft. The start date a draft carries is the
	 * day it was begun, filled in by the schema's own calculation, not a date
	 * anyone gave; the case starts from the moment it was sent.
	 *
	 * @param array<string, mixed> $case         The case, naming its receipt.
	 * @param string               $termStartsAt The moment the case's term starts, or '' when no calendar answered.
	 *
	 * @return string The start day, `Y-m-d`.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function startDateFor(array $case, string $termStartsAt): string;
}//end interface
