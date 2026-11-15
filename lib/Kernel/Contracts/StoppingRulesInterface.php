<?php

/**
 * Contract of StoppingRules
 *
 * The public surface of StoppingRules that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of StoppingRules.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StoppingRulesInterface {

	/**
	 * The app config key of the stopping rule schema.
	 */
	public const SCHEMA_KEY = 'stopping_rule_schema';

	/**
	 * The refusal sentence once review started.
	 */
	public const LOCKED = 'A stopping rule is declared before review. Once a document is marked, it cannot change.';

	/**
	 * Declare the rule, or replace it while review has not started.
	 *
	 * @param string $caseId The case UUID.
	 * @param float $targetRecall The share to find, 0.5 to 0.99.
	 * @param float $confidence 0.90, 0.95 or 0.99.
	 * @param string $userId Who declares it.
	 *
	 * @return array<string, mixed> The stored rule.
	 *
	 * @throws RefusedException When out of range, after review started, or when it cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function declare(string $caseId, float $targetRecall, float $confidence, string $userId): array;

	/**
	 * The case's stopping rule, or null when none was declared.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The rule.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function forCase(string $caseId): ?array;

	/**
	 * Whether any document of the case is marked in or out of scope.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return bool True once review started.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function reviewStarted(string $caseId): bool;
}//end interface
