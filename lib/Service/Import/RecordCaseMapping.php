<?php

/**
 * Dossiq record-to-case mapping
 *
 * One record another app stores, read through the mapping a case type
 * declares under `recordImports`: which source field fills which case field
 * (dot paths reach nested objects), how a value is read (text, date, moment,
 * number, a value list), which source status lands on which case status and
 * what that says about the term, and the source's own number kept as a former
 * reference. Pure: it answers the case payload and the term state, and writes
 * nothing. The procedure lives in the configuration, not here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-a-case-type-declares-how-another-apps-records-become-its-cases-req-cri-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

use InvalidArgumentException;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Term\TermCarryOver;

/**
 * Maps one source record onto a case, by a case type's import declaration.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-a-case-type-declares-how-another-apps-records-become-its-cases-req-cri-001
 */
class RecordCaseMapping {

	/**
	 * The `from` that reads the record's own uuid.
	 */
	public const RECORD_ID = '@id';

	/**
	 * The `statuses` key a record takes whose status is not declared or absent.
	 */
	public const ANY_STATUS = '*';

	/**
	 * The term states a status may declare.
	 */
	private const TERM_STATES = [
		TermCarryOver::STATE_RUNNING,
		TermCarryOver::STATE_SUSPENDED,
		TermCarryOver::STATE_CLOSED,
	];

