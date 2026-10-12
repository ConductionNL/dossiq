<?php

/**
 * The relation roles of a case type are read, keyed by role, and a key declared twice is refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\Relation\RoleLinkDefinition;
use OCA\Dossiq\Service\Relation\RoleLinkDefinitionReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\RoleLinkDefinitionReader
 * @uses \OCA\Dossiq\Service\Relation\RoleLinkDefinition
 */
class RoleLinkDefinitionReaderTest extends TestCase {

	/**
	 * Every declared role is read, keyed by its role key.
	 *
	 * @return void
	 */
	public function testEveryRoleIsReadKeyedByRole(): void {
		$roles = (new RoleLinkDefinitionReader())->allOf(caseType: ['relations' => [['role' => 'follows'], ['role' => 'reviews', 'label' => 'Reviews']]]);

		self::assertSame(['follows', 'reviews'], array_keys($roles));
		self::assertContainsOnlyInstancesOf(RoleLinkDefinition::class, $roles);
		self::assertSame('Reviews', $roles['reviews']->label);
	}//end testEveryRoleIsReadKeyedByRole()

	/**
	 * Two roles with one key are refused.
	 *
	 * @return void
	 */
	public function testARoleDeclaredTwiceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('declared twice');
		(new RoleLinkDefinitionReader())->allOf(caseType: ['relations' => [['role' => 'r'], ['role' => 'r']]]);
	}//end testARoleDeclaredTwiceIsRefused()

	/**
	 * A case type without roles declares none.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutRolesDeclaresNone(): void {
		self::assertSame([], (new RoleLinkDefinitionReader())->allOf(caseType: ['title' => 'x']));
	}//end testACaseTypeWithoutRolesDeclaresNone()
}//end class
