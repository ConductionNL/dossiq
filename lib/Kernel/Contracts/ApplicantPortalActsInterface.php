<?php

/**
 * Contract of ApplicantPortalActs
 *
 * The public surface of ApplicantPortalActs that other components call, so they depend
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
 * Contract of ApplicantPortalActs.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicantPortalActsInterface {

	/**
	 * Record a withdrawal the applicant made from the portal and tell the assignee.
	 *
	 * @param string $caseId     The case that was withdrawn.
	 * @param string $status     The status the withdrawal landed on.
	 * @param string $reason     What the applicant said, or ''.
	 * @param string $occurredAt The moment of the withdrawal, ISO 8601.
	 *
	 * @return boolean True when the case is dossiq's and the withdrawal was recorded.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function recordWithdrawal(string $caseId, string $status, string $reason, string $occurredAt): bool;

	/**
	 * Record a write the applicant made on their case and tell the assignee.
	 *
	 * @param string             $caseId     The case the write landed on.
	 * @param string             $act        `amendment`, `document`, `task-answer` or `answer`.
	 * @param array<int, string> $fields     The field names the act touched.
	 * @param string             $occurredAt The moment of the write, ISO 8601.
	 * @param array<string, mixed> $mandate  The mandate the write ran under: `actingFor`, `actingForLabel`, or none.
	 *
	 * @return boolean True when the case is dossiq's and the act was recorded.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/changes/site-business-and-authorisation/specs/portal-contribution/spec.md
	 */
	public function recordWrite(string $caseId, string $act, array $fields, string $occurredAt, array $mandate=[]): bool;
}//end interface