	/**
	 * Puts a raw value in the shape its field declaration asks for.
	 *
	 * @var RecordFieldShape
	 */
	private readonly RecordFieldShape $shapes;

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates  The one reader of a date and the tenant's time zone.
	 * @param RecordValues       $values Lists, numbers and answers as the case stores them.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
		private readonly RecordValues $values = new RecordValues(),
	) {
		$this->shapes = new RecordFieldShape(dates: $this->dates, values: $this->values);
	}//end __construct()

	/**
	 * The case one record becomes.
	 *
	 * @param array<string, mixed> $import     One entry of the case type's `recordImports`.
	 * @param array<string, mixed> $record     The source record.
	 * @param string               $recordId   Its uuid.
	 * @param string               $caseTypeId The case type the case is of.
	 *
	 * @return array{case: array<string, mixed>, term: array<string, mixed>, result: string}
	 *
	 * @throws InvalidArgumentException When the record cannot become a case under this
	 *                                  declaration: a required field is empty, a value is
	 *                                  outside its list, or the status is not declared.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-a-case-type-declares-how-another-apps-records-become-its-cases-req-cri-001
	 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
	 */
	public function map(array $import, array $record, string $recordId, string $caseTypeId): array {
		$sourceStatus = self::sourceStatusOf(import: $import, record: $record);
		$declared = self::declaredFor(import: $import, sourceStatus: $sourceStatus);
		$termState = self::termStateOf(declared: $declared, sourceStatus: $sourceStatus);

		$run = new RecordMappingRun(record: $record, recordId: $recordId);
		$run->case = ['caseType' => $caseTypeId];
		$this->mapFields(run: $run, fields: (array)($import['fields'] ?? []));
		self::applyStatus(run: $run, declared: $declared);
		$this->applyFormerReferences(run: $run, declaration: (array)($import['formerReference'] ?? []));

		return [
			'case' => $run->case,
			'term' => ['state' => $termState] + $this->term(declaration: (array)($import['term'] ?? []), record: $record),
			'result' => trim((string)($declared['result'] ?? '')),
		];
	}//end map()

	/**
	 * The status the source record is in, read from the declared status field.
	 *
	 * @param array<string, mixed> $import One entry of the case type's `recordImports`.
	 * @param array<string, mixed> $record The source record.
	 *
	 * @return string The source status.
	 */
	private static function sourceStatusOf(array $import, array $record): string {
		$statusField = (string)($import['statusField'] ?? 'status');

		return trim((string)($record[$statusField] ?? ''));
	}//end sourceStatusOf()

	/**
	 * What the declaration says for a source status, or for any status.
	 *
	 * @param array<string, mixed> $import       One entry of the case type's `recordImports`.
	 * @param string               $sourceStatus The source status.
	 *
	 * @return array<string, mixed> The declared status.
	 *
	 * @throws InvalidArgumentException When the case type declares neither.
	 */
	private static function declaredFor(array $import, string $sourceStatus): array {
		$declared = ($import['statuses'][$sourceStatus] ?? ($import['statuses'][self::ANY_STATUS] ?? null));
		if (is_array($declared) === false) {
			throw new InvalidArgumentException('The record has a status the case type does not declare: ' . $sourceStatus . '.');
		}

		return $declared;
	}//end declaredFor()

	/**
	 * The term state a declared status carries.
	 *
	 * @param array<string, mixed> $declared     The declared status.
	 * @param string               $sourceStatus The source status, for the refusal.
	 *
	 * @return string The term state.
	 *
	 * @throws InvalidArgumentException When the state is not one of TERM_STATES.
	 */
	private static function termStateOf(array $declared, string $sourceStatus): string {
		$termState = (string)($declared['term'] ?? TermCarryOver::STATE_RUNNING);
		if (in_array($termState, self::TERM_STATES, true) === false) {
			throw new InvalidArgumentException('The status ' . $sourceStatus . ' declares an unknown term state: ' . $termState . '.');
		}

		return $termState;
	}//end termStateOf()

	/**
	 * Write every declared field the record carries onto the case.
	 *
	 * @param RecordMappingRun          $run    The mapping.
	 * @param array<int|string, mixed> $fields The field declarations.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a required value is empty or outside its list.
	 */
	private function mapFields(RecordMappingRun $run, array $fields): void {
		foreach ($fields as $field) {
			$this->mapField(run: $run, field: (array)$field);
		}
	}//end mapFields()

	/**
	 * Write one declared field onto the case; a typed property is added to the answers at its path.
	 *
	 * @param RecordMappingRun     $run   The mapping.
	 * @param array<string, mixed> $field The field declaration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a required value is empty or outside its list.
	 */
	private function mapField(RecordMappingRun $run, array $field): void {
		$value = $this->valueOf(field: $field, run: $run);
		if ($value === null) {
			return;
		}

		$path = (string)($field['to'] ?? '');
		if (is_array($field['property'] ?? null) === true) {
			$answers = $this->answersAt(run: $run, path: $path);
			$answers[] = $this->values->answer(property: $field['property'], value: $value);
			$value = $answers;
		}

		$run->case = $this->set(target: $run->case, path: $path, value: $value);
	}//end mapField()

	/**
	 * The answers already at a path of the case, as a list.
	 *
	 * @param RecordMappingRun $run  The mapping.
	 * @param string           $path The dot path.
	 *
	 * @return array<int|string, mixed> The answers, empty when there are none.
	 */
	private function answersAt(RecordMappingRun $run, string $path): array {
		return (array)($this->read(case: $run->case, path: $path) ?? []);
	}//end answersAt()

	/**
	 * Set the status the declaration maps the source status to, when it names one.
	 *
	 * @param RecordMappingRun     $run      The mapping.
	 * @param array<string, mixed> $declared The declared status.
	 *
	 * @return void
	 */
	private static function applyStatus(RecordMappingRun $run, array $declared): void {
		$status = trim((string)($declared['status'] ?? ''));
		if ($status !== '') {
			$run->case['status'] = $status;
		}
	}//end applyStatus()

	/**
	 * Keep the record's former references on the case, when it has any.
	 *
	 * @param RecordMappingRun     $run         The mapping.
	 * @param array<string, mixed> $declaration The former-reference declaration.
	 *
	 * @return void
	 */
	private function applyFormerReferences(RecordMappingRun $run, array $declaration): void {
		$former = $this->formerReferences(declaration: $declaration, record: $run->record);
		if ($former !== []) {
			$run->case['formerReferences'] = $former;
		}
	}//end applyFormerReferences()

	/**
	 * A value at a dot path of a case payload, or null.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param string               $path The dot path.
	 *
	 * @return mixed The value.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-a-case-type-declares-how-another-apps-records-become-its-cases-req-cri-001
	 */
	public function read(array $case, string $path): mixed {
		$value = $case;
		foreach (explode('.', $path) as $key) {
			if (is_array($value) === false || array_key_exists($key, $value) === false) {
				return null;
			}

			$value = $value[$key];
		}

		return $value;
	}//end read()

	/**
	 * The term state the source reports.
	 *
	 * @param array<string, mixed> $declaration Source field names: endDate, extensions, extensionReason, closedOn.
	 * @param array<string, mixed> $record      The source record.
	 *
	 * @return array{endDate: string, extensions: int, extensionReason: string, closedOn: string}
	 */
	private function term(array $declaration, array $record): array {
		$field = static fn (string $key): mixed => ($record[(string)($declaration[$key] ?? '')] ?? null);

		return [
			'endDate' => (string)$this->dates->toCalendarDateOrNull(value: $field('endDate')),
			'extensions' => max(0, (int)$field('extensions')),
			'extensionReason' => trim((string)$field('extensionReason')),
			'closedOn' => (string)$this->dates->toCalendarDateOrNull(value: $field('closedOn')),
		];
	}//end term()

	/**
	 * One declared field's value, read and shaped.
	 *
	 * @param array<string, mixed> $field The field declaration.
	 * @param RecordMappingRun     $run   The source record and its uuid.
	 *
	 * @return mixed The value, or null when there is nothing to write.
	 *
	 * @throws InvalidArgumentException When a required value is empty or outside its list.
	 */
	private function valueOf(array $field, RecordMappingRun $run): mixed {
		$record = $run->record;
		if (array_key_exists('value', $field) === true) {
			return $field['value'];
		}

		$from = (string)($field['from'] ?? '');
		$raw = ($record[$from] ?? $this->read(case: $record, path: $from));
		if ($from === self::RECORD_ID) {
			$raw = $run->recordId;
		}

		$value = $this->shapes->shaped(field: $field, raw: $raw);
		if ($value === null && ($field['required'] ?? false) === true) {
			throw new InvalidArgumentException('The record has no readable ' . $from . '.');
		}

		return $value;
	}//end valueOf()

	/**
	 * Set a value at a dot path.
	 *
	 * @param array<string, mixed> $target The payload.
	 * @param string               $path   The dot path.
	 * @param mixed                $value  The value.
	 *
	 * @return array<string, mixed> The payload with the value set.
	 */
	private function set(array $target, string $path, mixed $value): array {
		$keys = explode('.', $path);
		$head = array_shift($keys);
		if ($head === null || $head === '') {
			return $target;
		}

		if ($keys === []) {
			$target[$head] = $value;
			return $target;
		}

		$target[$head] = $this->set(target: (array)($target[$head] ?? []), path: implode('.', $keys), value: $value);

		return $target;
	}//end set()

	/**
	 * The source's own number, kept so the old number still finds the case.
	 *
	 * @param array<string, mixed> $declaration `{application, from}`.
	 * @param array<string, mixed> $record      The source record.
	 *
	 * @return array<int, array{application: string, reference: string}>
	 */
	private function formerReferences(array $declaration, array $record): array {
		$reference = trim((string)($record[(string)($declaration['from'] ?? '')] ?? ''));
		$application = trim((string)($declaration['application'] ?? ''));
		if ($reference === '' || $application === '') {
			return [];
		}

		return [['application' => $application, 'reference' => $reference]];
	}//end formerReferences()
}//end class
