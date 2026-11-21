<?php

/**
 * Unit tests for RecordImportAnswer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Import;

use OCA\Dossiq\Service\Import\RecordImportAnswer;
use PHPUnit\Framework\TestCase;

/**
 * An answer opens empty under its key and source app, and every outcome counts where it belongs.
 *
 * @covers \OCA\Dossiq\Service\Import\RecordImportAnswer
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class RecordImportAnswerTest extends TestCase {

	/**
	 * The opened answer names the import and counts nothing.
	 *
	 * @return void
	 */
	public function testAnAnswerOpensEmpty(): void {
		$answers = new RecordImportAnswer();

		self::assertSame(
			expected: ['key' => 'woo', 'sourceApp' => 'opencatalogi'] + RecordImportAnswer::NOTHING_YET,
			actual: $answers->opened(import: ['key' => 'woo', 'sourceApp' => 'opencatalogi', 'other' => 'x'])
		);
		self::assertSame(expected: '', actual: $answers->opened(import: [])['sourceApp']);
	}//end testAnAnswerOpensEmpty()

	/**
	 * Each kind raises its counter and joins its list; references are summed and left off the row.
	 *
	 * @return void
	 */
	public function testEveryOutcomeCountsWhereItBelongs(): void {
		$answers = new RecordImportAnswer();
		$answer = $answers->opened(import: ['key' => 'woo']);
		$outcomes = [
			['kind' => 'alreadyImported', 'row' => []],
			['kind' => 'unmigrated', 'row' => []],
			['kind' => 'failed', 'row' => ['requestId' => 'r1', 'reason' => 'no']],
			['kind' => 'duplicate', 'row' => ['requestId' => 'r2', 'repointed' => 1]],
			['kind' => 'migrated', 'row' => ['requestId' => 'r3', 'repointed' => 2]],
			['kind' => 'something else', 'row' => ['requestId' => 'r4']],
		];
		foreach ($outcomes as $outcome) {
			$answer = $answers->counted(answer: $answer, outcome: $outcome);
		}

		self::assertSame(expected: 1, actual: $answer['alreadyImported']);
		self::assertSame(expected: 2, actual: $answer['unmigrated']);
		self::assertSame(expected: [['requestId' => 'r1', 'reason' => 'no']], actual: $answer['failed']);
		self::assertSame(expected: [['requestId' => 'r2']], actual: $answer['duplicates']);
		self::assertSame(expected: 2, actual: $answer['imported'], message: 'An unknown kind counts as a migration.');
		self::assertSame(expected: [['requestId' => 'r3'], ['requestId' => 'r4']], actual: $answer['migrated']);
		self::assertSame(expected: 3, actual: $answer['repointed']);
	}//end testEveryOutcomeCountsWhereItBelongs()
}//end class
