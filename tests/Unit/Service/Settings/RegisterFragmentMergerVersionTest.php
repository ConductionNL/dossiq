<?php

/**
 * Dossiq Register Fragment Merger Version Test
 *
 * A schema's `version` is OpenRegister's per-schema import gate: a version that
 * is not higher than the installed one skips the schema. When several fragments
 * bump the same schema, the merged version must be the highest of them. It was
 * the last file in sort order: `99-case-type-which-macros` (1.28.0) overwrote
 * `68-case-type-variants` (1.29.0), so the variants properties could not reach
 * an instance that already had 1.28.0.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The merged schema version is the highest any fragment declares.
 */
class RegisterFragmentMergerVersionTest extends TestCase {
	/** @var string The temporary fragment directory. */
	private string $dir = '';

	/**
	 * Create an empty fragment directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/dossiq-fragments-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}//end setUp()

	/**
	 * Remove the fragment directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$files = glob($this->dir . '/*');
		if ($files === false) {
			$files = [];
		}

		foreach ($files as $file) {
			unlink($file);
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * Write a fragment that bumps a schema.
	 *
	 * @param string $name The file name.
	 * @param string $schema The schema key.
	 * @param string $version The version it declares.
	 * @param string $property A property it adds.
	 *
	 * @return void
	 */
	private function fragment(string $name, string $schema, string $version, string $property): void {
		$body = ['components' => ['schemas' => [$schema => ['version' => $version, 'properties' => [$property => ['type' => 'string']]]]]];
		file_put_contents($this->dir . '/' . $name, json_encode($body));
	}//end fragment()

	/**
	 * A later file with a lower version does not lower the schema's version.
	 *
	 * @return void
	 */
	public function testTheHighestVersionWinsWhateverTheFileOrder(): void {
		$this->fragment(name: '68-case-type-variants.json', schema: 'caseType', version: '1.29.0', property: 'variants');
		$this->fragment(name: '99-case-type-which-macros.json', schema: 'caseType', version: '1.28.0', property: 'macros');
		$base = ['components' => ['schemas' => ['caseType' => ['version' => '1.20.0', 'properties' => []]]]];

		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $this->dir);

		$caseType = $merged['components']['schemas']['caseType'];
		self::assertSame(expected: '1.29.0', actual: $caseType['version']);
		self::assertArrayHasKey(key: 'variants', array: $caseType['properties']);
		self::assertArrayHasKey(key: 'macros', array: $caseType['properties']);
	}//end testTheHighestVersionWinsWhateverTheFileOrder()

	/**
	 * Versions compare as versions, not as strings: 1.10.0 is above 1.9.0.
	 *
	 * @return void
	 */
	public function testVersionsCompareAsVersions(): void {
		$this->fragment(name: '10-a.json', schema: 'task', version: '1.10.0', property: 'a');
		$this->fragment(name: '20-b.json', schema: 'task', version: '1.9.0', property: 'b');

		[$merged] = (new RegisterFragmentMerger())->merge(base: [], fragmentDir: $this->dir);

		self::assertSame(expected: '1.10.0', actual: $merged['components']['schemas']['task']['version']);
	}//end testVersionsCompareAsVersions()

	/**
	 * A fragment can still raise a version, and other scalars still take the last value.
	 *
	 * @return void
	 */
	public function testAHigherVersionStillRaisesAndOtherScalarsStillOverwrite(): void {
		$first  = ['components' => ['schemas' => ['task' => ['version' => '1.1.0', 'title' => 'Task']]]];
		$second = ['components' => ['schemas' => ['task' => ['version' => '1.2.0', 'title' => 'Work item']]]];
		file_put_contents($this->dir . '/10-a.json', json_encode($first));
		file_put_contents($this->dir . '/20-b.json', json_encode($second));

		[$merged] = (new RegisterFragmentMerger())->merge(base: [], fragmentDir: $this->dir);

		self::assertSame(expected: '1.2.0', actual: $merged['components']['schemas']['task']['version']);
		self::assertSame(expected: 'Work item', actual: $merged['components']['schemas']['task']['title']);
	}//end testAHigherVersionStillRaisesAndOtherScalarsStillOverwrite()

	/**
	 * The shipped register merges every schema to the highest version the base or a fragment declares.
	 *
	 * @return void
	 */
	public function testTheShippedRegisterMergesToTheHighestDeclaredVersion(): void {
		$settings = dirname(path: __DIR__, levels: 4) . '/lib/Settings';
		$base     = json_decode(json: (string) file_get_contents(filename: $settings . '/dossiq_register.json'), associative: true);

		$declared = [];
		$sources  = array_merge([$settings . '/dossiq_register.json'], (array) glob(pattern: $settings . '/register.d/*.json'));
		foreach ($sources as $file) {
			$config = json_decode(json: (string) file_get_contents(filename: $file), associative: true);
			foreach (($config['components']['schemas'] ?? []) as $name => $schema) {
				$version = $schema['version'] ?? null;
				if (is_string($version) === false) {
					continue;
				}

				if (isset($declared[$name]) === false || version_compare(version1: $version, version2: $declared[$name], operator: '>') === true) {
					$declared[$name] = $version;
				}
			}
		}

		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');

		self::assertNotEmpty(actual: $declared);
		foreach ($declared as $name => $version) {
			self::assertSame(expected: $version, actual: $merged['components']['schemas'][$name]['version'], message: $name);
		}
	}//end testTheShippedRegisterMergesToTheHighestDeclaredVersion()
}//end class
