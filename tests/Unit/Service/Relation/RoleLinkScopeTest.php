<?php

/**
 * RoleLinkScope carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-linking-a-case-with-a-role-writes-the-relation-and-the-record-req-lcr-2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\RoleLinkDefinition;
use OCA\Dossiq\Service\Relation\RoleLinkRegister;
use OCA\Dossiq\Service\Relation\RoleLinkScope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\RoleLinkScope
 */
class RoleLinkScopeTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$location = new RoleLinkRegister(objectService: new \stdClass(), register: 'cases');
		$definition = new RoleLinkDefinition(config: ['role' => 'appeal']);
		$value = new RoleLinkScope(
			location: $location,
			definition: $definition
		);

		self::assertSame(expected: $location, actual: $value->location);
		self::assertSame(expected: $definition, actual: $value->definition);
	}//end testEveryPropertyReadsBack()
}//end class
