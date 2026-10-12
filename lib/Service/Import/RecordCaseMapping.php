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

use DateTimeInterface;
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
	 * The term states a status may declare.
	 */
	private const TERM_STATES = [
		TermCarryOver::STATE_RUNNING,
		TermCarryOver::STATE_SUSPENDED,
		TermCarryOver::STATE_CLOSED,
	];

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one reader of a date and the tenant's time zone.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
	) {
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
	 */
	public function map(array $import, array $record, string $recordId, string $caseTypeId): array {
		$statusField = (string)($import['statusField'] ?? 'status');
		$sourceStatus = trim((string)($record[$statusField] ?? ''));
		$declared = ($import['statuses'][$sourceStatus] ?? null);
		if (is_array($declared) === false) {
			throw new InvalidArgumentException('The record has a status the case type does not declare: ' . $sourceStatus . '.');
		}

		$termState = (string)($declared['term'] ?? TermCarryOver::STATE_RUNNING);
		if (in_array($termState, self::TERM_STATES, true) === false) {
			throw new InvalidArgumentException('The status ' . $sourceStatus . ' declares an unknown term state: ' . $termState . '.');
		}

		$case = ['caseType' => $caseTypeId];
		foreach ((array)($import['fields'] ?? []) as $field) {
			$value = $this->valueOf(field: (array)$field, record: $record, recordId: $recordId);
			if ($value !== null) {
				$case = $this->set(target: $case, path: (string)($field['to'] ?? ''), value: $value);
			}
		}

		$status = trim((string)($declared['status'] ?? ''));
		if ($status !== '') {
			$case['status'] = $status;
		}

		$former = $this->formerReferences(declaration: (array)($import['formerReference'] ?? []), record: $record);
		if ($former !== []) {
			$case['formerReferences'] = $former;
		}

		return [
			'case' => $case,
			'term' => $this->term(declaration: (array)($import['term'] ?? []), record: $record, state: $termState),
			'result' => trim((string)($declared['result'] ?? '')),
		];
	}//end map()

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
	 * @param string               $state       The state the status declares.
	 *
	 * @return array{state: string, endDate: string, extensions: int, extensionReason: string, closedOn: string}
	 */
	private function term(array $declaration, array $record, string $state): array {
		$field = static fn (string $key): mixed => ($record[(string)($declaration[$key] ?? '')] ?? null);

		return [
			'state' => $state,
			'endDate' => (string)$this->dates->toCalendarDateOrNull(value: $field('endDate')),
			'extensions' => max(0, (int)$field('extensions')),
			'extensionReason' => trim((string)$field('extensionReason')),
			'closedOn' => (string)$this->dates->toCalendarDateOrNull(value: $field('closedOn')),
		];
	}//end term()

	/**
	 * One declared field's value, read and shaped.
	 *
	 * @param array<string, mixed> $field    The field declaration.
	 * @param array<string, mixed> $record   The source record.
	 * @param string               $recordId The record's uuid.
	 *
	 * @return mixed The value, or null when there is nothing to write.
	 *
	 * @throws InvalidArgumentException When a required value is empty or outside its list.
	 */
	private function valueOf(array $field, array $record, string $recordId): mixed {
		if (array_key_exists('value', $field) === true) {
			return $field['value'];
		}

		$from = (string)($field['from'] ?? '');
		$raw = ($record[$from] ?? null);
		if ($from === self::RECORD_ID) {
			$raw = $recordId;
		}

		$value = $this->shaped(field: $field, raw: $raw);
		if ($value === null && ($field['required'] ?? false) === true) {
			throw new InvalidArgumentException('The record has no readable ' . $from . '.');
		}

		return $value;
	}//end valueOf()

	/**
	 * A raw value in the shape its declaration asks for.
	 *
	 * @param array<string, mixed> $field The field declaration.
	 * @param mixed                $raw   The raw value.
	 *
	 * @return mixed The value, or null when empty or unreadable.
	 *
	 * @throws InvalidArgumentException When a value is outside its declared list.
	 */
	private function shaped(array $field, mixed $raw): mixed {
		$text = '';
		if (is_scalar($raw) === true) {
			$text = trim((string)preg_replace('/\s+/u', ' ', (string)$raw));
		}

		if (isset($field['values']) === true) {
			return $this->listed(field: $field, text: $text);
		}

		if ($text === '') {
			return null;
		}

		return match ((string)($field['as'] ?? 'text')) {
			'date' => $this->dates->toCalendarDateOrNull(value: $text),
			'dateTime' => $this->dates->tryParse(value: $text)?->format(DateTimeInterface::ATOM),
			'integer' => (int)$text,
			default => $this->cut(text: $text, length: (int)($field['maxLength'] ?? 0)),
		};
	}//end shaped()

	/**
	 * A value read through its declared list.
	 *
	 * @param array<string, mixed> $field The field declaration, with `values` and an optional `default`.
	 * @param string               $text  The raw value as text.
	 *
	 * @return mixed The listed value, or the default for an empty one.
	 *
	 * @throws InvalidArgumentException When the value is not in the list.
	 */
	private function listed(array $field, string $text): mixed {
		$values = (array)$field['values'];
		if ($text === '') {
			return ($field['default'] ?? null);
		}

		if (array_key_exists($text, $values) === false) {
			throw new InvalidArgumentException(
				'The ' . (string)($field['from'] ?? '') . ' must be one of: ' . implode(', ', array_keys($values)) . '.'
			);
		}

		return $values[$text];
	}//end listed()

	/**
	 * Text cut to a length at the last word that fits.
	 *
	 * @param string $text   The text.
	 * @param int    $length The length, or 0 for no limit.
	 *
	 * @return string The text.
	 */
	private function cut(string $text, int $length): string {
		if ($length <= 0 || mb_strlen($text) <= $length) {
			return $text;
		}

		$head = mb_substr($text, 0, $length + 1);
		$space = mb_strrpos($head, ' ');
		if ($space === false || $space === 0) {
			return mb_substr($text, 0, $length);
		}

		return rtrim(mb_substr($head, 0, $space));
	}//end cut()

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
