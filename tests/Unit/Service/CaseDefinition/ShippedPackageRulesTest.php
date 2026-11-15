<?php

/**
 * Unit tests for ShippedPackageRules: a shipped package's manifest is checked
 * field by field, its parts by the part rules, and files no part owns come
 * back as warnings rather than errors.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules
 *
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackagePartRules
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageSettingRules
 */
class ShippedPackageRulesTest extends TestCase {

	/**
	 * A manifest that passes every check, with overrides.
	 *
	 * @param array<string, mixed> $overrides Fields to replace or add.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(array $overrides = []): array {
		return array_merge(
			['id' => 'bezwaar', 'title' => 'Bezwaar', 'version' => '1.2.3', 'requires' => ['settings'], 'parts' => ['settings']],
			$overrides
		);
	}//end manifest()

	/**
	 * A package directory holding the given files.
	 *
	 * @param array<string, string> $files Files by path.
	 */
	private function package(array $files, string $id = 'bezwaar'): array {
		return ['id' => $id, 'files' => $files];
	}//end package()

	public function testAValidPackageReportsItsManifestAndNoErrors(): void {
		$manifest = $this->manifest(['install' => 'on-request']);

		$result = (new ShippedPackageRules())->check(
			$this->package(['package.json' => json_encode($manifest), 'settings.json' => '{"case_schema": "zaak_schema"}', 'README.md' => '# Bezwaar'])
		);

		$this->assertSame(['valid' => true, 'errors' => [], 'warnings' => [], 'manifest' => $manifest], $result);
	}//end testAValidPackageReportsItsManifestAndNoErrors()

	public function testWithoutAManifestNothingElseIsChecked(): void {
		$this->assertSame(
			['valid' => false, 'errors' => ['package.json is missing.'], 'warnings' => [], 'manifest' => null],
			(new ShippedPackageRules())->check($this->package(['settings.json' => '{"Bad": 1}', 'stray.txt' => 'x']))
		);
		$this->assertSame(
			['package.json is not a JSON object: No error'],
			(new ShippedPackageRules())->check($this->package(['package.json' => '["bezwaar"]']))['errors']
		);
	}//end testWithoutAManifestNothingElseIsChecked()

	public function testEveryManifestFieldIsCheckedInOrder(): void {
		$manifest = ['id' => 'other', 'title' => '  ', 'version' => '1.2', 'requires' => ['settings', 3], 'parts' => [], 'install' => 'always'];

		$result = (new ShippedPackageRules())->check($this->package(['package.json' => json_encode($manifest)]));

		$this->assertFalse($result['valid']);
		$this->assertSame(
			[
				"package.json: id must be 'bezwaar', the directory it sits in.",
				'package.json: title is missing.',
				'package.json: version must read major.minor.patch.',
				'package.json: requires must be a list of capability names.',
				'package.json: parts must be a non-empty list.',
				"package.json: install must be 'auto' or 'on-request'.",
			],
			$result['errors']
		);
		$this->assertSame($manifest, $result['manifest']);
	}//end testEveryManifestFieldIsCheckedInOrder()

	public function testRequiresMustBeAListAndInstallDefaultsToAuto(): void {
		$manifest = $this->manifest(['requires' => ['a' => 'settings']]);
		unset($manifest['install']);

		$this->assertSame(
			['package.json: requires must be a list of capability names.'],
			(new ShippedPackageRules())->check($this->package(['package.json' => json_encode($manifest), 'settings.json' => '{}']))['errors']
		);
	}//end testRequiresMustBeAListAndInstallDefaultsToAuto()

	public function testAnUnknownPartIsNamedAndItsDeclaredPartsAreChecked(): void {
		$manifest = $this->manifest(['parts' => ['settings', 'translations', 7]]);

		$result = (new ShippedPackageRules())->check($this->package(['package.json' => json_encode($manifest)]));

		$this->assertSame(
			[
				"package.json: part 'translations' is not one this installer can write.",
				"package.json: part 'integer' is not one this installer can write.",
				'settings.json is missing.',
			],
			$result['errors']
		);
	}//end testAnUnknownPartIsNamedAndItsDeclaredPartsAreChecked()

	public function testFilesNoDeclaredPartOwnsAreWarnings(): void {
		$manifest = $this->manifest(['parts' => ['settings', 'flows']]);
		$files = [
			'package.json' => json_encode($manifest),
			'settings.json' => '{}',
			'flows/intake.json' => '{"id": "0b1e8f5a-3c2d-4e6f-8a9b-1c2d3e4f5a6b"}',
			'seed/statuses.json' => '{}',
			'case-type.json' => '{}',
			'notes.txt' => 'x',
		];

		$result = (new ShippedPackageRules())->check($this->package($files));

		$this->assertTrue($result['valid']);
		$this->assertSame(
			[
				'seed/statuses.json belongs to no declared part and is not installed.',
				'case-type.json belongs to no declared part and is not installed.',
				'notes.txt belongs to no declared part and is not installed.',
			],
			$result['warnings']
		);
	}//end testFilesNoDeclaredPartOwnsAreWarnings()

	public function testEachCheckStartsWithoutTheLastChecksErrors(): void {
		$rules = new ShippedPackageRules();
		$rules->check($this->package([]));

		$result = $rules->check($this->package(['package.json' => json_encode($this->manifest()), 'settings.json' => '{}']));

		$this->assertTrue($result['valid']);
		$this->assertSame([], $result['errors']);
	}//end testEachCheckStartsWithoutTheLastChecksErrors()
}//end class
