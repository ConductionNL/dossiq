<?php

/**
 * The words a status shows the applicant, and the fallback when it has none.
 *
 * A `statusType` carries one name, and that name is written for the handler.
 * "Toets register B" is precise to a colleague and meaningless to the person
 * waiting for a decision. ZGW has had a second field for exactly this since
 * ZTC 1.0 (`statustype.statustekst`) and dossiq's mapping never carried it.
 *
 * So a status may now declare two more strings: `publicLabel` and
 * `publicDescription`. Both are optional, and the fallback is the whole
 * migration story: a status that declares neither reads to the applicant
 * exactly as it did before this change.
 *
 * 🔑 THE LABEL FALLS BACK, THE DESCRIPTION DOES NOT. An absent public label
 * means "the name is fine to show", which is true of Ontvangen and Afgehandeld
 * and most of the statuses anybody writes. An absent public description means
 * "nothing was written for the applicant", and `description` is NOT a stand-in
 * for it: that field holds what the phase means to a handler, and it routinely
 * names an internal check, an internal register or a colleague by role.
 * Falling back there would publish those words to a citizen, once, quietly, on
 * every case type that ever filled a description in.
 *
 * WHY THE CASE CARRIES THE ANSWER TOO. The two readers this exists for are
 * both handed a CASE and never a statusType. The public status page resolves a
 * token through OpenRegister's `case-tokens` endpoint, which renders the object
 * with `_extend: []`, so `status` arrives as a bare uuid. The portal projects a
 * case down to a field whitelist before a subject ever sees it. Neither can
 * follow the reference. That is why the case schema carries
 * `statusPublicLabel` and `statusPublicDescription` as declared calculations
 * over `@ref.statusType` (ADR-031), and why this class names those two fields
 * rather than letting three call sites spell them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * What a status says to the applicant.
 *
 * Pure functions over a row. No dependencies, so the portal contribution
 * provider (which must stay constructible with `new`, hydra ADR-046) can name
 * the case fields from here instead of keeping a second spelling of them.
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */
final class StatusPublicLabels {
	/**
	 * The case field carrying the label the applicant reads.
	 *
	 * Declared as a calculation over `@ref.statusType.publicLabel` on the case
	 * schema, so the fallback below has already been applied by the time a
	 * projected case carries it.
	 *
	 * @var string
	 */
	public const CASE_LABEL_FIELD = 'statusPublicLabel';

	/**
	 * The case field carrying the description the applicant reads.
	 *
	 * @var string
	 */
	public const CASE_DESCRIPTION_FIELD = 'statusPublicDescription';

	/**
	 * What the applicant reads for this status.
	 *
	 * @param array<string, mixed> $statusType The stored statusType row.
	 *
	 * @return string The public label, the name when there is none, or ''.
	 *
	 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
	 */
	public static function publicLabelOf(array $statusType): string {
		$label = self::textOf(value: ($statusType['publicLabel'] ?? null));
		if ($label !== '') {
			return $label;
		}

		return self::textOf(value: ($statusType['name'] ?? null));
	}//end publicLabelOf()

	/**
	 * What the applicant is told this status means.
	 *
	 * No fallback to `description`. See the class docblock: that string is
	 * written for a handler and publishing it to a citizen is a disclosure
	 * nobody chose.
	 *
	 * @param array<string, mixed> $statusType The stored statusType row.
	 *
	 * @return string The public description, or ''.
	 *
	 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
	 */
	public static function publicDescriptionOf(array $statusType): string {
		return self::textOf(value: ($statusType['publicDescription'] ?? null));
	}//end publicDescriptionOf()

	/**
	 * One translatable value as the text a reader sees.
	 *
	 * 🔴 `name`, `publicLabel` and `publicDescription` are declared
	 * `translatable` on the statusType schema, so OpenRegister STORES them as a
	 * language map (`{"nl": "Ontvangen"}`). Its API resolves the map to one
	 * string, but a row read through `ObjectService::searchObjects()` arrives
	 * unrendered, map and all. A `(string)` cast of that map is the literal
	 * "Array", which is how every step on a resident's case read "Array" and
	 * then folded into one, because eight labels of "Array" are equal.
	 *
	 * The reader's language first when one is given (`en_GB` also accepts
	 * `en`), then Dutch, the language these labels are written in, then the
	 * first non-empty text in the map.
	 *
	 * @param mixed  $value    A string, a language map, or anything else.
	 * @param string $language The reader's language code, or '' for none.
	 *
	 * @return string The text, trimmed, or '' when there is none.
	 *
	 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
	 * @spec openspec/changes/rebind-dialog-translated-labels/specs/zaaktype-versioning/spec.md
	 */
	public static function textOf(mixed $value, string $language = ''): string {
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

	/**
	 * The label a projected case already carries, if it carries one.
	 *
	 * The calculation resolves the fallback server-side, so this reads one
	 * field. It stays a method rather than an array access because a case
	 * projected to a whitelist that omits the field must read as "nothing to
	 * show" and not as the literal `null`.
	 *
	 * @param array<string, mixed> $case The case row, possibly field-projected.
	 *
	 * @return string The label, or ''.
	 *
	 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
	 */
	public static function labelOnCase(array $case): string {
		return trim((string)($case[self::CASE_LABEL_FIELD] ?? ''));
	}//end labelOnCase()

	/**
	 * The description a projected case already carries, if it carries one.
	 *
	 * @param array<string, mixed> $case The case row, possibly field-projected.
	 *
	 * @return string The description, or ''.
	 *
	 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
	 */
	public static function descriptionOnCase(array $case): string {
		return trim((string)($case[self::CASE_DESCRIPTION_FIELD] ?? ''));
	}//end descriptionOnCase()
}//end class
