<?php

/**
 * CommitteeMigrationRun carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CommitteeMigrationRun;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\CommitteeMigrationRun
 */
class CommitteeMigrationRunTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$register = 'value-2';
		$schema = 'value-3';
		$output = $this->createMock(originalClassName: IOutput::class);

		$value = new CommitteeMigrationRun(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			output: $output,
		);

		self::assertSame(expected: $objectService, actual: $value->objectService);
		self::assertSame(expected: $register, actual: $value->register);
		self::assertSame(expected: $schema, actual: $value->schema);
		self::assertSame(expected: $output, actual: $value->output);
	}//end testEveryPropertyReadsBack()
}//end class
