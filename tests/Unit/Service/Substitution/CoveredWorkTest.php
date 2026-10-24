<?php

/**
 * Unit tests for CoveredWork.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Substitution
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

namespace OCA\Dossiq\Tests\Unit\Service\Substitution;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Substitution\CoveredWork;
use OCA\Dossiq\Service\SubstitutionService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The cases a substitute covers, each one a case they may already read.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class CoveredWorkTest extends TestCase {

	/**
	 * The substitute.
	 *
	 * @return IUser The user.
	 */
	private function pieter(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');

		return $user;
	}//end pieter()

	/**
	 * CoveredWork over a substitution answer and a read guard.
	 *
	 * @param array<int, array<string, mixed>> $cases    The cases the substitutions route.
	 * @param array<string, bool>              $readable Which case ids the guard lets the substitute read.
	 *
	 * @return CoveredWork The service.
	 */
	private function coveredWork(array $cases, array $readable): CoveredWork {
		$substitutions = $this->createMock(SubstitutionService::class);
		$substitutions->expects($this->once())
			->method('getSubstitutedWorkFor')
			->with('pieter')
			->willReturn(['cases' => $cases, 'tasks' => []]);

		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturnCallback(
			static fn (string $caseId, IUser $user): bool => ($readable[$caseId] ?? false) && $user->getUID() === 'pieter'
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => ($uid === 'anouk') ? 'Anouk Bakker' : null
		);

		return new CoveredWork(substitutions: $substitutions, caseAccessGuard: $guard, userManager: $users);
	}//end coveredWork()

	/**
	 * A covered case names whom it is covered for, by name, and until when.
	 *
	 * @return void
	 */
	public function testACoveredCaseNamesTheAbsenteeAndTheEnd(): void {
		$cases = $this->coveredWork(
			cases: [['id' => 'c1', '_substituted' => ['absentee' => 'anouk', 'substitutionId' => 's1', 'until' => '2026-10-10']]],
			readable: ['c1' => true]
		)->casesFor(user: $this->pieter());

		$this->assertCount(expectedCount: 1, haystack: $cases);
		$this->assertSame(
			expected: ['absentee' => 'anouk', 'name' => 'Anouk Bakker', 'until' => '2026-10-10'],
			actual: $cases[0]['coveredFor']
		);
	}//end testACoveredCaseNamesTheAbsenteeAndTheEnd()

	/**
	 * A case the substitute may not read is left out: covering never widens access.
	 *
	 * @return void
	 */
	public function testACaseTheSubstituteMayNotReadIsLeftOut(): void {
		$cases = $this->coveredWork(
			cases: [
				['id' => 'c1', '_substituted' => ['absentee' => 'anouk', 'until' => '']],
				['id' => 'c2', '_substituted' => ['absentee' => 'anouk', 'until' => '']],
				['_substituted' => ['absentee' => 'anouk', 'until' => '']],
			],
			readable: ['c1' => true, 'c2' => false]
		)->casesFor(user: $this->pieter());

		$this->assertSame(expected: ['c1'], actual: array_column($cases, 'id'));
	}//end testACaseTheSubstituteMayNotReadIsLeftOut()

	/**
	 * A covered case that is closed, a draft or hidden from lists is not open work.
	 *
	 * @return void
	 */
	public function testACaseOutsideTheOpenListsIsLeftOut(): void {
		$sub = ['absentee' => 'anouk', 'until' => ''];
		$cases = $this->coveredWork(
			cases: [
				['id' => 'open', '_substituted' => $sub],
				['id' => 'closed', 'endDate' => '2026-07-01', '_substituted' => $sub],
				['id' => 'draft', 'isDraft' => true, '_substituted' => $sub],
				['id' => 'hidden', 'statusHiddenInLists' => true, '_substituted' => $sub],
			],
			readable: ['open' => true, 'closed' => true, 'draft' => true, 'hidden' => true]
		)->casesFor(user: $this->pieter());

		$this->assertSame(expected: ['open'], actual: array_column($cases, 'id'));
	}//end testACaseOutsideTheOpenListsIsLeftOut()

	/**
	 * An absentee without a display name is named by their user id.
	 *
	 * @return void
	 */
	public function testAnAbsenteeWithoutADisplayNameIsNamedByUid(): void {
		$cases = $this->coveredWork(
			cases: [['id' => 'c1', '_substituted' => ['absentee' => 'gone', 'until' => '']]],
			readable: ['c1' => true]
		)->casesFor(user: $this->pieter());

		$this->assertSame(expected: 'gone', actual: $cases[0]['coveredFor']['name']);
	}//end testAnAbsenteeWithoutADisplayNameIsNamedByUid()
}//end class
