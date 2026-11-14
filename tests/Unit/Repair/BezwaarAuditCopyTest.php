<?php

/**
 * BezwaarAuditCopy carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\BezwaarAuditCopy;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\BezwaarAuditCopy
 */
class BezwaarAuditCopyTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$register = 'value-1';
		$schema = 'value-2';
		$output = $this->createMock(originalClassName: IOutput::class);

		$value = new BezwaarAuditCopy(
			register: $register,
			schema: $schema,
			output: $output,
		);

		self::assertSame(expected: $register, actual: $value->register);
		self::assertSame(expected: $schema, actual: $value->schema);
		self::assertSame(expected: $output, actual: $value->output);
	}//end testEveryPropertyReadsBack()
}//end class
