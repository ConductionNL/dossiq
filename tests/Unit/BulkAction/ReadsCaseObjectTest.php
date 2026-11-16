<?php

/**
 * ReadsCaseObject — the case a bulk step is about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BulkAction;

use OCA\Dossiq\BulkAction\ReadsCaseObject;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * A step with a case id runs the work on it; one without is skipped.
 *
 * @covers \OCA\Dossiq\BulkAction\ReadsCaseObject
 */
final class ReadsCaseObjectTest extends TestCase {

	/**
	 * A class exposing the trait's steps.
	 *
	 * @return object
	 */
	private function subject(): object {
		return new class() {
			use ReadsCaseObject {
				forCase as public;
			}
		};
	}//end subject()

	/**
	 * A step on an object with a uuid hands that uuid to the work.
	 *
	 * @return void
	 */
	public function testWorkRunsOnTheCaseUuid(): void {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$step = new BulkStep(object: $object, parameters: [], commit: false, actor: null);
		$seen = '';

		$result = $this->subject()->forCase(
			step: $step,
			work: function (string $caseId) use (&$seen): BulkActionResult {
				$seen = $caseId;
				return BulkActionResult::applied();
			}
		);

		self::assertSame(expected: 'case-1', actual: $seen);
		self::assertEquals(expected: BulkActionResult::applied(), actual: $result);
	}//end testWorkRunsOnTheCaseUuid()

	/**
	 * A step on an object without a case id is skipped and the work never runs.
	 *
	 * @return void
	 */
	public function testObjectWithoutCaseIdIsSkipped(): void {
		$step = new BulkStep(object: new ObjectEntity(), parameters: [], commit: true, actor: null);
		$ran = false;

		$result = $this->subject()->forCase(
			step: $step,
			work: function () use (&$ran): BulkActionResult {
				$ran = true;
				return BulkActionResult::applied();
			}
		);

		self::assertFalse(condition: $ran);
		self::assertEquals(expected: BulkActionResult::skipped(reason: 'no_case_id'), actual: $result);
	}//end testObjectWithoutCaseIdIsSkipped()
}//end class
