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

use OCA\Dossiq\Service\CaseType\CaseTypeRightsByRole;
use OCA\Dossiq\Service\CaseTypeResolver;
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
	private function service(?array $matrix): CaseTypeRightsByRole {
		$resolver = $this->createMock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($matrix === null ? [] : ['id' => 'ct-1', 'rightsMatrix' => $matrix]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CaseTypeRightsByRole(resolver: $resolver, l10n: $l10n);
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
			['role' => 'Vergunningverlener', 'seeCases' => 'Vergunningen, Toezicht', 'handle' => 'Yes', 'decide' => 'Yes', 'documents' => 'All'],
			$rows[0]
		);
		$this->assertSame(
			['role' => 'Intaker', 'seeCases' => 'Vergunningen', 'handle' => 'No', 'decide' => 'No', 'documents' => 'Add'],
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
}//end class
