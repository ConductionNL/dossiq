<?php

/**
 * The shipped municipal role set: dormant, adopted once, undone while unused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Starter\MunicipalRoleSetService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for MunicipalRoleSetService.
 *
 * @covers \OCA\Dossiq\Service\Starter\MunicipalRoleSetService
 *
 * @uses \OCA\Dossiq\Service\Starter\StarterStore
 * @uses \OCA\Dossiq\Service\Starter\ShippedConfigurationService
 * @uses \OCA\Dossiq\Service\Starter\ShippedFingerprint
 * @uses \OCA\Dossiq\Service\Starter\ShippedSets
 */
class MunicipalRoleSetTest extends TestCase {

	/**
	 * The store and its rows.
	 *
	 * @var StarterStoreHarness
	 */
	private StarterStoreHarness $harness;

	/**
	 * The service under test.
	 *
	 * @var MunicipalRoleSetService
	 */
	private MunicipalRoleSetService $roleSet;

	/**
	 * Build the service over a real store, signed in as one administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harness = new StarterStoreHarness(test: $this);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('noor');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->roleSet = new MunicipalRoleSetService(
			$this->harness->store,
			new ShippedConfigurationService(
				$this->harness->store,
				new ShippedSets(),
				new ShippedFingerprint(),
				new NullLogger(),
			),
			new ShippedSets(),
			$session,
			new NullLogger(),
		);
	}//end setUp()

	/**
	 * The roles are there to adopt, and no role in the set holds a grant.
	 *
	 * @return void
	 */
	public function testTheShippedRolesArriveDormantAndUngranted(): void {
		$tally = $this->roleSet->seed();

		self::assertNotNull(actual: $tally);
		self::assertSame(expected: count(MunicipalRoleSetService::ROLES_SHIPPED), actual: $tally['seeded']);

		$offer = $this->roleSet->offer();

		self::assertNotNull(actual: $offer);
		self::assertFalse(condition: $offer['adopted']);
		self::assertSame(expected: [], actual: $offer['rolesInUse']);
		foreach ($offer['roles'] as $role) {
			self::assertTrue(condition: $role['dormant'], message: $role['name'] . ' arrived awake');
		}
	}//end testTheShippedRolesArriveDormantAndUngranted()

	/**
	 * Seeding twice does not leave six more Behandelaars.
	 *
	 * @return void
	 */
	public function testSeedingTwiceDoesNotDuplicateTheSet(): void {
		$this->roleSet->seed();
		$second = $this->roleSet->seed();

		self::assertNotNull(actual: $second);
		self::assertSame(expected: 0, actual: $second['seeded']);
		self::assertSame(
			expected: count(MunicipalRoleSetService::ROLES_SHIPPED),
			actual: count($this->harness->register->all(schema: 'roleType'))
		);
	}//end testSeedingTwiceDoesNotDuplicateTheSet()

	/**
	 * Every seeded role carries a provenance row naming the set.
	 *
	 * @return void
	 */
	public function testEverySeededRoleIsStampedWithItsSet(): void {
		$this->roleSet->seed();

		$ledger = $this->harness->register->all(schema: 'shippedOrigin');

		self::assertCount(expectedCount: count(MunicipalRoleSetService::ROLES_SHIPPED), haystack: $ledger);
		foreach ($ledger as $row) {
			self::assertSame(expected: ShippedSets::MUNICIPAL_ROLES, actual: $row['set']);
			self::assertSame(expected: 'roleType', actual: $row['targetSchema']);
		}
	}//end testEverySeededRoleIsStampedWithItsSet()

	/**
	 * Adoption is one act, records who did it, and wakes every role.
	 *
	 * @return void
	 */
	public function testAdoptionIsOneActAndIsRecorded(): void {
		$this->roleSet->seed();

		$result = $this->roleSet->adopt();

		self::assertTrue(condition: $result['ok']);
		self::assertSame(expected: count(MunicipalRoleSetService::ROLES_SHIPPED), actual: $result['adopted']);

		$offer = $this->roleSet->offer();

		self::assertNotNull(actual: $offer);
		self::assertTrue(condition: $offer['adopted']);
		self::assertSame(expected: 'noor', actual: $offer['adoptedBy']);
		foreach ($offer['roles'] as $role) {
			self::assertFalse(condition: $role['dormant'], message: $role['name'] . ' stayed dormant');
		}
	}//end testAdoptionIsOneActAndIsRecorded()

