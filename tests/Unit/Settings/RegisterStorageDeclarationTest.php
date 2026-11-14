<?php
/**
 * Register Storage Declaration Unit Tests
 *
 * Every schema of the dossiq register is declared magic-mapped in the
 * effective configuration, whichever file it came from.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\RegisterStorageDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister names an object only when its schema is declared magic-mapped
 * on the register; an undeclared schema's objects show up as uuids wherever a
 * facet or a reference is labelled.
 *
 * @covers \OCA\Dossiq\Service\Settings\RegisterStorageDeclaration
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class RegisterStorageDeclarationTest extends TestCase {


	/**
	 * Every schema the shipped register carries, monolith and fragments alike, is declared.
	 *
	 * @return void
	 */
	public function testEveryShippedSchemaIsDeclared(): void {
		$base = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: __DIR__ . '/../../../lib/Settings/register.d'
		);

		$declared = (new RegisterStorageDeclaration())->declare(config: $merged);
		$register = $declared['components']['registers']['dossiq'];

		$this->assertNotEmpty(actual: $register['schemas']);
		foreach ($register['schemas'] as $slug) {
			$this->assertSame(
				expected: ['magicMapping' => true, 'autoCreateTable' => true],
				actual: $register['configuration']['schemas'][$slug] ?? null,
				message: "schema '{$slug}' is not declared magic-mapped"
			);
		}

		// The case type and status the cases sidebar filters on are among them.
		$this->assertContains(needle: 'caseType', haystack: $register['schemas']);
		$this->assertContains(needle: 'statusType', haystack: $register['schemas']);
	}//end testEveryShippedSchemaIsDeclared()


	/**
	 * A declaration the register already makes for a schema is kept as written.
	 *
	 * @return void
	 */
	public function testAnExplicitDeclarationIsLeftAlone(): void {
		$config = [
			'components' => [
				'registers' => [
					'dossiq' => [
						'slug'          => 'dossiq',
						'schemas'       => ['caseType', 'auditLog'],
						'configuration' => [
							'schemas' => [
								'auditLog' => ['magicMapping' => false, 'comment' => 'append-only blob'],
							],
						],
					],
				],
			],
		];

		$declared = (new RegisterStorageDeclaration())->declare(config: $config);
		$schemas  = $declared['components']['registers']['dossiq']['configuration']['schemas'];

		$this->assertSame(expected: ['magicMapping' => false, 'comment' => 'append-only blob'], actual: $schemas['auditLog']);
		$this->assertSame(expected: ['magicMapping' => true, 'autoCreateTable' => true], actual: $schemas['caseType']);
	}//end testAnExplicitDeclarationIsLeftAlone()


	/**
	 * The import replaces the stored configuration, so the TMLO flag the
	 * archival repair step switches on rides along, unless the register
	 * says otherwise.
	 *
	 * @return void
	 */
	public function testTmloStaysOnAcrossAnImport(): void {
		$config = ['components' => ['registers' => ['dossiq' => ['slug' => 'dossiq', 'schemas' => ['case']]]]];

		$declared = (new RegisterStorageDeclaration())->declare(config: $config);
		$this->assertTrue(condition: $declared['components']['registers']['dossiq']['configuration']['tmloEnabled']);

		$config['components']['registers']['dossiq']['configuration'] = ['tmloEnabled' => false];
		$declared = (new RegisterStorageDeclaration())->declare(config: $config);
		$this->assertFalse(condition: $declared['components']['registers']['dossiq']['configuration']['tmloEnabled']);
	}//end testTmloStaysOnAcrossAnImport()


	/**
	 * A configuration without the register, or with a malformed one, passes through untouched.
	 *
	 * @return void
	 */
	public function testLeavesAConfigurationWithoutTheRegisterUntouched(): void {
		$declaration = new RegisterStorageDeclaration();

		$this->assertSame(expected: ['components' => []], actual: $declaration->declare(config: ['components' => []]));

		$malformed = ['components' => ['registers' => ['dossiq' => ['schemas' => 'case']]]];
		$this->assertSame(expected: $malformed, actual: $declaration->declare(config: $malformed));
	}//end testLeavesAConfigurationWithoutTheRegisterUntouched()
}//end class
