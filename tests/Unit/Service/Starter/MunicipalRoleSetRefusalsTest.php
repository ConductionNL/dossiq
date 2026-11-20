<?php

/**
 * The shipped municipal role set when its store cannot be read or written.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
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

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\Starter\MunicipalRoleSetService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedLedgerTargets;
use OCA\Dossiq\Service\Starter\ShippedRoleSources;
use OCA\Dossiq\Service\Starter\ShippedRowShape;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\TranslatedText;
use OCA\Dossiq\Tests\Support\StarterStoreHarness;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Refusals of the municipal role set: an unreachable store, a refused write,
 * an undo with nothing adopted and a role without an id.
 */
#[CoversClass(MunicipalRoleSetService::class)]
#[UsesClass(ShippedRoleSources::class)]
#[UsesClass(ShippedConfigurationService::class)]
#[UsesClass(ShippedFingerprint::class)]
#[UsesClass(ShippedLedgerTargets::class)]
#[UsesClass(ShippedRowShape::class)]
#[UsesClass(ShippedSets::class)]
#[UsesClass(StarterStore::class)]
#[UsesClass(JsonEncodedStringProperties::class)]
#[UsesClass(LanguageMapText::class)]
#[UsesClass(TranslatedText::class)]
final class MunicipalRoleSetRefusalsTest extends TestCase {

	/**
	 * Build the service over a harness.
	 *
	 * @param StarterStoreHarness $harness The store and its rows.
	 *
	 * @return MunicipalRoleSetService The service.
	 */
	private function serviceOver(StarterStoreHarness $harness): MunicipalRoleSetService {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('noor');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new MunicipalRoleSetService(
			shippedRoles: new ShippedRoleSources(
				store: $harness->store,
				shipped: new ShippedConfigurationService(
					store: $harness->store,
					sets: new ShippedSets(),
					fingerprint: new ShippedFingerprint(),
					logger: new NullLogger(),
				),
				sets: new ShippedSets(),
			),
			session: $session,
			logger: new NullLogger(),
		);
	}//end serviceOver()

	/**
	 * With no role type schema configured, seed and offer answer null and the
	 * undo says the store is unavailable, writing nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRoleTypeSchemaAnswersUnavailable(): void {
		$harness = new StarterStoreHarness(test: $this, unconfigured: [MunicipalRoleSetService::ROLE_TYPES]);
		$service = $this->serviceOver(harness: $harness);

		self::assertNull(actual: $service->seed());
		self::assertNull(actual: $service->offer());
		self::assertSame(
			expected: ['ok' => false, 'reason' => 'unavailable', 'roleInUse' => ''],
			actual: $service->undoAdoption()
		);
		self::assertSame(expected: 0, actual: $harness->register->writes);
	}//end testAnUnconfiguredRoleTypeSchemaAnswersUnavailable()

	/**
	 * A refused role write is neither counted as seeded nor stamped on the ledger.
	 *
	 * @return void
	 */
	public function testARefusedRoleWriteIsNotCountedOrStamped(): void {
		$harness = new StarterStoreHarness(test: $this);
		$harness->register->refusedSchemas = ['roleType'];
		$service = $this->serviceOver(harness: $harness);

		$tally = $service->seed();

		self::assertSame(expected: ['seeded' => 0, 'skipped' => 0], actual: $tally);
		self::assertSame(expected: [], actual: $harness->register->all(schema: 'shippedOrigin'));
	}//end testARefusedRoleWriteIsNotCountedOrStamped()

	/**
	 * Undoing a set nobody adopted is refused as not adopted and leaves the
	 * roles as they were.
	 *
	 * @return void
	 */
	public function testUndoingASetNobodyAdoptedIsRefused(): void {
		$harness = new StarterStoreHarness(test: $this);
		$service = $this->serviceOver(harness: $harness);
		$service->seed();
		$writes = $harness->register->writes;

		$undone = $service->undoAdoption();

		self::assertSame(expected: ['ok' => false, 'reason' => 'not_adopted', 'roleInUse' => ''], actual: $undone);
		self::assertSame(expected: $writes, actual: $harness->register->writes);
	}//end testUndoingASetNobodyAdoptedIsRefused()

	/**
	 * A role without an id cannot hold a grant, so it is not reported in use,
	 * while a role with one is when the grants cannot be read.
	 *
	 * @return void
	 */
	public function testARoleWithoutAnIdIsNotReportedInUse(): void {
		$harness = new StarterStoreHarness(test: $this, unconfigured: [MunicipalRoleSetService::ROLES]);
		$harness->seed(
			schema: 'roleType',
			uuid: '',
			row: ['name' => 'Behandelaar', 'shippedSet' => ShippedSets::MUNICIPAL_ROLES, 'dormant' => true]
		);
		$harness->seed(
			schema: 'roleType',
			uuid: 'role-2',
			row: ['name' => 'Coordinator', 'shippedSet' => ShippedSets::MUNICIPAL_ROLES, 'dormant' => true]
		);
		$service = $this->serviceOver(harness: $harness);

		$offer = $service->offer();

		self::assertNotNull(actual: $offer);
		self::assertSame(expected: ['Coordinator'], actual: $offer['rolesInUse']);
		self::assertCount(expectedCount: 2, haystack: $offer['roles']);
	}//end testARoleWithoutAnIdIsNotReportedInUse()
}//end class
