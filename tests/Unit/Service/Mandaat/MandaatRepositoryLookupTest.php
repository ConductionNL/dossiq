<?php

/**
 * Unit tests for the MandaatRepository lookups: approving a mandate decision
 * needs every mandate store configured, activates the decision's mandates
 * from now unless they already start, and roles and mandates are listed from
 * their configured schemas or not at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mandaat;

use OCA\Dossiq\Service\Mandaat\MandaatRepository;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Mandaat\MandaatRepository
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class MandaatRepositoryLookupTest extends TestCase {

	private const CONFIG = [
		'register' => 'dossiq',
		'mandaterings_besluit_schema' => 'mandateDecision',
		'mandaat_schema' => 'mandate',
		'organisatie_rol_schema' => 'role',
	];

	private SettingsService&MockObject $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
	}//end setUp()

	/**
	 * Wire a store answering rows per schema (a Throwable to throw), and config.
	 *
	 * @param array<string, mixed> $rows Rows by schema.
	 */
	private function store(array $rows, array $config = self::CONFIG): object {
		$store = new class($rows) {
			/** @var list<array<int|string, mixed>> */
			public array $calls = [];

			public function __construct(private array $rows) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['search', $schema, $filters];
				$rows = $this->rows[$schema] ?? [];
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}

			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				$this->calls[] = ['save', $schema, $object];
				return $object;
			}
		};
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $config[$key] ?? '');
		return $store;
	}//end store()

	private function repository(): MandaatRepository {
		return new MandaatRepository($this->settings, $this->createMock(LoggerInterface::class));
	}//end repository()

	public function testTheApprovalContextNamesTheStoreRegisterAndBothSchemas(): void {
		$store = $this->store([]);

		$this->assertSame(
			['objectService' => $store, 'register' => 'dossiq', 'bSchema' => 'mandateDecision', 'mSchema' => 'mandate'],
			$this->repository()->resolveApprovalContext()
		);
	}//end testTheApprovalContextNamesTheStoreRegisterAndBothSchemas()

	public function testAnUnconfiguredMandateStoreCannotApprove(): void {
		$this->store([], ['register' => 'dossiq', 'mandaterings_besluit_schema' => 'mandateDecision']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Mandaat services not configured');

		$this->repository()->resolveApprovalContext();
	}//end testAnUnconfiguredMandateStoreCannotApprove()

	public function testTheDecisionsMandatesAreActivatedFromNowUnlessTheyAlreadyStart(): void {
		$store = $this->store(
			['mandate' => [['id' => 'm-1', 'status' => 'draft'], ['id' => 'm-2', 'status' => 'draft', 'validFrom' => '2026-01-01'], ['id' => 'm-3', 'validFrom' => '']]]
		);

		$this->repository()->activateMandatenForBesluit($store, 'dossiq', 'mandate', 'dec-1', '2026-11-12');

		$this->assertSame(
			[
				['search', 'mandate', ['mandateDecision' => 'dec-1']],
				['save', 'mandate', ['id' => 'm-1', 'status' => 'active', 'validFrom' => '2026-11-12']],
				['save', 'mandate', ['id' => 'm-2', 'status' => 'active', 'validFrom' => '2026-01-01']],
				['save', 'mandate', ['id' => 'm-3', 'validFrom' => '2026-11-12', 'status' => 'active']],
			],
			$store->calls
		);
	}//end testTheDecisionsMandatesAreActivatedFromNowUnlessTheyAlreadyStart()

	public function testAFailingMandateSearchActivatesNothing(): void {
		$store = $this->store(['mandate' => new RuntimeException('down')]);

		$this->repository()->activateMandatenForBesluit($store, 'dossiq', 'mandate', 'dec-1', '2026-11-12');

		$this->assertCount(1, $store->calls);
	}//end testAFailingMandateSearchActivatesNothing()

	public function testTheRoleIndexMapsNamedRolesToTheirIds(): void {
		$this->store(['role' => [['roleName' => 'Teamleider', 'id' => 'r-1'], ['roleName' => '', 'id' => 'r-2'], ['roleName' => 'Directeur']]]);

		$this->assertSame(['Teamleider' => 'r-1', 'Directeur' => ''], $this->repository()->loadRoleIndex());
	}//end testTheRoleIndexMapsNamedRolesToTheirIds()

	public function testAFailingRoleStoreHasNoRoles(): void {
		$this->store(['role' => new RuntimeException('down')]);

		$this->assertSame([], $this->repository()->loadRoleIndex());
	}//end testAFailingRoleStoreHasNoRoles()

	public function testAnUnconfiguredRoleStoreIsNotSearched(): void {
		$store = $this->store([], ['register' => 'dossiq']);

		$this->assertSame([], $this->repository()->loadRoleIndex());
		$this->assertSame([], $store->calls);
	}//end testAnUnconfiguredRoleStoreIsNotSearched()

	public function testTheDecisionsMandatesAreListed(): void {
		$store = $this->store(['mandate' => [['id' => 'm-1']]]);

		$this->assertSame([['id' => 'm-1']], $this->repository()->findMandatenForBesluit('dec-1'));
		$this->assertSame(['search', 'mandate', ['mandateDecision' => 'dec-1']], $store->calls[0]);
	}//end testTheDecisionsMandatesAreListed()

	public function testAFailingMandateStoreListsNone(): void {
		$this->store(['mandate' => new RuntimeException('down')]);

		$this->assertSame([], $this->repository()->findMandatenForBesluit('dec-1'));
	}//end testAFailingMandateStoreListsNone()

	public function testWithoutAStoreNoMandatesAreListed(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->assertSame([], $this->repository()->findMandatenForBesluit('dec-1'));
	}//end testWithoutAStoreNoMandatesAreListed()
}//end class
