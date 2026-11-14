<?php

/**
 * Edge cases of translating a case type's rights matrix into grant rows.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
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
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows;
use PHPUnit\Framework\TestCase;

/**
 * What is skipped, what the document verbs default to, and how departments match.
 *
 * @covers \OCA\Dossiq\Service\Access\CaseTypeRightsMatrixRows
 */
class CaseTypeRightsMatrixRowsEdgeTest extends TestCase {

	/**
	 * Organisation roles.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const ROLES = [
		['id' => 'r-vv', 'roleName' => 'Vergunningverlener', 'department' => 'Vergunningen', 'ncGroupId' => ' vergunningverleners '],
		['id' => 'r-th', 'roleName' => 'Toezichthouder', 'department' => 'Toezicht', 'ncGroupId' => 'toezichthouders'],
	];

	/**
	 * A right that resolves, with the given overrides.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 *
	 * @return array<string, mixed> The rights row.
	 */
	private function right(array $overrides = []): array {
		return $overrides + ['department' => 'Toezicht', 'role' => 'r-th', 'confidentiality' => 'openbaar', 'actions' => ['read']];
	}//end right()

	/**
	 * The actions of each row one right becomes.
	 *
	 * @param array<string, mixed> $right The rights row.
	 *
	 * @return array<int, array<int, string>> The actions per row.
	 */
	private function actionsOf(array $right): array {
		return array_column(
			(new CaseTypeRightsMatrixRows())->rows([['id' => 'ct-1', 'rightsMatrix' => [$right]]], self::ROLES),
			'actions'
		);
	}//end actionsOf()

	/**
	 * A case type without an id or without a matrix, and a non-array right, are skipped.
	 *
	 * @return void
	 */
	public function testUnusableCaseTypesAndRightsAreSkipped(): void {
		$rows = (new CaseTypeRightsMatrixRows())->rows(
			[
				['id' => '  ', 'rightsMatrix' => [$this->right()]],
				['id' => 'ct-1', 'rightsMatrix' => 'not a matrix'],
				['id' => ' ct-2 ', 'rightsMatrix' => ['not a right', $this->right()]],
			],
			self::ROLES
		);

		self::assertSame(
			[
				[
					'group' => 'toezichthouders',
					'values' => ['toezichthouders'],
					'where' => ['caseType' => 'ct-2', 'confidentiality' => ['$in' => ['openbaar']]],
					'actions' => ['read'],
				],
				[
					'group' => 'toezichthouders',
					'values' => ['toezichthouders'],
					'where' => ['caseType' => 'ct-2', 'confidentiality' => ['$in' => ['openbaar']]],
					'actions' => ['readFiles'],
				],
			],
			$rows
		);
	}//end testUnusableCaseTypesAndRightsAreSkipped()

	/**
	 * Without documentActions, create gives read and add; list alone gives no file rights.
	 *
	 * @return void
	 */
	public function testTheDocumentVerbsFollowTheOldVerbs(): void {
		self::assertSame([['read'], ['create'], ['readFiles'], ['addFiles']], $this->actionsOf($this->right(['actions' => ['read', 'create']])));
		self::assertSame([['read']], $this->actionsOf($this->right(['actions' => ['list']])));
		self::assertSame([['handle']], $this->actionsOf($this->right(['actions' => ['handle']])));
	}//end testTheDocumentVerbsFollowTheOldVerbs()

	/**
	 * Explicit documentActions win over the verbs, and unknown document verbs are dropped.
	 *
	 * @return void
	 */
	public function testExplicitDocumentActionsWin(): void {
		self::assertSame(
			[['read'], ['create'], ['deleteFiles']],
			$this->actionsOf($this->right(['actions' => ['read', 'create'], 'documentActions' => ['delete', 'shred']]))
		);
		self::assertSame([['read'], ['create']], $this->actionsOf($this->right(['actions' => ['read', 'create'], 'documentActions' => []])));
	}//end testExplicitDocumentActionsWin()

	/**
	 * Non-string and blank entries in actions and statuses are ignored, and strings are trimmed.
	 *
	 * @return void
	 */
	public function testOnlyNonBlankStringsCount(): void {
		$rows = (new CaseTypeRightsMatrixRows())->rows(
			[[
				'id' => 'ct-1',
				'rightsMatrix' => [$this->right(['actions' => [' update ', 7, ''], 'statuses' => [' st-1 ', null, '  '], 'documentActions' => 'read'])],
			]],
			self::ROLES
		);

		self::assertCount(1, $rows);
		self::assertSame(['update'], $rows[0]['actions']);
		self::assertSame(['$in' => ['st-1']], $rows[0]['where']['status']);
	}//end testOnlyNonBlankStringsCount()

	/**
	 * `alle` and `ALL` mean every department; a department matches case-insensitively; groups are trimmed and sorted.
	 *
	 * @return void
	 */
	public function testDepartmentsMatchLoosely(): void {
		$values = fn (string $department): array => (new CaseTypeRightsMatrixRows())->rows(
			[['id' => 'ct-1', 'rightsMatrix' => [$this->right(['department' => $department])]]],
			self::ROLES
		)[0]['values'];

		self::assertSame(['toezichthouders', 'vergunningverleners'], $values('alle'));
		self::assertSame(['toezichthouders', 'vergunningverleners'], $values('ALL'));
		self::assertSame(['vergunningverleners'], $values(' vergunningen '));
	}//end testDepartmentsMatchLoosely()

	/**
	 * An empty or non-string `until` is not carried onto the rows.
	 *
	 * @return void
	 */
	public function testABlankUntilIsNotCarried(): void {
		foreach (['', 20270101, null] as $until) {
			$rows = (new CaseTypeRightsMatrixRows())->rows(
				[['id' => 'ct-1', 'rightsMatrix' => [$this->right(['until' => $until])]]],
				self::ROLES
			);
			self::assertArrayNotHasKey('until', $rows[0], var_export($until, true));
		}
	}//end testABlankUntilIsNotCarried()

	/**
	 * The highest level covers every level, in order.
	 *
	 * @return void
	 */
	public function testTheHighestLevelCoversAllLevels(): void {
		$rows = (new CaseTypeRightsMatrixRows())->rows(
			[['id' => 'ct-1', 'rightsMatrix' => [$this->right(['confidentiality' => 'zeer_geheim'])]]],
			self::ROLES
		);

		self::assertSame(CaseTypeRightsMatrixRows::LEVELS, $rows[0]['where']['confidentiality']['$in']);
	}//end testTheHighestLevelCoversAllLevels()
}//end class
