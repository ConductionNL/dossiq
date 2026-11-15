<?php

/**
 * TaskKeyAt carries the values it is built with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Task;

use OCA\Dossiq\Service\Task\TaskKeyAt;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Task\TaskKeyAt
 */
class TaskKeyAtTest extends TestCase {

	/**
	 * Every property reads back the value it was built with.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$value = new TaskKeyAt(
			task: 'Review',
			path: 'steps[0].task.effects'
		);

		self::assertSame(expected: 'Review', actual: $value->task);
		self::assertSame(expected: 'steps[0].task.effects', actual: $value->path);
	}//end testEveryPropertyReadsBack()
}//end class
