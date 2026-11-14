<?php

/**
 * Dossiq informatieobject metadata normaliser.
 *
 * Coerces the two properties a person types freely — `keywords` and
 * `direction` — onto what the register schema declares, before either reaches
 * OpenRegister. Both are labels on a document, so a value that would fail
 * validation is CORRECTED here rather than refused: a rejected save on an
 * upload throws the uploaded file away with it, and the person sees a failed
 * upload rather than a shortened tag.
 *
 * Split out of {@see \OCA\Dossiq\Service\ZaakdossierService} so that service
 * keeps the dossier as a whole — upload, join, grouping, status — while the
 * per-field coercion lives in one testable place.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

/**
 * Coercion of `informatieobject.keywords` and `informatieobject.direction`.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
class InformatieobjectMetadataNormaliser {

	/**
	 * The values `informatieobject.direction` accepts.
	 *
	 * Mirrors the enum in lib/Settings/register.d/70-document-zaakdossier.json.
	 *
	 * @var string[]
	 */
	public const DIRECTIONS = ['incoming', 'outgoing', 'internal'];

	/**
	 * The direction a document carries when nobody chose one.
	 *
	 * @var string
	 */
	public const DEFAULT_DIRECTION = 'internal';

	/**
	 * The longest a single keyword may be, per the schema's items.maxLength.
	 *
	 * @var int
	 */
	public const KEYWORD_MAX_LENGTH = 64;

	/**
	 * Coerce a submitted direction onto the schema's enum.
	 *
	 * @param mixed $value The submitted direction.
	 *
	 * @return string One of self::DIRECTIONS.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 */
	public function direction(mixed $value): string {
		$direction = '';
		if (is_string($value) === true) {
			$direction = trim($value);
		}

		if (in_array($direction, self::DIRECTIONS, true) === true) {
			return $direction;
		}

		return self::DEFAULT_DIRECTION;
	}//end direction()

	/**
	 * Coerce submitted keywords onto the schema's `array of string`.
	 *
	 * Trims, drops blanks and non-scalars, deduplicates, and truncates each
	 * keyword to the schema's maxLength.
	 *
	 * @param mixed $value The submitted keywords.
	 *
	 * @return string[] The keywords to store, possibly empty.
	 *
	 * @spec openspec/specs/document-zaakdossier/spec.md
	 */
	public function keywords(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$keywords = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false && is_numeric($entry) === false) {
				continue;
			}

			$keyword = mb_substr(trim((string)$entry), 0, self::KEYWORD_MAX_LENGTH);
			if ($keyword !== '' && in_array($keyword, $keywords, true) === false) {
				$keywords[] = $keyword;
			}
		}

		return $keywords;
	}//end keywords()
}//end class
