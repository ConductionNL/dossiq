<?php

/**
 * Dossiq config lexicon test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workplace/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\ConfigLexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;
use PHPUnit\Framework\TestCase;

/**
 * Dossiq declares the words of the top bar's search field (board DqKop) as a
 * config lexicon default, translated to the board's Dutch, and leaves every
 * other key alone.
 */
class ConfigLexiconTest extends TestCase {

	/**
	 * The search field's words are declared, as a string with the board's text as default.
	 *
	 * @return void
	 */
	public function testDeclaresTheSearchFieldWords(): void {
		$entries = (new ConfigLexicon())->getAppConfigs();
		$this->assertCount(1, $entries);
		$this->assertSame('search_placeholder', $entries[0]->getKey());
		$this->assertSame(ValueType::STRING, $entries[0]->getValueType());
		$this->assertSame('Search cases, documents or contacts', ConfigLexicon::SEARCH_PLACEHOLDER);

		$nl = json_decode((string) file_get_contents(__DIR__.'/../../../l10n/nl.json'), true);
		$this->assertSame('Zoek zaken, documenten of contacten', $nl['translations'][ConfigLexicon::SEARCH_PLACEHOLDER]);
	}

	/**
	 * Every key the lexicon does not declare keeps working, unlogged.
	 *
	 * @return void
	 */
	public function testIgnoresUndeclaredKeys(): void {
		$lexicon = new ConfigLexicon();
		$this->assertSame(Strictness::IGNORE, $lexicon->getStrictness());
		$this->assertSame([], $lexicon->getUserConfigs());
	}

	/**
	 * The application registers the lexicon.
	 *
	 * @return void
	 */
	public function testApplicationRegistersIt(): void {
		$source = (string) file_get_contents(__DIR__.'/../../../lib/AppInfo/Application.php');
		$this->assertStringContainsString('registerConfigLexicon(ConfigLexicon::class)', $source);
	}
}
