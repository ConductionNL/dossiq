<?php

/**
 * Contract of CaseDecisionAccess
 *
 * The public surface of CaseDecisionAccess that other components call, so they depend
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
 * Contract of CaseDecisionAccess.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDecisionAccessInterface {

	/**
	 * The refusal reason handed to OpenRegister.
	 */
	public const REFUSAL = 'case-supervisor-required';

	/**
	 * True to allow, false to refuse, null when no guarded case holds the object.
	 *
	 * @param string $objectUuid The object the decision is on (a case or a document).
	 * @param string $userId     The user who wants to change the decision.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
	 */
	public function mayChange(string $objectUuid, string $userId): ?bool;

	/**
	 * Whether the user may see a decision hidden under hideOthers on the object.
	 *
	 * Only a supervisor of every guarded case the object belongs to sees it;
	 * an object on no guarded case shows nothing beyond what OpenRegister shows.
	 *
	 * @param string $objectUuid The object the decision is on (a case or a document).
	 * @param string $userId     The user who reads the decision.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-supervisor-of-the-case-sees-a-hidden-decision-on-its-file-req-cra-007
	 */
	public function maySee(string $objectUuid, string $userId): bool;
}//end interface
