<?php

/**
 * Case type rights rows as OpenRegister matrix rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows;
use PHPUnit\Framework\TestCase;

/**
 * Pins the translation of each rights row key.
 */
class CaseTypeRightsMatrixRowsTest extends TestCase {

	/**
	 * Organisation roles: two in Vergunningen, one in Toezicht, one without a group.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const ROLES = [
		['id' => 'r-intake', 'roleName' => 'Intaker', 'department' => 'Vergunningen', 'ncGroupId' => 'intakers'],
		['id' => 'r-vv', 'roleName' => 'Vergunningverlener', 'department' => 'Vergunningen', 'ncGroupId' => 'vergunningverleners'],
		['id' => 'r-th', 'roleName' => 'Toezichthouder', 'department' => 'Toezicht', 'ncGroupId' => 'toezichthouders'],
		['id' => 'r-loose', 'roleName' => 'Losse rol', 'department' => 'Toezicht', 'ncGroupId' => ''],
	];

	/**
	 * The rows one rights row becomes for case type `ct-1`.
	 *
	 * @param array<string, mixed> $right The rights row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(array $right): array {
		return (new CaseTypeRightsMatrixRows())->rows([['id' => 'ct-1', 'rightsMatrix' => [$right]]], self::ROLES);
	}//end rowsOf()

	/**
	 * Own team, edit limited to a status, documents add: read and read files everywhere, the rest in the status.
	 *
	 * @return void
	 */
	public function testAnIntakerRowWithOwnTeamStatusesAndDocuments(): void {
		$where = ['caseType' => 'ct-1', 'confidentiality' => ['$in' => ['openbaar', 'beperkt_openbaar', 'intern']]];
		$inStatus = $where + ['status' => ['$in' => ['st-ontvangen']]];

		$this->assertSame(
			[
				['group' => 'intakers', 'values' => ['$self'], 'where' => $where, 'actions' => ['read']],
				['group' => 'intakers', 'values' => ['$self'], 'where' => $inStatus, 'actions' => ['create', 'update']],
				['group' => 'intakers', 'values' => ['$self'], 'where' => $where, 'actions' => ['readFiles']],
				['group' => 'intakers', 'values' => ['$self'], 'where' => $inStatus, 'actions' => ['addFiles']],
			],
			$this->rowsOf(
				[
					'department' => '$ownTeam',
					'role' => 'Intaker',
					'confidentiality' => 'intern',
					'actions' => ['read', 'update', 'create'],
					'statuses' => ['st-ontvangen'],
					'documentActions' => ['read', 'add'],
				]
			)
		);
	}//end testAnIntakerRowWithOwnTeamStatusesAndDocuments()

	/**
	 * A department resolves to its roles' groups; `*` to every group; a role by id.
	 *
	 * @return void
	 */
	public function testDepartmentsResolveToTeams(): void {
		$values = fn (string $department): array => $this->rowsOf(
			['department' => $department, 'role' => 'r-th', 'confidentiality' => 'openbaar', 'actions' => ['read']]
		)[0]['values'];

		$this->assertSame(['intakers', 'vergunningverleners'], $values('Vergunningen'));
		$this->assertSame(['intakers', 'toezichthouders', 'vergunningverleners'], $values('*'));
		$this->assertSame('toezichthouders', $this->rowsOf(['department' => 'Toezicht', 'role' => 'r-th', 'confidentiality' => 'openbaar', 'actions' => ['read']])[0]['group']);
	}//end testDepartmentsResolveToTeams()

	/**
	 * Without `documentActions` the old verbs say what documents may do; `until` passes through.
	 *
	 * @return void
	 */
	public function testStoredRowsKeepTheirMeaning(): void {
		$rows = $this->rowsOf(
			['department' => '*', 'role' => 'Vergunningverlener', 'confidentiality' => 'geheim', 'actions' => ['read', 'update', 'delete'], 'until' => '2027-01-01']
		);

		$this->assertSame([['read'], ['update', 'delete'], ['readFiles'], ['addFiles', 'deleteFiles']], array_column($rows, 'actions'));
		$this->assertSame(['2027-01-01'], array_values(array_unique(array_column($rows, 'until'))));
		$this->assertArrayNotHasKey('status', $rows[1]['where'], 'no statuses: every status');
		$this->assertCount(7, $rows[0]['where']['confidentiality']['$in']);
	}//end testStoredRowsKeepTheirMeaning()

	/**
	 * A row that does not resolve is left out whole.
	 *
	 * @return void
	 */
	public function testARowThatDoesNotResolveIsLeftOut(): void {
		$base = ['department' => 'Toezicht', 'role' => 'Toezichthouder', 'confidentiality' => 'intern', 'actions' => ['read']];

		$this->assertSame([], $this->rowsOf(['role' => 'Losse rol'] + $base), 'a role without a group');
		$this->assertSame([], $this->rowsOf(['role' => 'Onbekend'] + $base), 'an unknown role');
		$this->assertSame([], $this->rowsOf(['department' => 'Belastingen'] + $base), 'a department without teams');
		$this->assertSame([], $this->rowsOf(['confidentiality' => 'topgeheim'] + $base), 'an unknown level');
		$this->assertSame([], $this->rowsOf(['department' => ''] + $base), 'no department');
	}//end testARowThatDoesNotResolveIsLeftOut()
}//end class
