<?php

/**
 * A translatable register value as the text the current reader sees.
 *
 * Case type titles and status names are declared `translatable`, so a row read
 * through `ObjectService::searchObjects()` carries them as a language map
 * (`{"nl": "Kapvergunning"}`), not as a string. A `(string)` cast of that map
 * is the literal "Array", which is what every option of the change case type
 * dialog read. This class resolves the map to the reader's language, falling
 * back to Dutch and then to the first text the map holds.
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
 * @spec openspec/changes/rebind-dialog-translated-labels/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Service\Transitions\StatusPublicLabels;
use OCP\IL10N;

/**
 * Resolves a language map to one string in the reader's language.
 *
 * @spec openspec/changes/rebind-dialog-translated-labels/specs/zaaktype-versioning/spec.md
 */
class TranslatedText {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The app's translator, which knows the reader's language.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * One value as text: a string as is, a language map in the reader's language.
	 *
	 * @param mixed $value A string, a language map, or anything else.
	 *
	 * @return string The text, trimmed, or '' when there is none.
	 *
	 * @spec openspec/changes/rebind-dialog-translated-labels/specs/zaaktype-versioning/spec.md
	 */
	public function of(mixed $value): string {
		return StatusPublicLabels::textOf(value: $value, language: $this->l10n->getLanguageCode());
	}//end of()
}//end class
