<?php

/**
 * "Zoek op BSN": guarded, recorded, exact, and the answer never names the number.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
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
 * @covers \OCA\Dossiq\Service\People\PersonNumberLookup
 */
class PersonNumberLookupTest extends TestCase {

	private const BSN = '999990627';

	/**
	 * What the guard was told.
	 *
	 * @var array<int, string>
	 */
	private array $recorded = [];

	/**
	 * The lookup for a caller the guard allows or refuses.
	 *
	 * @param bool $allowed Whether the guard allows the caller.
	 *
	 * @return PersonNumberLookup The lookup.
	 */
	private function lookup(bool $allowed): PersonNumberLookup {
		$guard = $this->createMock(GuardedCitizenLookup::class);
		$guard->method('isAllowed')->willReturn($allowed);
		$guard->method('recordRefusal')->willReturnCallback(function (string $uid, string $burgerId): void {
			$this->recorded[] = 'refused ' . $burgerId;
		});
		$guard->method('recordAnswer')->willReturnCallback(function (IUser $user, string $burgerId): void {
			$this->recorded[] = 'answered ' . $burgerId;
		});
		$objects = new class {
			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if (($filters['citizenServiceNumber'] ?? '') === '999990639') {
					throw new \RuntimeException('register unreachable');
				}

				if (($filters['citizenServiceNumber'] ?? '') !== '999990627') {
					return [];
				}

				return [['id' => 'p9', 'displayName' => 'Sanne de Vries', 'citizenServiceNumber' => '999990627', 'residence' => ['street' => 'Lindelaan']]];
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturn('dossiq');

		return new PersonNumberLookup($guard, $settings, new TrustedRevealRead());
	}//end lookup()

	/**
	 * The caller.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		return $user;
	}//end user()

	/**
	 * An allowed caller finds the person by an exact BSN; the answer omits the
	 * number, and the lookup is recorded.
	 *
	 * @return void
	 */
	public function testFindsThePersonAndRecordsIt(): void {
		$people = $this->lookup(allowed: true)->find(user: $this->user(), bsn: self::BSN);

		$this->assertSame([['id' => 'p9', 'displayName' => 'Sanne de Vries', 'residence' => ['street' => 'Lindelaan']]], $people);
		$this->assertSame(['answered ' . self::BSN], $this->recorded);
	}//end testFindsThePersonAndRecordsIt()

	/**
	 * A refused caller reads nothing; the refusal is recorded.
	 *
	 * @return void
	 */
	public function testARefusedCallerReadsNothing(): void {
		$this->assertNull($this->lookup(allowed: false)->find(user: $this->user(), bsn: self::BSN));
		$this->assertSame(['refused ' . self::BSN], $this->recorded);
	}//end testARefusedCallerReadsNothing()

	/**
	 * A term that is not a valid BSN is not looked up.
	 *
	 * @return void
	 */
	public function testAnInvalidNumberIsNotLookedUp(): void {
		$this->assertSame([], $this->lookup(allowed: true)->find(user: $this->user(), bsn: '123456789'));
		$this->assertSame([], $this->recorded);
		$this->assertTrue(PersonNumberLookup::isBsn(value: self::BSN));
	}//end testAnInvalidNumberIsNotLookedUp()

	/**
	 * A register that cannot be read is not "nobody has this BSN": the read
	 * failure reaches the caller and no answer is recorded.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsNotAnEmptyAnswer(): void {
		$this->expectException(\RuntimeException::class);
		try {
			$this->lookup(allowed: true)->find(user: $this->user(), bsn: '999990639');
		} finally {
			$this->assertSame([], $this->recorded);
		}
	}//end testAnUnreadableRegisterIsNotAnEmptyAnswer()
}//end class
