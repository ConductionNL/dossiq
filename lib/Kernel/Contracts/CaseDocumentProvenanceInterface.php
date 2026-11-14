<?php

/**
 * Contract of CaseDocumentProvenance
 *
 * The public surface of CaseDocumentProvenance that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of CaseDocumentProvenance.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDocumentProvenanceInterface {

	/**
	 * The fields a selection of documents can be filtered on.
	 */
	public const FILTER_FIELDS = ['custodian', 'sourceSystem', 'source'];

	/**
	 * One document's provenance on one case, with every field present.
	 *
	 * The record's own provenance wins (a copied file); otherwise this case's
	 * join (a linked document). `sourceSystem` falls back to `source` for a
	 * document collected before the system was recorded separately.
	 *
	 * @param string                    $caseId     The case uuid.
	 * @param string                    $documentId The document uuid.
	 * @param array<string, mixed>|null $record     The record when the caller already read it.
	 *
	 * @return array<string, string> Every field of FIELDS; '' where unknown.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
	 */
	public function forDocument(string $caseId, string $documentId, ?array $record = null): array;

	/**
	 * The mail header fields of one document on one case, as `provenance.mail` stores them.
	 *
	 * Addresses come back lower-cased and `senderDomain` is the part of
	 * `from` after the @. A document that is no mail, or was collected
	 * before its headers were read, answers `known` false and empty fields.
	 *
	 * @param string                    $caseId     The case uuid.
	 * @param string                    $documentId The document uuid.
	 * @param array<string, mixed>|null $record     The record when the caller already read it.
	 *
	 * @return array{
	 *     known: bool,
	 *     unreadable: bool,
	 *     from: string,
	 *     senderDomain: string,
	 *     to: list<string>,
	 *     cc: list<string>,
	 *     date: string,
	 *     messageId: string
	 * }
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
	 */
	public function mailOf(string $caseId, string $documentId, ?array $record = null): array;

	/**
	 * The documents of the case whose provenance matches every field of the filter.
	 *
	 * @param string                $caseId The case uuid.
	 * @param array<string, string> $filter Field => value; fields outside FILTER_FIELDS are refused.
	 *
	 * @return list<string> The matching document uuids.
	 *
	 * @throws InvalidArgumentException When the filter names a field provenance does not carry.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-documents-are-selected-by-their-provenance-req-dpv-002
	 */
	public function select(string $caseId, array $filter): array;

	/**
	 * The conversation of every collected mail on the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, array{threadId: string, messageId: string, parentIds: list<string>, sentAt: string}>
	 *         threadOf() per document uuid, only documents that carry a thread.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function threadsForCase(string $caseId): array;
}//end interface
