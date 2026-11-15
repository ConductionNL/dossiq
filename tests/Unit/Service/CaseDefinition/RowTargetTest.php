<?php

/**
 * Unit tests for RowTarget: retargeting a row write to another schema or
 * register returns a new target that keeps the object service and strategy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\RowTarget;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\RowTarget
 */
class RowTargetTest extends TestCase {

	public function testInSchemaKeepsTheRegisterAndLeavesTheOriginalAlone(): void {
		$service = new stdClass();
		$target = new RowTarget(objectService: $service, register: 'dossiq', schema: 'case', strategy: 'skip');

		$moved = $target->inSchema('statusType');

		$this->assertNotSame($target, $moved);
		$this->assertSame($service, $moved->objectService);
		$this->assertSame('dossiq', $moved->register);
		$this->assertSame('statusType', $moved->schema);
		$this->assertSame('skip', $moved->strategy);
		$this->assertSame('case', $target->schema);
	}//end testInSchemaKeepsTheRegisterAndLeavesTheOriginalAlone()

	public function testInRegisterReplacesRegisterAndSchemaOnly(): void {
		$service = new stdClass();
		$target = new RowTarget(objectService: $service, register: 'dossiq', schema: 'case', strategy: 'overwrite');

		$moved = $target->inRegister('pipelinq', 'partyKindAcceptance');

		$this->assertSame($service, $moved->objectService);
		$this->assertSame('pipelinq', $moved->register);
		$this->assertSame('partyKindAcceptance', $moved->schema);
		$this->assertSame('overwrite', $moved->strategy);
		$this->assertSame('dossiq', $target->register);
		$this->assertSame('case', $target->schema);
	}//end testInRegisterReplacesRegisterAndSchemaOnly()
}//end class
