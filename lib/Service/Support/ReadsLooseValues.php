<?php

/**
 * Reading loosely typed values as the plain types a service works with.
 *
 * Configuration, stored objects and tool arguments hand values over as
 * `mixed`. Services read them the same few ways: an array or an empty list,
 * a trimmed string or an empty one, a trimmed string or a default, and an
 * empty string as null. This trait gives those readings once.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Loose values read as arrays, trimmed strings or null.
 *
 * Using classes alias the step they need to their own name.
 *
 * @spec exclude shared value reading; each using class carries its own spec
 */
trait ReadsLooseValues {

	/**
	 * The value when it is an array, otherwise an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array The array, or [].
	 */
	private function looseList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end looseList()

	/**
	 * The value trimmed when it is a string, otherwise ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The trimmed string, or ''.
	 */
	private function looseText(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end looseText()

	/**
	 * The value cast and trimmed when it is a scalar, otherwise ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The trimmed text, or ''.
	 */
	private static function looseScalarText(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end looseScalarText()

	/**
	 * The trimmed string under a key of a configuration block, or ''.
	 *
	 * @param array<string, mixed> $config The configuration block.
	 * @param string               $key    The key.
	 *
	 * @return string The trimmed string, or '' when absent or not a string.
	 */
	private static function looseConfigText(array $config, string $key): string {
		$value = ($config[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end looseConfigText()

	/**
	 * The value trimmed when it is a non-blank string, otherwise the default.
	 *
	 * @param mixed  $value   The value.
	 * @param string $default The answer for anything else.
	 *
	 * @return string The trimmed string, or the default.
	 */
	private static function looseTextOr(mixed $value, string $default): string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return $default;
	}//end looseTextOr()

	/**
	 * The string, or null when it is blank.
	 *
	 * @param string $value The string.
	 *
	 * @return string|null The string unchanged, or null.
	 */
	private function blankAsNull(string $value): ?string {
		if (trim($value) === '') {
			return null;
		}

		return $value;
	}//end blankAsNull()
}//end trait
