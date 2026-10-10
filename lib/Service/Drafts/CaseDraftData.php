<?php

/**
 * Dossiq case draft data
 *
 * The render data of a document drafted from a case type's template
 * (case-document-drafts, REQ-CDD-002), built from the case type's declaration
 * and nothing else: `fields` read the case by dot path, `lists` gather the
 * case's related records (by the app setting naming their schema and the
 * field holding the case) into rows with their own fields and a stable
 * sequence number, and `summaries` reduce one list field to one answer (the
 * shared value when every row agrees, `empty` with no rows, `mixed`
 * otherwise). A field maps raw values through `values`; a value it does not
 * list is refused, so a template never renders a code it cannot explain.
 *
 * Pure apart from the related-record read, which goes through the callable
 * the caller hands in.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Drafts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-data-is-built-from-the-declaration-req-cdd-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Drafts;

use InvalidArgumentException;

/**
 * Builds the data a drafted document renders.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-data-is-built-from-the-declaration-req-cdd-002
 */
class CaseDraftData {

	/**
	 * The fixed value that answers the day the draft is made, as Y-m-d.
	 */
	public const TODAY = '@today';

	/**
	 * Build the declared data over a case.
	 *
	 * @param array<string, mixed> $declaration The draft declaration.
	 * @param array<string, mixed> $case        The case row.
	 * @param callable             $related     Reads related records: (schemaKey, caseField) => list of rows.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @throws InvalidArgumentException When a field meets a value its `values` map does not list,
	 *                                  or a required field is empty.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-data-is-built-from-the-declaration-req-cdd-002
	 */
	public function build(array $declaration, array $case, callable $related): array {
		$data = $this->fields(fields: (array)($declaration['fields'] ?? []), row: $case);
		foreach ((array)($declaration['lists'] ?? []) as $list) {
			$list = (array)$list;
			$rows = $related((string)($list['schemaKey'] ?? ''), (string)($list['caseField'] ?? 'case'));
			$data = $this->set(target: $data, path: (string)($list['to'] ?? 'items'), value: $this->rows(list: $list, rows: $rows));
		}

		foreach ((array)($declaration['summaries'] ?? []) as $summary) {
			$summary = (array)$summary;
			$rows = (array)($this->read(row: $data, path: (string)($summary['over'] ?? '')) ?? []);
			$data = $this->set(target: $data, path: (string)($summary['to'] ?? ''), value: $this->summarise(summary: $summary, rows: $rows));
		}

		return $data;
	}//end build()

	/**
	 * A value at a dot path, or null.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param string               $path The dot path.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-document-drafts/spec.md#requirement-the-draft-data-is-built-from-the-declaration-req-cdd-002
	 */
	public function read(array $row, string $path): mixed {
		$cursor = $row;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return null;
			}

			$cursor = $cursor[$segment];
		}

		return $cursor;
	}//end read()

	/**
	 * The declared fields read off one row.
	 *
	 * @param array<int, mixed>    $fields The field declarations.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(array $fields, array $row): array {
		$out = [];
		foreach ($fields as $field) {
			$field = (array)$field;
			$to = (string)($field['to'] ?? '');
			if ($to !== '') {
				$out = $this->set(target: $out, path: $to, value: $this->valueOf(field: $field, row: $row));
			}
		}

		return $out;
	}//end fields()

	/**
	 * One field's value: the constant, or the row's value mapped through `values`.
	 *
	 * @param array<string, mixed> $field The declaration.
	 * @param array<string, mixed> $row   The row.
	 *
	 * @return mixed
	 *
	 * @throws InvalidArgumentException On an unlisted value or an empty required field.
	 */
	private function valueOf(array $field, array $row): mixed {
		$value = $field['value'] ?? $this->read(row: $row, path: (string)($field['from'] ?? ''));
		if ($value === self::TODAY) {
			$value = gmdate('Y-m-d');
		}

		if ($this->isEmpty(value: $value) === true) {
			$value = $field['default'] ?? $value;
		}

		if (($field['required'] ?? false) === true && $this->isEmpty(value: $value) === true) {
			throw new InvalidArgumentException('draft-field-empty:' . (string)($field['to'] ?? ''));
		}

		if (is_array($field['values'] ?? null) === false || is_scalar($value) === false) {
			return $value;
		}

		if (array_key_exists((string)$value, $field['values']) === false) {
			throw new InvalidArgumentException('draft-value-unlisted:' . (string)($field['to'] ?? '') . '=' . (string)$value);
		}

		return $field['values'][(string)$value];
	}//end valueOf()

	/**
	 * Whether a value counts as empty: null, '' or [].
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isEmpty(mixed $value): bool {
		return in_array($value, [null, '', []], true);
	}//end isEmpty()

	/**
	 * The rows of one list, sorted by `sortBy` and numbered from 1 into `numberAs`.
	 *
	 * @param array<string, mixed>       $list The list declaration.
	 * @param list<array<string, mixed>> $rows The related records.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $list, array $rows): array {
		$sortBy = (string)($list['sortBy'] ?? 'id');
		usort(
			$rows,
			fn (array $left, array $right): int => strcmp((string)$this->read(row: $left, path: $sortBy), (string)$this->read(row: $right, path: $sortBy))
		);

		$numberAs = (string)($list['numberAs'] ?? '');
		$out = [];
		foreach ($rows as $index => $row) {
			$item = $this->fields(fields: (array)($list['fields'] ?? []), row: $row);
			if ($numberAs !== '') {
				$item[$numberAs] = ($index + 1);
			}

			$out[] = $item;
		}

		return $out;
	}//end rows()

	/**
	 * One list field reduced to one answer.
	 *
	 * @param array<string, mixed> $summary The summary declaration: `field`, `all`, `empty`, `mixed`.
	 * @param array<int, mixed>    $rows    The list rows.
	 *
	 * @return mixed
	 */
	private function summarise(array $summary, array $rows): mixed {
		if ($rows === []) {
			return $summary['empty'] ?? null;
		}

		$seen = [];
		foreach ($rows as $row) {
			$seen[(string)$this->read(row: (array)$row, path: (string)($summary['field'] ?? ''))] = true;
		}

		$all = (array)($summary['all'] ?? []);
		$only = (string)array_key_first($seen);
		if (count($seen) === 1 && array_key_exists($only, $all) === true) {
			return $all[$only];
		}

		return $summary['mixed'] ?? null;
	}//end summarise()

	/**
	 * Set a value at a dot path.
	 *
	 * @param array<string, mixed> $target The array.
	 * @param string               $path   The dot path.
	 * @param mixed                $value  The value.
	 *
	 * @return array<string, mixed>
	 */
	private function set(array $target, string $path, mixed $value): array {
		$segments = explode('.', $path);
		$head = array_shift($segments);
		if ($segments === []) {
			$target[$head] = $value;
			return $target;
		}

		$target[$head] = $this->set(target: (array)($target[$head] ?? []), path: implode('.', $segments), value: $value);

		return $target;
	}//end set()
}//end class
