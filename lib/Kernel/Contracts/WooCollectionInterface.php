<?php

/**
 * Contract of WooCollection
 *
 * The public surface of WooCollection that other components call, so they depend
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

use OCA\Dossiq\Woo\WooCorpusRefused;

/**
 * Contract of WooCollection.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooCollectionInterface {

	/**
	 * Set a document on the case aside before review.
	 *
	 * @param string $caseId      The case uuid.
	 * @param string $documentRef The document's uuid.
	 * @param string $reason      One of {@see self::REASONS}.
	 * @param string $note        What the handler wrote.
	 * @param string $userId      Who sets it aside.
	 *
	 * @return array<string, mixed> The exclusion as stored.
	 *
	 * @throws WooCorpusRefused 400 for an unknown reason, 404 for a document not on the case,
	 *                          409 for an assessed or already excluded document.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	public function exclude(string $caseId, string $documentRef, string $reason, string $note, string $userId): array;

	/**
	 * The collection report: per custodian, per system, the exclusions and the reconciliation.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> `{custodians, systems, exclusions, arrived, assessed, excluded, outstanding,
	 *         documents, conversations, threads}`.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-every-exclusion-before-review-is-kept-with-its-reason-req-wrc-003
	 */
	public function report(string $caseId): array;
}//end interface
