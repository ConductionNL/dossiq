<?php

/**
 * CaseObjectReference Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseObjectReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a register/schema pair another app hands over names a dossiq case:
 * by the configured ids (also as a path suffix), or by the app's own slugs.
 */
#[CoversClass(CaseObjectReference::class)]
class CaseObjectReferenceTest extends TestCase {

	/**
	 * The subject over configured register and case schema.
	 *
	 * @param string $register     Configured register.
	 * @param string $caseSchema   Configured case schema.
	 *
	 * @return CaseObjectReference
	 */
	private function reference(string $register, string $caseSchema): CaseObjectReference {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => $register,
				'case_schema' => $caseSchema,
				default => '',
			}
		);

		return new CaseObjectReference(settingsService: $settings);
	}//end reference()

	/**
	 * The pairs and whether they name a case, with register "14" and case schema "Zaak".
	 *
	 * @return array<string, array{0: ?string, 1: ?string, 2: bool}>
	 */
	public static function pairs(): array {
		return [
			'configured ids' => ['14', '153', false],
			'configured register and schema, any case' => ['14', 'ZAAK', true],
			'path ending in the configured ids' => ['/api/registers/14', '/api/schemas/zaak', true],
			'app slug and case slug' => [' Dossiq ', 'case', true],
			'blank register' => ['', 'zaak', false],
			'null schema' => ['14', null, false],
			'another register' => ['15', 'zaak', false],
			'a path that only contains the id' => ['/api/registers/140', 'zaak', false],
			'a name merely ending in the id' => ['dept14', 'zaak', false],
			'own register, another schema' => ['dossiq', 'task', false],
		];
	}//end pairs()

	/**
	 * Each pair is recognised, or not, as a dossiq case.
	 *
	 * @param string|null $register The register given.
	 * @param string|null $schema   The schema given.
	 * @param bool        $expected Whether it is a case.
	 *
	 * @return void
	 */
	#[DataProvider('pairs')]
	public function testRecognisesACase(?string $register, ?string $schema, bool $expected): void {
		$this->assertSame($expected, $this->reference(register: '14', caseSchema: 'Zaak')->isCase(register: $register, schema: $schema));
	}//end testRecognisesACase()

	/**
	 * Without configuration only the app's own slugs name a case.
	 *
	 * @return void
	 */
	public function testWithoutConfigurationOnlyTheSlugsMatch(): void {
		$reference = $this->reference(register: '', caseSchema: '');

		$this->assertTrue($reference->isCase(register: 'dossiq', schema: 'case'));
		$this->assertFalse($reference->isCase(register: '14', schema: 'case'));
		$this->assertFalse($reference->isCase(register: 'dossiq', schema: 'zaak'));
	}//end testWithoutConfigurationOnlyTheSlugsMatch()
}//end class
