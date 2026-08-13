<?php

/**
 * MandaatRegistryService Unit Tests
 *
 * Covers the referential-integrity guard on OrganisatieRol deletion.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Mandaat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mandaat-matrix/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Mandaat;

use OCA\Procest\Service\Mandaat\MandaatRegistryService;
use OCA\Procest\Service\Support\ConfiguredRegistryService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for MandaatRegistryService.
 *
 * @covers \OCA\Procest\Service\Mandaat\MandaatRegistryService
 */
class MandaatRegistryServiceTest extends TestCase {

	/**
	 * The generic registry, mocked.
	 *
	 * @var ConfiguredRegistryService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ConfiguredRegistryService $registry;

	/**
	 * The subject under test.
	 *
	 * @var MandaatRegistryService
	 */
	private MandaatRegistryService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registry = $this->createMock(ConfiguredRegistryService::class);
		$this->service = new MandaatRegistryService($this->registry);
	}//end setUp()

	/**
	 * Stub the two listings the delete guard consults.
	 *
	 * @param array<int, array<string, mixed>> $mandaten Mandaat rows.
	 * @param array<int, array<string, mixed>> $toewijzingen Assignment rows.
	 *
	 * @return void
	 */
	private function stubListings(array $mandaten, array $toewijzingen): void {
		$this->registry->method('list')->willReturnCallback(
			static function (string $key) use ($mandaten, $toewijzingen): array {
				if ($key === MandaatRegistryService::SCHEMA_MANDAAT) {
					return $mandaten;
				}

				if ($key === MandaatRegistryService::SCHEMA_TOEWIJZING) {
					return $toewijzingen;
				}

				return [];
			}
		);
	}//end stubListings()

	/**
	 * An unreferenced role deletes.
	 *
	 * This is the negative control for every test below: without it, a guard
	 * that refused everything would pass all the refusal assertions.
	 *
	 * @return void
	 */
	public function testDeletesAnUnreferencedRole(): void {
		$this->stubListings([], []);
		$this->registry->expects($this->once())
			->method('delete')
			->with(MandaatRegistryService::SCHEMA_ROL, 'role-1');

		$this->service->deleteRole('role-1');
	}//end testDeletesAnUnreferencedRole()

	/**
	 * A role held by a Mandaat is refused.
	 *
	 * ⚠️ The Mandaat schema names the reference `gemandateerdeRol`. An earlier
	 * draft of the guard looked for `organisatieRol`/`rol`/`rolId` only, which
	 * matched nothing on a Mandaat and let the delete through. This test fails
	 * if that regression returns.
	 *
	 * @return void
	 */
	public function testRefusesARoleReferencedByAMandaat(): void {
		$this->stubListings([['id' => 'm-1', 'gemandateerdeRole' => 'role-1']], []);
		$this->registry->expects($this->never())->method('delete');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/1 mandaat/');

		$this->service->deleteRole('role-1');
	}//end testRefusesARoleReferencedByAMandaat()

	/**
	 * A role held by an assignment with no end date is refused.
	 *
	 * @return void
	 */
	public function testRefusesARoleWithAnOpenEndedAssignment(): void {
		$this->stubListings([], [['id' => 't-1', 'roleId' => 'role-1']]);
		$this->registry->expects($this->never())->method('delete');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/1 active role assignment/');

		$this->service->deleteRole('role-1');
	}//end testRefusesARoleWithAnOpenEndedAssignment()

	/**
	 * An EXPIRED assignment does not pin the role.
	 *
	 * History must not block deletion forever — the guard reads `validUntil`,
	 * which is what the shipped schema declares (the spec prose says
	 * `toewijzingTotEnMet`, a property the schema does not have).
	 *
	 * @return void
	 */
	public function testAnExpiredAssignmentDoesNotBlockDeletion(): void {
		$this->stubListings(
			[],
			[['id' => 't-1', 'roleId' => 'role-1', 'validUntil' => '2000-01-01']]
		);
		$this->registry->expects($this->once())->method('delete');

		$this->service->deleteRole('role-1');
	}//end testAnExpiredAssignmentDoesNotBlockDeletion()

	/**
	 * A future end date still counts as active.
	 *
	 * @return void
	 */
	public function testAFutureEndDateStillBlocksDeletion(): void {
		$this->stubListings(
			[],
			[['id' => 't-1', 'roleId' => 'role-1', 'validUntil' => '2999-12-31']]
		);
		$this->registry->expects($this->never())->method('delete');

		$this->expectException(RuntimeException::class);
		$this->service->deleteRole('role-1');
	}//end testAFutureEndDateStillBlocksDeletion()

	/**
	 * A row referencing a DIFFERENT role does not block.
	 *
	 * Guards against a guard that refuses on the mere existence of any row.
	 *
	 * @return void
	 */
	public function testAnUnrelatedReferenceDoesNotBlockDeletion(): void {
		$this->stubListings(
			[['id' => 'm-1', 'gemandateerdeRole' => 'some-other-role']],
			[['id' => 't-1', 'roleId' => 'some-other-role']]
		);
		$this->registry->expects($this->once())->method('delete');

		$this->service->deleteRole('role-1');
	}//end testAnUnrelatedReferenceDoesNotBlockDeletion()

	/**
	 * Both kinds of blocker are reported together.
	 *
	 * @return void
	 */
	public function testReportsBothBlockerKinds(): void {
		$this->stubListings(
			[['id' => 'm-1', 'gemandateerdeRole' => 'role-1']],
			[['id' => 't-1', 'roleId' => 'role-1']]
		);

		$blockers = $this->service->findRoleReferences('role-1');

		$this->assertCount(2, $blockers);
		$this->assertStringContainsString('mandaat', $blockers[0]);
		$this->assertStringContainsString('assignment', $blockers[1]);
	}//end testReportsBothBlockerKinds()

	/**
	 * A nested reference object is resolved through its `id`.
	 *
	 * @return void
	 */
	public function testResolvesANestedReferenceObject(): void {
		$this->stubListings([['id' => 'm-1', 'gemandateerdeRole' => ['id' => 'role-1']]], []);
		$this->registry->expects($this->never())->method('delete');

		$this->expectException(RuntimeException::class);
		$this->service->deleteRole('role-1');
	}//end testResolvesANestedReferenceObject()
}//end class
