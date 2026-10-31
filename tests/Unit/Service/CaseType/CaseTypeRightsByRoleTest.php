<?php

/**
 * Case type rights by role tests
 *
 * Board DqZaaktypeOverzicht's Rechten card: one row per role, with what the
 * role may do read off the rights matrix (department x role x
 * confidentiality x verbs).
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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\CaseType\CaseTypeRightsByRole;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\MandaatCheckService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The rights matrix projected per role.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeRightsByRole
 *
 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
 */
class CaseTypeRightsByRoleTest extends TestCase {

	/**
	 * Build the service over a case type with this matrix.
	 *
	 * @param array<int, array<string, mixed>>|null $matrix The rights matrix, null for no case type.
	 *
	 * @return CaseTypeRightsByRole
	 */
	private function service(?array $matrix, array $mandates = [], bool $registerUnreadable = false): CaseTypeRightsByRole {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($matrix === null ? [] : ['id' => 'ct-1', 'slug' => 'omgevingsvergunning', 'rightsMatrix' => $matrix]);
		$resolver->method('statusTypesFor')->willReturn([['id' => 'st-1', 'name' => 'Fase 1'], ['id' => 'st-2', 'name' => ['nl' => 'Fase 2', 'en' => 'Phase 2']]]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$mandateService = $this->createMock(MandaatCheckService::class);
		if ($registerUnreadable === true) {
			$mandateService->method('activeRows')->willThrowException(
				new RefusedException(rule: 'mandaat-register-unreadable', sentence: 'unreadable', status: RefusedException::STATUS_INDETERMINATE)
			);
		} else {
			$mandateService->method('activeRows')->willReturn($mandates);
		}

		$roles = $this->getMockBuilder(OrganisationRoles::class)->disableOriginalConstructor()->onlyMethods(['all'])->getMock();
		$roles->method('all')->willReturn([['id' => 'r-vv', 'roleName' => 'Vergunningverlener'], ['id' => 'r-intake', 'roleName' => 'Intaker']]);

		return new CaseTypeRightsByRole(resolver: $resolver, l10n: $l10n, mandates: $mandateService, roles: $roles);
	}//end service()

	/**
	 * One row per role, in the order the roles first appear.
	 *
	 * @return void
	 */
	public function testOneRowPerRole(): void {
		$rows = $this->service(
			[
				['department' => 'Vergunningen', 'role' => 'Vergunningverlener', 'confidentiality' => 'openbaar', 'actions' => ['read', 'list', 'update', 'create', 'delete']],
				['department' => 'Vergunningen', 'role' => 'Intaker', 'confidentiality' => 'openbaar', 'actions' => ['read', 'create']],
				['department' => 'Toezicht', 'role' => 'Vergunningverlener', 'confidentiality' => 'vertrouwelijk', 'actions' => ['read']],
			]
		)->rowsFor(caseTypeId: 'ct-1');

		$this->assertSame(['Vergunningverlener', 'Intaker'], array_column($rows, 'role'));
	}//end testOneRowPerRole()

	/**
	 * See cases names the departments the role reads in; handle, decide and
	 * documents follow the verbs granted in any of the role's rows.
	 *
	 * @return void
	 */
	public function testColumnsFromTheVerbs(): void {
		$rows = $this->service(
			[
				['department' => 'Vergunningen', 'role' => 'Vergunningverlener', 'confidentiality' => 'openbaar', 'actions' => ['read', 'update', 'decide', 'delete']],
				['department' => 'Toezicht', 'role' => 'Vergunningverlener', 'confidentiality' => 'openbaar', 'actions' => ['list']],
				['department' => 'Vergunningen', 'role' => 'Intaker', 'confidentiality' => 'openbaar', 'actions' => ['read', 'create']],
				['department' => '*', 'role' => 'Toezichthouder', 'confidentiality' => 'openbaar', 'actions' => ['read']],
				['department' => 'Vergunningen', 'role' => 'Archivaris', 'confidentiality' => 'openbaar', 'actions' => ['export']],
			]
		)->rowsFor(caseTypeId: 'ct-1');

		$this->assertSame(
			['role' => 'Vergunningverlener', 'seeCases' => 'Vergunningen, Toezicht', 'handle' => 'All phases', 'decide' => 'No', 'documents' => 'All'],
			$rows[0]
		);
		$this->assertSame(
			['role' => 'Intaker', 'seeCases' => 'Vergunningen', 'handle' => 'All phases', 'decide' => 'No', 'documents' => 'Add'],
			$rows[1]
		);
		$this->assertSame(
			['role' => 'Toezichthouder', 'seeCases' => 'All (cases)', 'handle' => 'No', 'decide' => 'No', 'documents' => 'Read'],
			$rows[2]
		);
		$this->assertSame(
			['role' => 'Archivaris', 'seeCases' => 'No', 'handle' => 'No', 'decide' => 'No', 'documents' => 'No'],
			$rows[3]
		);
	}//end testColumnsFromTheVerbs()

	/**
	 * A role limited to statuses names them, a language-map name read as text
	 * (r31 DqZaaktypeOverzicht printed "Array").
	 *
	 * @return void
	 */
	public function testHandleNamesItsStatusesAsText(): void {
		$rows = $this->service(
			[['department' => 'Vergunningen', 'role' => 'Intaker', 'confidentiality' => 'openbaar', 'actions' => ['read', 'update'], 'statuses' => ['st-1', 'st-2']]]
		)->rowsFor(caseTypeId: 'ct-1');

		$this->assertSame('Fase 1, Fase 2', $rows[0]['handle']);
	}//end testHandleNamesItsStatusesAsText()

	/**
	 * A row past its end date grants nothing any more.
	 *
	 * @return void
	 */
	public function testAnExpiredRowGrantsNothing(): void {
		$rows = $this->service(
			[
				['department' => 'Vergunningen', 'role' => 'Intaker', 'confidentiality' => 'openbaar', 'actions' => ['read'], 'until' => '2000-01-01'],
			]
		)->rowsFor(caseTypeId: 'ct-1');

		$this->assertSame([], $rows);
	}//end testAnExpiredRowGrantsNothing()

	/**
	 * No case type answers null; a type without a matrix answers no rows.
	 *
	 * @return void
	 */
	public function testNoCaseTypeAndNoMatrix(): void {
		$this->assertNull($this->service(null)->rowsFor(caseTypeId: 'ct-x'));
		$this->assertSame([], $this->service([])->rowsFor(caseTypeId: 'ct-1'));
	}//end testNoCaseTypeAndNoMatrix()

	/**
	 * The board's Rights card: own team, a phase, a mandate ceiling, document rights.
	 *
	 * @return void
	 */
	public function testTheBoardsRightsCard(): void {
		$rows = $this->service(
			[
				['department' => '$ownTeam', 'role' => 'Intaker', 'confidentiality' => 'intern', 'actions' => ['read', 'update'], 'statuses' => ['st-1'], 'documentActions' => ['read', 'add']],
				['department' => '*', 'role' => 'r-vv', 'confidentiality' => 'geheim', 'actions' => ['read', 'update'], 'documentActions' => ['read', 'add', 'delete']],
				['department' => '*', 'role' => 'Toezichthouder', 'confidentiality' => 'intern', 'actions' => ['read'], 'documentActions' => ['read']],
			],
			[
				['mandateeRole' => 'r-vv', 'terms' => ['plafondCents' => 5000000]],
				['mandateeRole' => 'r-vv', 'terms' => ['plafondCents' => 1000000]],
			]
		)->rowsFor(caseTypeId: 'ct-1');

		$this->assertSame(
			[
				['role' => 'Intaker', 'seeCases' => 'Own team', 'handle' => 'Fase 1', 'decide' => 'No', 'documents' => 'Add'],
				['role' => 'r-vv', 'seeCases' => 'All (cases)', 'handle' => 'All phases', 'decide' => 'Up to € 50.000', 'documents' => 'All'],
				['role' => 'Toezichthouder', 'seeCases' => 'All (cases)', 'handle' => 'No', 'decide' => 'No', 'documents' => 'Read'],
			],
			$rows
		);
	}//end testTheBoardsRightsCard()

	/**
	 * A mandate without a ceiling is Unlimited; a role named by name finds a mandate on its id.
	 *
	 * @return void
	 */
	public function testAMandateWithoutACeilingIsUnlimited(): void {
		$rows = $this->service(
			[['department' => '*', 'role' => 'Vergunningverlener', 'confidentiality' => 'intern', 'actions' => ['read']]],
			[['mandateeRole' => 'r-vv', 'terms' => []]]
		)->rowsFor(caseTypeId: 'ct-1');

		$this->assertSame('Unlimited', $rows[0]['decide']);
	}//end testAMandateWithoutACeilingIsUnlimited()

	/**
	 * An unreadable mandate register says Unknown, not No.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsUnknown(): void {
		$rows = $this->service(
			[['department' => '*', 'role' => 'Intaker', 'confidentiality' => 'intern', 'actions' => ['read']]],
			[],
			true
		)->rowsFor(caseTypeId: 'ct-1');

		$this->assertSame('Unknown', $rows[0]['decide']);
	}//end testAnUnreadableRegisterIsUnknown()
}//end class
