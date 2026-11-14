<?php

/**
 * Contract of ReportGroupingConsumer
 *
 * The public surface of ReportGroupingConsumer that other components call, so they depend
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
 * Contract of ReportGroupingConsumer.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportGroupingConsumerInterface {

	/**
	 * The group one case is in, as it now stands, read as the caller.
	 *
	 * A read only: it never places the case. A case the caller cannot read
	 * answers null, exactly like one that does not exist.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The answer, or null when the case cannot be read.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
	 */
	public function currentGroup(string $caseId): ?array;

	/**
	 * Place one new case in hermiq's grouping, once, and keep the group's id on
	 * the case.
	 *
	 * 🔴 ONCE PER REPORT. hermiq's evaluation adds the report to a group, so
	 * asking again would count the same resident twice. The stored
	 * `reportGroupId` is what makes a second run a no-op.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The answer, or null when the case cannot be read.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-two-hundred-reports-read-as-one-item-with-a-count
	 */
	public function placeCase(string $caseId): ?array;
}//end interface
