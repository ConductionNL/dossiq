<?php

/**
 * A translatable register value as one string.
 *
 * Case type titles and status names are declared `translatable`, so a row read
 * through `ObjectService::searchObjects()` carries them as a language map
 * (`{"nl": "Kapvergunning"}`), not as a string. A `(string)` cast of that map
 * is the literal "Array". This class reads the map in a given language,
 * falling back to Dutch and then to the first text the map holds.
 *
 * An instance rather than a static helper, so a class that needs it receives
 * it through its constructor like any other collaborator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Resolves a language map to one string.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
class LanguageMapText {
	/**
	 * One value as text: a string as is, a language map in the given language.
	 *
	 * The given language first when there is one (`en_GB` also accepts
	 * `en`), then Dutch, the language these labels are written in, then the
	 * first non-empty text in the map. Without a language the answer is the
	 * same for every reader, which is what a merge key needs.
	 *
	 * @param mixed  $value    A string, a language map, or anything else.
	 * @param string $language The reader's language code, or '' for none.
	 *
	 * @return string The text, trimmed, or '' when there is none.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function textOf(mixed $value, string $language = ''): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === false) {
			return '';
		}

		$preferred = [];
		$base = strtolower(explode('_', str_replace('-', '_', $language))[0]);
		foreach ([$language, $base, 'nl'] as $code) {
			if ($code !== '' && array_key_exists($code, $value) === true) {
				$preferred[$code] = $value[$code];
			}
		}

		$candidates = ($preferred + $value);

		foreach ($candidates as $text) {
			if (is_string($text) === true && trim($text) !== '') {
				return trim($text);
			}
		}

		return '';
	}//end textOf()
}//end class
