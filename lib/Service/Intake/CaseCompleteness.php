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
 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Kernel\Contracts\CaseCompletenessInterface;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Support\FilledValue;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUser;

/**
 * Builds the completeness answer of one case.
 *
 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
 */
class CaseCompleteness implements CaseCompletenessInterface {

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore        $cases     Reads the case.
	 * @param CaseTypeResolver       $caseTypes Reads the effective case type.
	 * @param ZaakdossierService     $dossier   Reads the case's documents.
	 * @param InformatieobjectReader $reader    Which documents the reader may read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseStatusStore $cases,
		private readonly CaseTypeResolver $caseTypes,
		private readonly ZaakdossierService $dossier,
		private readonly InformatieobjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The completeness answer of one case for one reader, who must already be allowed to read it.
	 *
	 * @param string $caseId The case uuid.
	 * @param IUser  $user   The reader.
	 *
	 * @return array<string, mixed>|null The answer (see check()), or null when the case is not found.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function forCase(string $caseId, IUser $user): ?array {
		$case = $this->cases->loadCase(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		$items = $this->itemsOfCase(case: $case);
		if ($items === []) {
			return ['items' => []];
		}

		$documents = ($this->dossier->getDossierForCase(caseId: $caseId)['informatieobjecten'] ?? []);

		return $this->check(
			items: $items,
			case: $case,
			documents: $documents,
			readable: $this->reader->filterForUser(user: $user, informatieobjecten: $documents)
		);
	}//end forCase()

	/**
	 * The declared items of a case type, each with a key and a label.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, array<string, string>> The items, malformed ones left out.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function itemsOf(array $caseType): array {
		$declared = ($caseType[IntakeRequirements::PROPERTY]['completenessItems'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$items = [];
		foreach ($declared as $item) {
			$clean = $this->cleanItem(item: $item);
			if ($clean !== null) {
				$items[] = $clean;
			}
		}

		return $items;
	}//end itemsOf()

	/**
	 * The declared items of the effective case type of a case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function itemsOfCase(array $case): array {
		return $this->itemsOf(caseType: $this->caseTypes->effectiveCaseType(caseTypeId: $this->caseTypeIdOf(case: $case)));
	}//end itemsOfCase()

	/**
	 * The case type id of a case, whether stored flat or as a nested object.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string
	 */
	private function caseTypeIdOf(array $case): string {
		$caseTypeId = ($case['caseType'] ?? '');
		if (is_array($caseTypeId) === true) {
			$caseTypeId = ($caseTypeId['id'] ?? '');
		}

		return (string)$caseTypeId;
	}//end caseTypeIdOf()

	/**
	 * One declared item with a trimmed key and label and its usable options, or null when malformed.
	 *
	 * @param mixed $item The declared entry.
	 *
	 * @return array<string, string>|null
	 */
	private function cleanItem(mixed $item): ?array {
		if (is_array($item) === false) {
			return null;
		}

		$key   = trim((string)($item['key'] ?? ''));
		$label = trim((string)($item['label'] ?? ''));
		if ($key === '' || $label === '') {
			return null;
		}

		return array_merge(['key' => $key, 'label' => $label], $this->itemOptions(item: $item));
	}//end cleanItem()

	/**
	 * The non-blank string options of a declared item, trimmed, in their fixed order.
	 *
	 * @param array<string, mixed> $item The declared item.
	 *
	 * @return array<string, string>
	 */
	private function itemOptions(array $item): array {
		$options = [];
		foreach (['documentType', 'documentTitleContains', 'field', 'evidence'] as $option) {
			if (is_string($item[$option] ?? null) === true && trim($item[$option]) !== '') {
				$options[$option] = trim($item[$option]);
			}
		}

		return $options;
	}//end itemOptions()

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
	 * @spec openspec/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function check(array $items, array $case, array $documents, array $readable): array {
		if ($items === []) {
			return ['items' => []];
		}

		$readableIds = $this->documentIds(documents: $readable);

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
			'verdict' => $this->verdictOf(present: $present, total: $total),
		];
	}//end check()

	/**
	 * The ids of documents.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents.
	 *
	 * @return array<int, string>
	 */
	private function documentIds(array $documents): array {
		$ids = [];
		foreach ($documents as $document) {
			$ids[] = $this->documentId(document: $document);
		}

		return $ids;
	}//end documentIds()

	/**
	 * The verdict: complete when every item is present.
	 *
	 * @param int $present How many items are present.
	 * @param int $total   How many items there are.
	 *
	 * @return string `complete` or `incomplete`.
	 */
	private function verdictOf(int $present, int $total): string {
		if ($present === $total) {
			return 'complete';
		}

		return 'incomplete';
	}//end verdictOf()

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
		$answering = $this->answeringDocuments(item: $item, documents: $documents);
		if ($answering !== []) {
			$row = $this->emptyRow(item: $item);
			$row['present']  = true;
			$row['evidence'] = $this->readableName(answering: $answering, readableIds: $readableIds);
			return $row;
		}

		return $this->fieldRow(item: $item, case: $case);
	}//end tick()

	/**
	 * The row of an item that nothing answers yet.
	 *
	 * @param array<string, string> $item The item.
	 *
	 * @return array{key: string, label: string, present: bool, evidence: string|null}
	 */
	private function emptyRow(array $item): array {
		return ['key' => $item['key'], 'label' => $item['label'], 'present' => false, 'evidence' => null];
	}//end emptyRow()

	/**
	 * The documents that answer an item, in their order.
	 *
	 * @param array<string, string>            $item      The item.
	 * @param array<int, array<string, mixed>> $documents Every document of the case.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function answeringDocuments(array $item, array $documents): array {
		$answering = [];
		foreach ($documents as $document) {
			if ($this->documentAnswers(item: $item, document: $document) === true) {
				$answering[] = $document;
			}
		}

		return $answering;
	}//end answeringDocuments()

	/**
	 * The name of the first answering document the reader may read, or null when none is readable.
	 *
	 * @param array<int, array<string, mixed>> $answering   The answering documents.
	 * @param array<int, string>               $readableIds Ids of the documents the reader may read.
	 *
	 * @return string|null
	 */
	private function readableName(array $answering, array $readableIds): ?string {
		foreach ($answering as $document) {
			if (in_array($this->documentId(document: $document), $readableIds, true) === true) {
				return $this->documentName(document: $document);
			}
		}

		return null;
	}//end readableName()

	/**
	 * An item ticked against its case field: present when the field carries a value.
	 *
	 * @param array<string, string> $item The item.
	 * @param array<string, mixed>  $case The case.
	 *
	 * @return array{key: string, label: string, present: bool, evidence: string|null}
	 */
	private function fieldRow(array $item, array $case): array {
		$row = $this->emptyRow(item: $item);
		if (isset($item['field']) === false) {
			return $row;
		}

		$value = $this->fieldValue(case: $case, path: $item['field']);
		if ($this->isAnswered(value: $value) === true) {
			$row['present']  = true;
			$row['evidence'] = ($item['evidence'] ?? $this->scalarText(value: $value));
		}

		return $row;
	}//end fieldRow()

	/**
	 * A scalar value as text, or null for a list or object.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function scalarText(mixed $value): ?string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return null;
	}//end scalarText()

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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FilledValue is the one stateless filled-in check shared with derived status.
	 */
	private function isAnswered(mixed $value): bool {
		if ($value === null) {
			return false;
		}

		return FilledValue::isFilled(value: $value);
	}//end isAnswered()
}//end class
