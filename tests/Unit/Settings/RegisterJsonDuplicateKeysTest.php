<?php

/**
 * The register JSON declares every key once per object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * json_decode keeps the last of two equal keys without a word, so a property
 * declared twice leaves one declaration dead. This reads the text itself.
 */
class RegisterJsonDuplicateKeysTest extends TestCase {

	/**
	 * The keys that occur more than once in one object, with their path.
	 *
	 * @param string $json The JSON text.
	 *
	 * @return array<int, string> One entry per repeated key, e.g. `components.schemas.caseType.properties.referenceProcess`.
	 */
	private function duplicateKeys(string $json): array {
		$found = [];
		// Each frame: ['keys' => array<string, true>|null, 'path' => string, 'expectKey' => bool, 'last' => string].
		$stack = [];
		$length = strlen($json);
		for ($i = 0; $i < $length; $i++) {
			$char = $json[$i];
			if ($char === '"') {
				$end = $i + 1;
				while ($end < $length && $json[$end] !== '"') {
					$end += ($json[$end] === '\\') ? 2 : 1;
				}

				$text = json_decode(substr($json, $i, ($end - $i + 1)));
				$i = $end;
				$top = count($stack) - 1;
				if ($top >= 0 && $stack[$top]['keys'] !== null && $stack[$top]['expectKey'] === true) {
					$key = (string)$text;
					if (isset($stack[$top]['keys'][$key]) === true) {
						$found[] = ltrim($stack[$top]['path'] . '.' . $key, '.');
					}

					$stack[$top]['keys'][$key] = true;
					$stack[$top]['last'] = $key;
					$stack[$top]['expectKey'] = false;
				}

				continue;
			}

			$top = count($stack) - 1;
			if ($char === '{' || $char === '[') {
				$path = '';
				if ($top >= 0) {
					$path = $stack[$top]['path'] . '.' . (($stack[$top]['keys'] !== null) ? $stack[$top]['last'] : '[]');
				}

				$stack[] = ['keys' => ($char === '{') ? [] : null, 'path' => $path, 'expectKey' => true, 'last' => ''];
			} else if ($char === '}' || $char === ']') {
				array_pop($stack);
			} else if ($char === ',' && $top >= 0) {
				$stack[$top]['expectKey'] = true;
			}
		}//end for

		return $found;
	}//end duplicateKeys()

	/**
	 * The register and its fragments.
	 *
	 * @return array<string, array{0: string}> File paths by name.
	 */
	public static function registerFiles(): array {
		$dir = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$dir . 'dossiq_register.json'], (glob($dir . 'register.d/*.json') ?: []));
		$cases = [];
		foreach ($files as $file) {
			$cases[basename($file)] = [$file];
		}

		return $cases;
	}//end registerFiles()

	/**
	 * No object in a register file names the same key twice.
	 *
	 * @dataProvider registerFiles
	 *
	 * @param string $file The register file.
	 *
	 * @return void
	 */
	public function testNoObjectDeclaresAKeyTwice(string $file): void {
		$this->assertSame([], $this->duplicateKeys((string)file_get_contents($file)), basename($file));
	}//end testNoObjectDeclaresAKeyTwice()

	/**
	 * The scanner finds a repeated key, also one nested in an array, and does
	 * not count the same key in two sibling objects.
	 *
	 * @return void
	 */
	public function testTheScannerFindsARepeatedKey(): void {
		$this->assertSame(
			['a.b', 'list.[].x'],
			$this->duplicateKeys('{"a": {"b": 1, "c": "x\\"y", "b": 2}, "list": [{"x": 1, "x": 2}, {"x": 3}]}')
		);
		$this->assertSame([], $this->duplicateKeys('{"a": {"k": 1}, "b": {"k": 1}, "s": "{\\"k\\": 1, \\"k\\": 2}"}'));
	}//end testTheScannerFindsARepeatedKey()
}//end class
