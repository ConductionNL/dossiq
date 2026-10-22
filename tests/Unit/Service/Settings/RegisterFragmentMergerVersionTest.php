<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * A schema's version is the highest any fragment declares, not the last one
 * read. Fragments are merged in filename order and a scalar overwrites, so a
 * fragment that bumped `caseType` to 1.29.0 was undone by one sorting after it
 * that still said 1.28.0, and an instance never re-imported the schema.
 *
 * @spec openspec/changes/register-version-is-the-highest/specs/admin-settings/spec.md#requirement-a-merged-schema-carries-the-highest-version-its-fragments-declare
 */
class RegisterFragmentMergerVersionTest extends TestCase {

	/**
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/dossiq-fragments-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}//end setUp()

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*.json') ?: [] as $file) {
			unlink($file);
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * @param string               $name     The fragment's file name.
	 * @param array<string, mixed> $fragment Its content.
	 *
	 * @return void
	 */
	private function fragment(string $name, array $fragment): void {
		file_put_contents($this->dir . '/' . $name, json_encode($fragment));
	}//end fragment()

	/**
	 * @param string $version The version.
	 * @param string $title   A title.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(string $version, string $title): array {
		return ['components' => ['schemas' => ['caseType' => ['version' => $version, 'title' => $title]]]];
	}//end caseType()

	/**
	 * @return void
	 */
	public function testAnEarlierHigherVersionIsNotUndoneByALaterLowerOne(): void {
		$this->fragment('68-variants.json', $this->caseType('1.29.0', 'Variants'));
		$this->fragment('99-macros.json', $this->caseType('1.28.0', 'Macros'));

		[$merged] = (new RegisterFragmentMerger())->merge(base: $this->caseType('1.23.0', 'Base'), fragmentDir: $this->dir);

		$this->assertSame('1.29.0', $merged['components']['schemas']['caseType']['version']);
		$this->assertSame('Macros', $merged['components']['schemas']['caseType']['title'], 'Every other scalar still takes the last fragment.');
	}

	/**
	 * @return void
	 */
	public function testALaterHigherVersionWins(): void {
		$this->fragment('10-a.json', $this->caseType('1.24.0', 'A'));
		$this->fragment('20-b.json', $this->caseType('1.30.0', 'B'));

		[$merged] = (new RegisterFragmentMerger())->merge(base: $this->caseType('1.23.0', 'Base'), fragmentDir: $this->dir);

		$this->assertSame('1.30.0', $merged['components']['schemas']['caseType']['version']);
	}

	/**
	 * A value that is not a version number is overwritten as before.
	 *
	 * @return void
	 */
	public function testANonVersionValueIsOverwrittenAsBefore(): void {
		$this->fragment('10-a.json', ['info' => ['version' => 'draft']]);

		[$merged] = (new RegisterFragmentMerger())->merge(base: ['info' => ['version' => '2.0.0']], fragmentDir: $this->dir);

		$this->assertSame('draft', $merged['info']['version']);
	}

	/**
	 * The shipped fragments: the merged caseType carries the highest version
	 * any of them declares.
	 *
	 * @return void
	 */
	public function testTheShippedCaseTypeCarriesTheHighestDeclaredVersion(): void {
		$settings = dirname(__DIR__, 4) . '/lib/Settings';
		$base     = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		$highest  = (string)$base['components']['schemas']['caseType']['version'];
		foreach (glob($settings . '/register.d/*.json') ?: [] as $file) {
			$declared = (json_decode((string)file_get_contents($file), true)['components']['schemas']['caseType']['version'] ?? null);
			if (is_string($declared) === true && version_compare($declared, $highest, '>') === true) {
				$highest = $declared;
			}
		}

		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');

		$this->assertSame($highest, $merged['components']['schemas']['caseType']['version']);
	}
}//end class
