<?php

/**
 * Dossiq RegisterValues.
 *
 * The coercions the register response mappers (BRK, WOZ) apply to loose
 * JSON values: a non-empty string or null, an int or null, a list of
 * strings, and a list of fragments mapped one by one. Pure functions, no I/O.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External;

/**
 * Coerces raw register values into the typed values of a DTO. A mapper
 * builds one per fragment it maps; it holds no state.
 *
 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
 */
final class RegisterValues {
	/**
	 * Coerce a value to a non-empty string, or null.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
	 */
	public function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * Coerce a value to an int, or null when absent/non-numeric.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
	 */
	public function intOrNull(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_float($value) === true) {
			return (int)$value;
		}

		if (is_string($value) === true && $value !== '' && is_numeric($value) === true) {
			return (int)$value;
		}

		return null;
	}//end intOrNull()

	/**
	 * Coerce a value into a string array — a single string becomes a
	 * one-element array, an array is filtered to strings, anything else
	 * becomes an empty array.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
	 */
	public function toStringArray(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $item) {
			if (is_string($item) === true && $item !== '') {
				$out[] = $item;
			}
		}

		return $out;
	}//end toStringArray()

	/**
	 * Map every fragment of a list, in order, skipping what is not a fragment.
	 *
	 * @param array<int,mixed>                                    $rawList Decoded JSON fragments.
	 * @param callable(array<string,mixed>): array<string,mixed> $map     Maps one fragment.
	 *
	 * @return array<int,array<string,mixed>> Normalized DTOs, same order.
	 *
	 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
	 */
	public function mapFragments(array $rawList, callable $map): array {
		$out = [];
		foreach ($rawList as $item) {
			if (is_array($item) === true) {
				$out[] = $map($item);
			}
		}

		return $out;
	}//end mapFragments()
}//end class
