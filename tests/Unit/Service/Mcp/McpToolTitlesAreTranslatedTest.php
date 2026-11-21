<?php

/**
 * Every MCP tool title dossiq declares has a Dutch translation in its catalogue.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * OpenRegister shows a tool's `#[McpToolTitle]` through the declaring app's IL10N.
 *
 * Nextcloud's extraction only reads literal `t()` calls, so a title is in the
 * catalogue only when somebody put it there. This test is what puts it there.
 *
 * @coversNothing
 */
class McpToolTitlesAreTranslatedTest extends TestCase {

	/**
	 * Every declared tool title, as `file => title`.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function titles(): array {
		$lib = dirname(__DIR__, 4) . '/lib';
		$titles = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			preg_match_all("/#\[McpToolTitle\(\s*'((?:[^'\\\\]|\\\\.)*)'/", (string)file_get_contents($file->getPathname()), $found);
			foreach ($found[1] as $title) {
				$titles[substr($file->getPathname(), strlen($lib) + 1) . ': ' . $title] = [stripslashes($title)];
			}
		}

		return $titles;
	}//end titles()

	/**
	 * At least one title is found, so the provider below is never vacuous.
	 *
	 * @return void
	 */
	public function testTheScanFindsTheDeclaredTitles(): void {
		self::assertNotSame([], self::titles());
	}//end testTheScanFindsTheDeclaredTitles()

	/**
	 * The title is a key of both catalogues, with a Dutch value of its own.
	 *
	 * @param string $title The declared title.
	 *
	 * @return void
	 *
	 * @dataProvider titles
	 */
	public function testTheTitleHasADutchTranslation(string $title): void {
		$en = $this->catalogue(locale: 'en');
		$nl = $this->catalogue(locale: 'nl');

		self::assertArrayHasKey($title, $en, 'l10n/en.json lacks the MCP tool title "' . $title . '".');
		self::assertArrayHasKey($title, $nl, 'l10n/nl.json lacks the MCP tool title "' . $title . '".');
		self::assertNotSame('', trim((string)$nl[$title]));
		self::assertNotSame($en[$title], $nl[$title], 'The Dutch value of "' . $title . '" is the English one.');
	}//end testTheTitleHasADutchTranslation()

	/**
	 * One locale's translations.
	 *
	 * @param string $locale The locale.
	 *
	 * @return array<string, string> Translation per key.
	 */
	private function catalogue(string $locale): array {
		$path = dirname(__DIR__, 4) . '/l10n/' . $locale . '.json';
		$decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		return (array)($decoded['translations'] ?? []);
	}//end catalogue()
}//end class
