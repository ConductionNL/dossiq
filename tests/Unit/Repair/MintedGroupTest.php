<?php

/**
 * Tests for MintedGroup: every property reads back what was passed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MintedGroup;
use OCP\IGroup;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MintedGroup.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class MintedGroupTest extends TestCase {

	/**
	 * Every property reads back what the constructor got.
	 *
	 * @return void
	 */
	public function testPropertiesReadBack(): void {
		$group = $this->createMock(originalClassName: IGroup::class);
		$subject = new MintedGroup(
			group: $group,
			groupId: 'groupId-value'
		);

		self::assertSame(expected: $group, actual: $subject->group);
		self::assertSame(expected: 'groupId-value', actual: $subject->groupId);
	}//end testPropertiesReadBack()
}//end class
