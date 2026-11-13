<?php

/**
 * Dossiq Register Storage Declaration and Renamed Setting Keys Test
 *
 * Pins how the register configuration gets a storage declaration for every
 * schema (without overriding one already written), and how a setting saved
 * under an earlier key name is carried to its current name.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\RegisterStorageDeclaration;
use OCA\Dossiq\Service\Settings\RenamedSettingKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegisterStorageDeclaration and RenamedSettingKeys.
 */
#[CoversClass(RegisterStorageDeclaration::class)]
#[CoversClass(RenamedSettingKeys::class)]
final class RegisterStorageDeclarationTest extends TestCase {
	/**
	 * Every schema slug gets magic mapping and an auto-created table, and TMLO is switched on.
	 *
	 * @return void
	 */
	public function testEverySchemaIsDeclared(): void {
		$config = ['components' => ['registers' => ['dossiq' => ['schemas' => ['case', 'task', '', 7]]]]];

		$result = (new RegisterStorageDeclaration())->declare(config: $config);

		self::assertSame(
			expected: [
				'schemas'     => [
					'case' => ['magicMapping' => true, 'autoCreateTable' => true],
					'task' => ['magicMapping' => true, 'autoCreateTable' => true],
				],
				'tmloEnabled' => true,
			],
			actual: $result['components']['registers']['dossiq']['configuration']
		);
		self::assertSame(expected: ['case', 'task', '', 7], actual: $result['components']['registers']['dossiq']['schemas']);
	}//end testEverySchemaIsDeclared()

	/**
	 * A declaration already written, and an explicit TMLO switch, are kept; other configuration stays.
	 *
	 * @return void
	 */
	public function testExistingDeclarationsAreKept(): void {
		$config = [
			'components' => [
				'registers' => [
					'dossiq' => [
						'schemas'       => ['case', 'task'],
						'configuration' => [
							'schemas'     => ['case' => ['magicMapping' => false]],
							'tmloEnabled' => false,
							'other'       => 'x',
						],
					],
				],
			],
		];

		$configuration = (new RegisterStorageDeclaration())->declare(config: $config)['components']['registers']['dossiq']['configuration'];

		self::assertSame(
			expected: [
				'schemas'     => [
					'case' => ['magicMapping' => false],
					'task' => ['magicMapping' => true, 'autoCreateTable' => true],
				],
				'tmloEnabled' => false,
				'other'       => 'x',
			],
			actual: $configuration
		);
	}//end testExistingDeclarationsAreKept()

	/**
	 * A non-array configuration or declared schema list is replaced rather than trusted.
	 *
	 * @return void
	 */
	public function testMalformedConfigurationIsRebuilt(): void {
		$config = ['components' => ['registers' => ['dossiq' => ['schemas' => ['case'], 'configuration' => 'broken']]]];
		$fromBroken = (new RegisterStorageDeclaration())->declare(config: $config)['components']['registers']['dossiq']['configuration'];

		$config['components']['registers']['dossiq']['configuration'] = ['schemas' => 'broken'];
		$fromBrokenList = (new RegisterStorageDeclaration())->declare(config: $config)['components']['registers']['dossiq']['configuration'];

		$expected = ['schemas' => ['case' => ['magicMapping' => true, 'autoCreateTable' => true]], 'tmloEnabled' => true];
		self::assertSame(expected: $expected, actual: $fromBroken);
		self::assertSame(expected: $expected, actual: $fromBrokenList);
	}//end testMalformedConfigurationIsRebuilt()

	/**
	 * A config without that register, or with a non-array schema list, is returned unchanged.
	 *
	 * @return void
	 */
	public function testConfigWithoutTheRegisterIsUnchanged(): void {
		$declaration = new RegisterStorageDeclaration();
		$noRegister  = ['components' => ['registers' => ['other' => ['schemas' => ['case']]]]];
		$badSchemas  = ['components' => ['registers' => ['dossiq' => ['schemas' => 'case']]]];

		self::assertSame(expected: $noRegister, actual: $declaration->declare(config: $noRegister));
		self::assertSame(expected: $badSchemas, actual: $declaration->declare(config: $badSchemas));
		self::assertSame(expected: [], actual: $declaration->declare(config: []));
	}//end testConfigWithoutTheRegisterIsUnchanged()

	/**
	 * Another register slug is declared when named.
	 *
	 * @return void
	 */
	public function testAnotherRegisterSlugIsDeclaredWhenNamed(): void {
		$config = ['components' => ['registers' => ['dossiq' => ['schemas' => ['case']], 'vth' => ['schemas' => ['permit']]]]];

		$result = (new RegisterStorageDeclaration())->declare(config: $config, registerSlug: 'vth');

		self::assertArrayHasKey(key: 'permit', array: $result['components']['registers']['vth']['configuration']['schemas']);
		self::assertArrayNotHasKey(key: 'configuration', array: $result['components']['registers']['dossiq']);
	}//end testAnotherRegisterSlugIsDeclaredWhenNamed()

	/**
	 * A value saved under an earlier key is carried to its current key, unless the current key is present.
	 *
	 * @return void
	 */
	public function testRenamedKeysAreCarriedWithoutOverriding(): void {
		$keys = new RenamedSettingKeys(earlierKeys: ['case_schema' => 'zaak_schema', 'task_schema' => 'taak_schema', 'role_schema' => 'rol_schema']);

		$result = $keys->carry(
			data: [
				'zaak_schema' => '12',
				'taak_schema' => '13',
				'task_schema' => '99',
				'rol_schema'  => null,
				'other'       => 'x',
			]
		);

		self::assertSame(
			expected: [
				'zaak_schema' => '12',
				'taak_schema' => '13',
				'task_schema' => '99',
				'rol_schema'  => null,
				'other'       => 'x',
				'case_schema' => '12',
				'role_schema' => null,
			],
			actual: $result
		);
	}//end testRenamedKeysAreCarriedWithoutOverriding()

	/**
	 * Without the earlier key nothing is added.
	 *
	 * @return void
	 */
	public function testNothingToCarryLeavesDataUnchanged(): void {
		$keys = new RenamedSettingKeys(earlierKeys: ['case_schema' => 'zaak_schema']);

		self::assertSame(expected: ['other' => 'x'], actual: $keys->carry(data: ['other' => 'x']));
	}//end testNothingToCarryLeavesDataUnchanged()
}//end class
