<?php

/**
 * Contract of TriageRules
 *
 * The public surface of TriageRules that other components call, so they depend
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
 * Contract of TriageRules.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TriageRulesInterface {

	/**
	 * Apply the active rules in order to every unmarked document of the case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{counts: array<string, int>, marked: int, considered: int} The documents each rule marked,
	 *         the total, and how many unmarked documents were looked at.
	 *
	 * @throws RefusedException When the rules, the reviews or a document's facts cannot be read or written.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function apply(string $caseId): array;

	/**
	 * Delete the case's rule `ruleId`. The documents it marked keep their marking and its name.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $ruleId The rule.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the rule is not the case's, or cannot be deleted.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function delete(string $caseId, string $ruleId): void;

	/**
	 * The case's rules in order, each with how many documents it decided and how many reviewers overturned.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>> The rules with `decided` and `overturned`.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function forCase(string $caseId): array;

	/**
	 * Create a rule, or change the case's rule `ruleId`.
	 *
	 * @param string               $caseId The case UUID.
	 * @param array<string, mixed> $input  `name`, `conditions`, `effect`, and optionally `order` and `active`.
	 * @param string|null          $ruleId The rule to change, null to create one.
	 *
	 * @return array<string, mixed> The saved rule.
	 *
	 * @throws RefusedException When the rule is incomplete, its name is taken, the rule is not the case's, or nothing can be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
	 */
	public function save(string $caseId, array $input, ?string $ruleId = null): array;
}//end interface
