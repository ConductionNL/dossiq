<?php

/**
 * Whether two cases already stand in a parent and sub-case relation.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\CaseHierarchyOverlapGuard;
use PHPUnit\Framework\TestCase;

/**
 * Either direction, every parent reference shape, and unrelated cases.
 *
 * @covers \OCA\Dossiq\Service\Relation\CaseHierarchyOverlapGuard
 */
class CaseHierarchyOverlapGuardTest extends TestCase {

	public function testAParentInEitherDirectionLinksThem(): void {
		$guard = new CaseHierarchyOverlapGuard();

		$this->assertTrue($guard->areLinked(['id' => 'a', 'parentCase' => 'b'], ['id' => 'b']));
		$this->assertTrue($guard->areLinked(['id' => 'a'], ['id' => 'b', 'parentCase' => ['id' => 'a']]));
		$this->assertTrue($guard->areLinked(['@self' => ['id' => 'a']], ['@self' => ['id' => 'b'], 'parentCase' => ['uuid' => 'a']]));
	}

	public function testUnrelatedOrUnidentifiedCasesAreNotLinked(): void {
		$guard = new CaseHierarchyOverlapGuard();

		$this->assertFalse($guard->areLinked(['id' => 'a', 'parentCase' => 'c'], ['id' => 'b', 'parentCase' => 'c']));
		$this->assertFalse($guard->areLinked(['parentCase' => ''], ['parentCase' => '']));
		$this->assertFalse($guard->areLinked(['id' => 'a', 'parentCase' => ['id' => 5]], ['id' => 'b', 'parentCase' => 7]));
		$this->assertFalse($guard->areLinked(['id' => 'a', 'parentCase' => ['name' => 'b']], ['id' => 'b']));
	}
}
