<?php

/**
 * Dossiq config lexicon
 *
 * The app config keys dossiq declares with a default, so they read without
 * ever being written.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workplace/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * The declared app config keys. Every other dossiq key keeps working as
 * before: the lexicon ignores keys it does not declare.
 *
 * `search_placeholder` is the words of the top bar's search field on dossiq
 * pages (board DqKop), an English source string the theme (thematiq)
 * translates through dossiq's catalogue. An administrator can still set it.
 *
 * @spec openspec/specs/workplace/spec.md#requirement-dossiq-words-the-search-field
 */
class ConfigLexicon implements ILexicon {

	/**
	 * The words of the top bar's search field on a dossiq page.
	 *
	 * @var string
	 */
	public const SEARCH_PLACEHOLDER = 'Search cases, documents or contacts';

	/**
	 * Undeclared keys are neither refused nor logged.
	 *
	 * @return Strictness The strictness.
	 *
	 * @spec openspec/specs/workplace/spec.md#requirement-dossiq-words-the-search-field
	 */
	public function getStrictness(): Strictness {
		return Strictness::IGNORE;
	}//end getStrictness()

	/**
	 * The declared app config keys.
	 *
	 * @return Entry[] The entries.
	 *
	 * @spec openspec/specs/workplace/spec.md#requirement-dossiq-words-the-search-field
	 */
	public function getAppConfigs(): array {
		return [
			new Entry(
				key: 'search_placeholder',
				type: ValueType::STRING,
				defaultRaw: self::SEARCH_PLACEHOLDER,
				definition: 'Words of the top bar search field on dossiq pages, translated through dossiq\'s catalogue'
			),
		];
	}//end getAppConfigs()

	/**
	 * No user config keys are declared.
	 *
	 * @return Entry[] None.
	 *
	 * @spec openspec/specs/workplace/spec.md#requirement-dossiq-words-the-search-field
	 */
	public function getUserConfigs(): array {
		return [];
	}//end getUserConfigs()
}//end class
