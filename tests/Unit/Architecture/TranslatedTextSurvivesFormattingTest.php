<?php

/**
 * A text translated without parameters survives Nextcloud's formatting.
 *
 * Nextcloud runs every translated text through vsprintf(), also when the call
 * passes no parameters. A bare `%` in such a text ("SLA Compliance %", "Within
 * the norm (%)") then throws, and the whole response fails: DqDoorlooptijd's
 * Monthly SLA trend answered "Report generation failed" and its CSV download
 * broke. A literal percent sign is written `%%`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/translated-percent-sign/spec.md#requirement-a-text-translated-without-parameters-survives-formatting
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Every `->t('...')` call in lib/ without parameters, in English and Dutch.
 */
class TranslatedTextSurvivesFormattingTest extends TestCase {
	/**
	 * Each text lib/ translates without parameters, by the file that does it.
	 *
	 * @return array<string, string> Text to the first file that translates it.
	 */
	private function textsWithoutParameters(): array {
		$root  = dirname(__DIR__, 3) . '/lib';
		$texts = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			preg_match_all("/->t\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $source, $matches);
			foreach ($matches[1] as $text) {
				$texts[stripslashes($text)] ??= substr($file->getPathname(), strlen($root) + 1);
			}
		}

		return $texts;
	}//end textsWithoutParameters()

	/**
	 * Whether vsprintf() formats a text without parameters.
	 *
	 * @param string $text The text.
	 *
	 * @return bool True when it formats.
	 */
	private function formats(string $text): bool {
		try {
			$formatted = vsprintf($text, []);
		} catch (Throwable) {
			return false;
		}

		return ($formatted !== '' || $text === '');
	}//end formats()

	/**
	 * The scan finds the texts at all, so an empty match cannot pass.
	 *
	 * @return void
	 */
	public function testTheScanFindsTexts(): void {
		self::assertGreaterThan(expected: 100, actual: count($this->textsWithoutParameters()));
		self::assertFalse(condition: $this->formats(text: 'SLA Compliance %'), message: 'A bare percent sign must fail the check.');
	}//end testTheScanFindsTexts()

	/**
	 * No source text and no Dutch translation of one breaks vsprintf().
	 *
	 * @return void
	 */
	public function testEveryTextFormats(): void {
		$raw = file_get_contents(dirname(__DIR__, 3) . '/l10n/nl.json');
		self::assertIsString(actual: $raw);
		$dutch = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['translations'];

		$broken = [];
		foreach ($this->textsWithoutParameters() as $text => $file) {
			if ($this->formats(text: $text) === false) {
				$broken[] = $file . ': ' . $text;
			}

			$translation = ($dutch[$text] ?? null);
			if (is_string($translation) === true && $this->formats(text: $translation) === false) {
				$broken[] = $file . ' (nl): ' . $translation;
			}
		}

		self::assertSame(expected: [], actual: $broken, message: 'Write a literal percent sign as %% in a text translated without parameters.');
	}//end testEveryTextFormats()
}//end class
