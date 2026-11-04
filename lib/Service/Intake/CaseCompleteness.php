<?php

/**
 * Dossiq CaseCompleteness.
 *
 * Ticks the completeness items a case type declares
 * (`intakeRequirements.completenessItems`) against the case's documents and
 * fields. An item is answered by a document whose informatieobjecttype equals
 * its `documentType`, by a document whose title or file name holds its
 * `documentTitleContains`, or by a case field (`field`, a dot path) that
 * carries a value.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * Builds the completeness answer of one case.
 *
 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 */
class CaseCompleteness {

	/**
	 * The declared items of a case type, each with a key and a label.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, array<string, string>> The items, malformed ones left out.
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function itemsOf(array $caseType): array {
		$declared = ($caseType[IntakeRequirements::PROPERTY]['completenessItems'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$items = [];
		foreach ($declared as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$key   = trim((string)($item['key'] ?? ''));
			$label = trim((string)($item['label'] ?? ''));
			if ($key === '' || $label === '') {
				continue;
			}

			$clean = ['key' => $key, 'label' => $label];
			foreach (['documentType', 'documentTitleContains', 'field', 'evidence'] as $option) {
				if (is_string($item[$option] ?? null) === true && trim($item[$option]) !== '') {
					$clean[$option] = trim($item[$option]);
				}
			}

			$items[] = $clean;
		}//end foreach

		return $items;
	}//end itemsOf()

	/**
	 * The completeness answer: each item ticked, the counts and the verdict.
	 *
	 * A document the reader may not read still counts as present, but its
	 * name is not shown: `evidence` then stays null.
	 *
	 * @param array<int, array<string, string>>    $items     The declared items (itemsOf()).
	 * @param array<string, mixed>                 $case      The case.
	 * @param array<int, array<string, mixed>>     $documents Every document of the case.
	 * @param array<int, array<string, mixed>>     $readable  The documents the reader may read.
	 *
	 * @return array<string, mixed> `{ items, present, total, missing, verdict }`, or `{ items: [] }` without items.
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function check(array $items, array $case, array $documents, array $readable): array {
		if ($items === []) {
			return ['items' => []];
		}

		$readableIds = [];
		foreach ($readable as $document) {
			$readableIds[] = $this->documentId(document: $document);
		}

		$rows    = [];
		$present = 0;
		foreach ($items as $item) {
			$row = $this->tick(item: $item, case: $case, documents: $documents, readableIds: $readableIds);
			if ($row['present'] === true) {
				$present++;
			}

			$rows[] = $row;
		}

		$total = count($rows);

		return [
			'items' => $rows,
			'present' => $present,
			'total' => $total,
			'missing' => ($total - $present),
			'verdict' => ($present === $total) ? 'complete' : 'incomplete',
		];
	}//end check()

	/**
	 * One item, ticked against the documents and then the case field.
	 *
	 * @param array<string, string>             $item        The item.
	 * @param array<string, mixed>              $case        The case.
	 * @param array<int, array<string, mixed>>  $documents   Every document of the case.
	 * @param array<int, string>                $readableIds Ids of the documents the reader may read.
	 *
	 * @return array{key: string, label: string, present: bool, evidence: string|null}
	 */
	private function tick(array $item, array $case, array $documents, array $readableIds): array {
		$row = ['key' => $item['key'], 'label' => $item['label'], 'present' => false, 'evidence' => null];

		foreach ($documents as $document) {
			if ($this->documentAnswers(item: $item, document: $document) === false) {
				continue;
			}

			$row['present'] = true;
			if (in_array($this->documentId(document: $document), $readableIds, true) === true) {
				$row['evidence'] = $this->documentName(document: $document);
				return $row;
			}
		}

		if ($row['present'] === true || isset($item['field']) === false) {
			return $row;
		}

		$value = $this->fieldValue(case: $case, path: $item['field']);
		if ($this->isAnswered(value: $value) === true) {
			$row['present']  = true;
			$row['evidence'] = ($item['evidence'] ?? (is_scalar($value) === true ? (string)$value : null));
		}

		return $row;
	}//end tick()

	/**
	 * Whether a document answers an item.
	 *
	 * @param array<string, string> $item     The item.
	 * @param array<string, mixed>  $document The document.
	 *
	 * @return bool
	 */
	private function documentAnswers(array $item, array $document): bool {
		if (isset($item['documentType']) === true
			&& (string)($document['informatieobjecttype'] ?? '') === $item['documentType']
		) {
			return true;
		}

		if (isset($item['documentTitleContains']) === false) {
			return false;
		}

		$haystack = mb_strtolower((string)($document['titel'] ?? '') . ' ' . (string)($document['bestandsnaam'] ?? ''));

		return str_contains($haystack, mb_strtolower($item['documentTitleContains']));
	}//end documentAnswers()

	/**
	 * The id of a document.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return string
	 */
	private function documentId(array $document): string {
		return (string)($document['id'] ?? ($document['@self']['id'] ?? ($document['uuid'] ?? '')));
	}//end documentId()

	/**
	 * The name a reader recognises a document by: its file name, else its title.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return string
	 */
	private function documentName(array $document): string {
		$name = trim((string)($document['bestandsnaam'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return trim((string)($document['titel'] ?? ''));
	}//end documentName()

	/**
	 * A case value by dot path.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string               $path The dot path.
	 *
	 * @return mixed
	 */
	private function fieldValue(array $case, string $path): mixed {
		$value = $case;
		foreach (explode('.', $path) as $part) {
			if (is_array($value) === false || array_key_exists($part, $value) === false) {
				return null;
			}

			$value = $value[$part];
		}

		return $value;
	}//end fieldValue()

	/**
	 * Whether a value counts as an answer: not null, not blank, not an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isAnswered(mixed $value): bool {
		if ($value === null) {
			return false;
		}

		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		if (is_array($value) === true) {
			return $value !== [];
		}

		return true;
	}//end isAnswered()
}//end class
