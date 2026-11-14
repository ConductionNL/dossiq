<?php

/**
 * Contract of PaymentScheduleOverview
 *
 * The public surface of PaymentScheduleOverview that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PaymentScheduleOverview.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentScheduleOverviewInterface {

	/**
	 * The instalments of every decision on the case, in date order, and their totals.
	 *
	 * `empty` is true while the case has no instalment, so a card can say so
	 * rather than show zeros.
	 *
	 * @param string            $caseId The case UUID.
	 * @param DateTimeImmutable $today  The day the states count from.
	 *
	 * @return array{empty: bool, instalments: list<array<string, mixed>>, totals: array<string, float>}
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-a-handler-runs-a-grant-from-its-case-page-req-sub-case-01
	 */
	public function forCase(string $caseId, DateTimeImmutable $today): array;
}//end interface