	/**
	 * A second adoption is refused rather than recorded twice.
	 *
	 * @return void
	 */
	public function testAdoptingATwiceAdoptedSetIsRefused(): void {
		$this->roleSet->seed();
		$this->roleSet->adopt();

		$again = $this->roleSet->adopt();

		self::assertFalse(condition: $again['ok']);
		self::assertSame(expected: 'already_adopted', actual: $again['reason']);
	}//end testAdoptingATwiceAdoptedSetIsRefused()

	/**
	 * Adoption is undone while nothing uses it.
	 *
	 * @return void
	 */
	public function testAdoptionIsUndoneWhileNothingUsesIt(): void {
		$this->roleSet->seed();
		$this->roleSet->adopt();

		$undone = $this->roleSet->undoAdoption();

		self::assertTrue(condition: $undone['ok']);

		$offer = $this->roleSet->offer();

		self::assertNotNull(actual: $offer);
		self::assertFalse(condition: $offer['adopted']);
		foreach ($offer['roles'] as $role) {
			self::assertTrue(condition: $role['dormant'], message: $role['name'] . ' stayed awake');
		}
	}//end testAdoptionIsUndoneWhileNothingUsesIt()

	/**
	 * Adoption is not undone once somebody is in a role, and the refusal names
	 * the role.
	 *
	 * 🔑 THE FAILURE THIS PINS TAKES ACCESS AWAY SILENTLY. An undo that swept
	 * a granted role back to dormant would remove whatever it governs from
	 * whoever had it, with nothing on screen saying so.
	 *
	 * @return void
	 */
	public function testAdoptionIsNotUndoneOnceAGrantExists(): void {
		$this->roleSet->seed();
		$this->roleSet->adopt();

		$behandelaar = null;
		foreach ($this->harness->register->all(schema: 'roleType') as $role) {
			if ($role['name'] === 'Behandelaar') {
				$behandelaar = $role;
			}
		}

		self::assertNotNull(actual: $behandelaar);
		$this->harness->seed(
			schema: 'role',
			uuid: 'grant-1',
			row: ['id' => 'grant-1', 'roleType' => (string)$behandelaar['id'], 'participant' => 'ahmed'],
		);

		$refused = $this->roleSet->undoAdoption();

		self::assertFalse(condition: $refused['ok']);
		self::assertSame(expected: 'role_in_use', actual: $refused['reason']);
		self::assertSame(expected: 'Behandelaar', actual: $refused['roleInUse']);
	}//end testAdoptionIsNotUndoneOnceAGrantExists()

	/**
	 * A role schema nobody configured reads as in use, not as ungranted.
	 *
	 * "Could not look" and "found none" are different facts, and answering the
	 * second for the first is how an undo strips access nobody checked for.
	 *
	 * @return void
	 */
	public function testAnUnreadableGrantStoreStopsTheUndo(): void {
		$this->roleSet->seed();
		$this->roleSet->adopt();

		$blind = new StarterStoreHarness(test: $this, unconfigured: ['role_schema']);
		$blind->register->rows = $this->harness->register->rows;

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('noor');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$service = new MunicipalRoleSetService(
			$blind->store,
			new ShippedConfigurationService(
				$blind->store,
				new ShippedSets(),
				new ShippedFingerprint(),
				new NullLogger(),
			),
			new ShippedSets(),
			$session,
			new NullLogger(),
		);

		$refused = $service->undoAdoption();

		self::assertFalse(condition: $refused['ok']);
		self::assertSame(expected: 'role_in_use', actual: $refused['reason']);
	}//end testAnUnreadableGrantStoreStopsTheUndo()
}//end class
