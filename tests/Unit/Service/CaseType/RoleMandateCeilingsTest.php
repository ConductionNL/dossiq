<?php

/**
 * Role mandate ceilings tests
 *
 * The Decide column of a case type's rights by role, read off the mandate
 * register on its own.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\CaseType\RoleMandateCeilings;
use OCA\Dossiq\Service\MandaatCheckService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * One Decide cell per role.
 *
 * @covers \OCA\Dossiq\Service\CaseType\RoleMandateCeilings
 * @uses \OCA\Dossiq\Service\Access\OrganisationRoles
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class RoleMandateCeilingsTest extends TestCase {

	/**
	 * The ceilings over these active mandates, or over an unreadable register.
	 *
	 * @param array<int, array<string, mixed>>|null $mandates The register's rows, null when it cannot be read.
	 *
	 * @return RoleMandateCeilings
	 */
	private function ceilings(?array $mandates): RoleMandateCeilings {
		$register = $this->createMock(originalClassName: MandaatCheckService::class);
		if ($mandates === null) {
			$register->method('activeRows')->willThrowException(
				new RefusedException(rule: 'mandaat-register-unreadable', sentence: 'unreadable', status: RefusedException::STATUS_INDETERMINATE)
			);
		} else {
			$register->method('activeRows')->willReturn($mandates);
		}

		$roles = $this->getMockBuilder(className: OrganisationRoles::class)->disableOriginalConstructor()->onlyMethods(methods: ['all'])->getMock();
		$roles->method('all')->willReturn([['id' => 'r-vv', 'roleName' => 'Vergunningverlener'], ['id' => 'r-intake', 'roleName' => 'Intaker']]);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new RoleMandateCeilings(mandates: $register, roles: $roles, l10n: $l10n);
	}//end ceilings()

	/**
	 * Each role gets its own cell: the highest ceiling, Unlimited, or No; a
	 * role named by name finds the mandates held on its id.
	 *
	 * @return void
	 */
	public function testOneCellPerRole(): void {
		$cells = $this->ceilings(
			mandates: [
				['mandateeRole' => 'r-vv', 'terms' => ['plafondCents' => 2000000]],
				['mandateeRole' => 'r-vv', 'terms' => ['plafondCents' => 7500000]],
				['mandateeRole' => 'r-intake', 'terms' => []],
			]
		)->byRole(caseType: ['slug' => 'ov'], caseTypeId: 'ct-1', roles: ['Vergunningverlener', 'r-intake', 'Toezichthouder']);

		$this->assertSame(
			expected: ['Vergunningverlener' => 'Up to € 75.000', 'r-intake' => 'Unlimited', 'Toezichthouder' => 'No'],
			actual: $cells
		);
	}//end testOneCellPerRole()

	/**
	 * A mandate scoped to another case type does not count; one scoped to the
	 * identifier does.
	 *
	 * @return void
	 */
	public function testScopedMandates(): void {
		$cells = $this->ceilings(
			mandates: [
				['mandateeRole' => 'r-vv', 'terms' => ['plafondCents' => 900000, 'caseTypes' => ['other']]],
				['mandateeRole' => 'r-vv', 'terms' => ['plafondCents' => 300000, 'caseTypes' => ['OV-1']]],
				['mandateeRole' => 'r-intake', 'terms' => ['plafondCents' => 300000, 'caseTypes' => ['other']]],
			]
		)->byRole(caseType: ['identifier' => 'OV-1'], caseTypeId: 'ct-1', roles: ['r-vv', 'r-intake']);

		$this->assertSame(expected: ['r-vv' => 'Up to € 3.000', 'r-intake' => 'No'], actual: $cells);
	}//end testScopedMandates()

	/**
	 * The register is asked once, for now and for this case type.
	 *
	 * @return void
	 */
	public function testTheRegisterIsAskedForThisCaseType(): void {
		$before   = new DateTimeImmutable();
		$register = $this->createMock(originalClassName: MandaatCheckService::class);
		$register->expects($this->once())
			->method('activeRows')
			->with(
				$this->callback(callback: static fn (DateTimeImmutable $date): bool => $date >= $before && $date <= new DateTimeImmutable()),
				['caseType' => 'ct-7']
			)
			->willReturn([]);
		$roles = $this->getMockBuilder(className: OrganisationRoles::class)->disableOriginalConstructor()->onlyMethods(methods: ['all'])->getMock();
		$roles->method('all')->willReturn([]);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(argumentIndex: 0);

		$cells = (new RoleMandateCeilings(mandates: $register, roles: $roles, l10n: $l10n))
			->byRole(caseType: [], caseTypeId: 'ct-7', roles: ['a', 'b']);

		$this->assertSame(expected: ['a' => 'No', 'b' => 'No'], actual: $cells);
	}//end testTheRegisterIsAskedForThisCaseType()

	/**
	 * An unreadable register makes every role Unknown, and no roles make no cells.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegister(): void {
		$this->assertSame(
			expected: ['r-vv' => 'Unknown', 'Intaker' => 'Unknown'],
			actual: $this->ceilings(mandates: null)->byRole(caseType: [], caseTypeId: 'ct-1', roles: ['r-vv', 'Intaker'])
		);
		$this->assertSame(expected: [], actual: $this->ceilings(mandates: null)->byRole(caseType: [], caseTypeId: 'ct-1', roles: []));
	}//end testAnUnreadableRegister()
}//end class
