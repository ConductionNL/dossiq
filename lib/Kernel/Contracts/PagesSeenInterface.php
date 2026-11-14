<?php

/**
 * Contract of PagesSeen
 *
 * The public surface of PagesSeen that other components call, so they depend
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
 * Contract of PagesSeen.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PagesSeenInterface {

	/**
	 * The sentence naming the pages still to be seen.
	 *
	 * @param list<int> $pages The unseen pages.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function sentence(array $pages): string;

	/**
	 * The required pages of an in-scope document nobody has seen yet; empty for any other document.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document.
	 *
	 * @return list<int> The unseen required pages.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function unseenFor(string $caseId, string $documentRef): array;

	/**
	 * Refuse a decision while any in-scope document of the case has unseen required pages.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return void
	 *
	 * @throws RefusedException With 409 while a required page is unseen.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function assertAllSeen(string $caseId): void;

	/**
	 * Every in-scope document of a case with required pages nobody has seen yet.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, list<int>> The unseen pages by document.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function unseenInCase(string $caseId): array;
	/**
	 * The refusal of a verdict on an in-scope document whose required pages are not all seen.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $assessment The assessment.
	 *
	 * @return array<string, string> `['pagesSeen' => sentence]`, or empty.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function refusalFor(string $caseId, array $assessment): array;
}//end interface
