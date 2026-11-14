<?php

/**
 * Contract of ZgwRuleResults
 *
 * The public surface of ZgwRuleResults that other components call, so they depend
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
 * Contract of ZgwRuleResults.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwRuleResultsInterface {

	/**
	 * Refuse because a rule could not run its check, and log which rule and which object.
	 *
	 * A lookup that ran and found nothing is an answer. A lookup that could not
	 * run is not, so the rule refuses instead of passing. The refusal carries no
	 * field errors: nothing in the request is wrong.
	 *
	 * @param string $rule The rule, as the standard names it (drc-004).
	 * @param string $object What the rule was checking.
	 * @param string $cause Why the check could not run.
	 *
	 * @return array{valid: bool, status: int, detail: string, invalidParams: array, enrichedBody: array} With `code` set.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function notVerified(string $rule, string $object, string $cause): array;
}//end interface
