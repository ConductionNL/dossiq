<?php

/**
 * Dossiq case document provenance
 *
 * Where a document collected onto a case was found, read the same way for
 * every case type (document-provenance, REQ-DPV-001 and REQ-DPV-002): the
 * source, the system it came from, the custodian whose files held it, where
 * it sat, the search terms, and when and by whom it was collected.
 *
 * A copied file carries `provenance` on its own informatieobject. A document
 * linked from another case carries it on this case's zaakinformatieobject
 * join, because the record also belongs to the other case. This class hides
 * that difference from every reader: a collection report, a review batch
 * filter, a triage rule.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Woo\WooCaseDocuments;

/**
 * Reads and filters the provenance of the documents on a case.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
 */
class CaseDocumentProvenance {

	/**
	 * The provenance fields, in the order a reader shows them.
	 */
	public const FIELDS = ['source', 'sourceSystem', 'custodian', 'location', 'terms', 'searchedAt', 'searchedBy'];

	/**
	 * The fields a selection of documents can be filtered on.
	 */
	public const FILTER_FIELDS = ['custodian', 'sourceSystem', 'source'];

	/**
	 * Constructor.
	 *
	 * @param DocumentRecordStore $store         A document's record and its join to the case.
	 * @param WooCaseDocuments    $caseDocuments Which documents are on the case.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentRecordStore $store,
		private readonly WooCaseDocuments $caseDocuments,
	) {
	}//end __construct()

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
	public function forDocument(string $caseId, string $documentId, ?array $record = null): array {
		return $this->normalise(stored: $this->stored(caseId: $caseId, documentId: $documentId, record: $record));
	}//end forDocument()

	/**
	 * Every document on the case with its provenance.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, array<string, string>> Keyed by document uuid.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-a-collected-document-carries-its-provenance-for-any-case-type-req-dpv-001
	 */
	public function forCase(string $caseId): array {
		$rows = [];
		foreach ($this->caseDocuments->idsFor(caseId: $caseId) as $documentId) {
			$rows[$documentId] = $this->forDocument(caseId: $caseId, documentId: $documentId);
		}

		return $rows;
	}//end forCase()

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
	public function select(string $caseId, array $filter): array {
		$unknown = array_diff(array_keys($filter), self::FILTER_FIELDS);
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unknown provenance filter field: ' . implode(', ', $unknown));
		}

		$selected = [];
		foreach ($this->forCase(caseId: $caseId) as $documentId => $provenance) {
			if ($this->matches(provenance: $provenance, filter: $filter) === true) {
				$selected[] = (string)$documentId;
			}
		}

		return $selected;
	}//end select()

	/**
	 * Whether a provenance matches every field of the filter; an empty filter matches all.
	 *
	 * @param array<string, string> $provenance The provenance, as forDocument() answers it.
	 * @param array<string, string> $filter     Field => value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-documents-are-selected-by-their-provenance-req-dpv-002
	 */
	public function matches(array $provenance, array $filter): bool {
		foreach ($filter as $field => $value) {
			if (($provenance[$field] ?? '') !== trim((string)$value)) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * The distinct non-empty values of one field over the case's documents, sorted.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $field  One of FILTER_FIELDS.
	 *
	 * @return list<string> The values.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/document-provenance/spec.md#requirement-documents-are-selected-by-their-provenance-req-dpv-002
	 */
	public function valuesOf(string $caseId, string $field): array {
		if (in_array($field, self::FILTER_FIELDS, true) === false) {
			return [];
		}

		$values = [];
		foreach ($this->forCase(caseId: $caseId) as $provenance) {
			if ($provenance[$field] !== '') {
				$values[$provenance[$field]] = true;
			}
		}

		$names = array_map('strval', array_keys($values));
		sort($names);

		return $names;
	}//end valuesOf()

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
	public function mailOf(string $caseId, string $documentId, ?array $record = null): array {
		$stored = $this->stored(caseId: $caseId, documentId: $documentId, record: $record);
		$mail = $stored['mail'] ?? null;
		if (is_array($mail) === false) {
			$mail = [];
		}

		$from = strtolower(trim($this->scalar(value: ($mail['from'] ?? ''))));
		$domain = '';
		if (str_contains($from, '@') === true) {
			$domain = substr($from, (strrpos($from, '@') + 1));
		}

		return [
			'known' => $mail !== [],
			'unreadable' => (($mail['unreadable'] ?? false) === true),
			'from' => $from,
			'senderDomain' => $domain,
			'to' => $this->addresses(value: ($mail['to'] ?? [])),
			'cc' => $this->addresses(value: ($mail['cc'] ?? [])),
			'date' => trim($this->scalar(value: ($mail['date'] ?? ''))),
			'messageId' => trim($this->scalar(value: ($mail['messageId'] ?? ''))),
		];
	}//end mailOf()

	/**
	 * A list of addresses, lower-cased, from a list or a single address.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return list<string>
	 */
	private function addresses(mixed $value): array {
		$list = [];
		foreach ((array)$value as $address) {
			$address = strtolower(trim($this->scalar(value: $address)));
			if ($address !== '') {
				$list[] = $address;
			}
		}

		return $list;
	}//end addresses()

	/**
	 * A scalar as a string; anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function scalar(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end scalar()

	/**
	 * The provenance as stored: on the record, else on this case's join, else [].
	 *
	 * @param string                    $caseId     The case uuid.
	 * @param string                    $documentId The document uuid.
	 * @param array<string, mixed>|null $record     The record when already read.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(string $caseId, string $documentId, ?array $record): array {
		$record = ($record ?? $this->store->findRecord(recordId: $documentId) ?? []);
		if (is_array($record['provenance'] ?? null) === true) {
			return $record['provenance'];
		}

		foreach ($this->store->joinsFor(recordId: $documentId, caseId: $caseId) as $join) {
			if (is_array($join['provenance'] ?? null) === true) {
				return $join['provenance'];
			}
		}

		return [];
	}//end stored()

	/**
	 * Every field present as a string, `sourceSystem` falling back to `source`.
	 *
	 * @param array<string, mixed> $stored The provenance as stored.
	 *
	 * @return array<string, string>
	 */
	private function normalise(array $stored): array {
		$row = [];
		foreach (self::FIELDS as $field) {
			$value = $stored[$field] ?? '';
			$row[$field] = '';
			if (is_scalar($value) === true) {
				$row[$field] = trim((string)$value);
			}
		}

		if ($row['sourceSystem'] === '') {
			$row['sourceSystem'] = $row['source'];
		}

		return $row;
	}//end normalise()
}//end class
