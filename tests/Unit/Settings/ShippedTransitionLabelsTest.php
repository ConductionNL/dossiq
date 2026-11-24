<?php

/**
 * Every shipped transition label reads in Dutch and in English
 *
 * A transition's label is what a handler reads on Next step, so a label the
 * packages, templates and example sets ship only in Dutch reaches an English
 * reader untranslated. Each one is a language map with both.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The transition labels in lib/Settings.
 */
class ShippedTransitionLabelsTest extends TestCase {
	private const DIR = __DIR__ . '/../../../lib/Settings';

	/**
	 * Every transition label in a shipped file, with the file it is in.
	 *
	 * @return list<array{0: string, 1: mixed}> The file and the label.
	 */
	private static function labels(): array {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DIR));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'json') {
				continue;
			}

			$data = json_decode((string)file_get_contents($file->getPathname()), true);
			self::collect(value: $data, file: $file->getPathname(), found: $found);
		}

		return $found;
	}//end labels()

	/**
	 * Walk a decoded file and add the label of every transition list it holds.
	 *
	 * @param mixed                             $value The decoded value.
	 * @param string                            $file  The file.
	 * @param list<array{0: string, 1: mixed}> $found The labels found so far.
	 *
	 * @return void
	 */
	private static function collect(mixed $value, string $file, array &$found): void {
		if (is_array($value) === false) {
			return;
		}

		foreach ($value as $key => $item) {
			if ($key === 'transitions') {
				$list = $item;
				if (is_string($list) === true) {
					$list = json_decode($list, true);
				}

				foreach ((is_array($list) === true ? $list : []) as $transition) {
					if (is_array($transition) === true && array_key_exists('label', $transition) === true
						&& (isset($transition['toStatus']) === true || isset($transition['toStatusName']) === true)
					) {
						$found[] = [$file, $transition['label']];
					}
				}
			}

			self::collect(value: $item, file: $file, found: $found);
		}
	}//end collect()

	/**
	 * Every label is a map with a Dutch and an English text.
	 *
	 * @return void
	 */
	public function testEveryShippedLabelHasDutchAndEnglish(): void {
		$labels = self::labels();
		$this->assertNotEmpty($labels);

		foreach ($labels as [$file, $label]) {
			$this->assertIsArray($label, basename($file) . ': a transition label is a language map');
			$this->assertNotSame('', trim((string)($label['nl'] ?? '')), basename($file) . ': Dutch text');
			$this->assertNotSame('', trim((string)($label['en'] ?? '')), basename($file) . ': English text');
		}
	}//end testEveryShippedLabelHasDutchAndEnglish()
}//end class
