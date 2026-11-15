<?php

/**
 * The BSN lookup at its edges: the eleven-test and an unconfigured register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\Kcc\GuardedCitizenLookup;
use OCA\Dossiq\Service\People\PersonNumberLookup;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\TrustedRevealRead;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PersonNumberLookup at its edges.
 *
 * @covers \OCA\Dossiq\Service\People\PersonNumberLookup
 * @uses \OCA\Dossiq\Service\Support\TrustedRevealRead
 */
class PersonNumberLookupEdgeTest extends TestCase {

	/**
	 * Values the eleven-test accepts or refuses.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function numbers(): array {
		return [
			'a valid BSN' => ['999990627', true],
			'a failing checksum' => ['999990628', false],
			'eight digits' => ['99999062', false],
			'ten digits' => ['9999906270', false],
			'letters' => ['99999062a', false],
			'empty' => ['', false],
			'surrounding spaces' => [' 999990627', false],
		];
	}//end numbers()

	/**
	 * Only nine digits passing the eleven-test are a BSN.
	 *
	 * @param string $value    The candidate.
	 * @param bool   $expected Whether it is a BSN.
	 *
	 * @return void
	 *
	 * @dataProvider numbers
	 */
	public function testTheElevenTest(string $value, bool $expected): void {
		self::assertSame($expected, PersonNumberLookup::isBsn(value: $value));
	}//end testTheElevenTest()

	/**
	 * Without OpenRegister or a configured register the lookup finds nobody, and still records the answer.
	 *
	 * @return void
	 */
	public function testNoRegisterFindsNobody(): void {
		foreach ([[null, 'dossiq'], [new \stdClass(), '']] as [$objectService, $register]) {
			$guard = $this->createMock(GuardedCitizenLookup::class);
			$guard->method('isAllowed')->willReturn(true);
			$guard->expects(self::once())->method('recordAnswer')->with(self::isInstanceOf(IUser::class), '999990627');

			$settings = $this->createMock(SettingsService::class);
			$settings->method('getObjectService')->willReturn($objectService);
			$settings->method('getConfigValue')->willReturn($register);

			$lookup = new PersonNumberLookup($guard, $settings, new TrustedRevealRead());

			self::assertSame([], $lookup->find(user: $this->createMock(IUser::class), bsn: ' 999990627 '));
		}
	}//end testNoRegisterFindsNobody()
}//end class
