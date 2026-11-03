<?php

/**
 * The assigned group's read rows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\AssignedGroupReadRows;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\AssignedGroupReadRows
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class AssignedGroupReadRowsTest extends TestCase {

	/**
	 * The rows for a user in the given groups, or for nobody.
	 *
	 * @param array<int, string>|null $groups The user's groups; null when nobody is signed in.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsFor(?array $groups): array {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$manager = $this->createMock(originalClassName: IGroupManager::class);
		$user = null;
		if ($groups !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$manager->method('getUserGroupIds')->with($user)->willReturn($groups);
		}

		$session->method('getUser')->willReturn($user);

		return (new AssignedGroupReadRows(session: $session, groups: $manager))->rows();
	}//end rowsFor()

	/**
	 * Each group reads the cases assigned to that group, and only reads them.
	 *
	 * @return void
	 */
	public function testEachGroupReadsItsOwnCases(): void {
		self::assertSame(
			expected: [
				['group' => 'team-woo', 'values' => ['team-woo'], 'actions' => ['read']],
				['group' => 'team-toezicht', 'values' => ['team-toezicht'], 'actions' => ['read']],
			],
			actual: $this->rowsFor(groups: ['team-woo', ' team-toezicht ']),
		);
	}//end testEachGroupReadsItsOwnCases()

	/**
	 * Groups OpenRegister refuses in a provider row, and blanks, give no row.
	 *
	 * @return void
	 */
	public function testReservedAndBlankGroupsGiveNoRow(): void {
		self::assertSame(expected: [], actual: $this->rowsFor(groups: ['admin', 'Authenticated', 'public', '', '  ']));
	}//end testReservedAndBlankGroupsGiveNoRow()

	/**
	 * Without a signed-in user there are no rows.
	 *
	 * @return void
	 */
	public function testNobodySignedInGivesNoRows(): void {
		self::assertSame(expected: [], actual: $this->rowsFor(groups: null));
	}//end testNobodySignedInGivesNoRows()
}//end class
