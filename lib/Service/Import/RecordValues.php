<?php

/**
 * Dossiq record values
 *
 * How a value read from a source record is kept on a case: a list or object
 * as JSON text, a number from text, and an answer to a case type's field as
 * the `{propertyDefinition, name, value}` entry every answer on a case is,
 * its value as text.
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
 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

/**
 * Shapes record values the way a case stores them.
 *
 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
 */
class RecordValues {
	/**
	 * A list or object as JSON text, or null when it is empty.
	 *
	 * @param mixed $raw The raw value.
	 *
	 * @return string|null The JSON text.
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
	 */
	public function json(mixed $raw): ?string {
		if ($raw === null || $raw === '' || $raw === []) {
			return null;
		}

		$encoded = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			return null;
		}

		return $encoded;
	}//end json()

	/**
	 * A number read from text, or null when the text is not one.
	 *
	 * @param string $text The text.
	 *
	 * @return int|float|null The number.
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
	 */
	public function number(string $text): int|float|null {
		if (is_numeric($text) === false) {
			return null;
		}

		return $text + 0;
	}//end number()

	/**
	 * One answer to a case type's field, its value as text like every answer on a case.
	 *
	 * @param array<string, mixed> $property The `{definition, name}` the field answers.
	 * @param mixed                $value    The shaped value.
	 *
	 * @return array{propertyDefinition: string, name: string, value: string}
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-a-declaration-reads-nested-typed-source-values-and-answers-the-case-types-fields-req-cri-005
	 */
	public function answer(array $property, mixed $value): array {
		$text = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (is_bool($value) === true) {
			$text = 'false';
			if ($value === true) {
				$text = 'true';
			}
		} else if (is_scalar($value) === true) {
			$text = (string)$value;
		}

		return [
			'propertyDefinition' => (string)($property['definition'] ?? ''),
			'name' => (string)($property['name'] ?? ''),
			'value' => $text,
		];
	}//end answer()
}//end class
