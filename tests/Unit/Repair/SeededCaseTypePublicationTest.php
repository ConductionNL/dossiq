<?php

/**
 * A publication run of the seeded case types: its register, object service and tally.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SeededCaseTypePublication;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\Dossiq\Repair\SeededCaseTypePublication
 */
class SeededCaseTypePublicationTest extends TestCase {

	/**
	 * A new run holds its object service and register, and counts nothing yet.
	 *
	 * @return void
	 */
	public function testANewRunStartsEmpty(): void {
		$objects = new stdClass();
		$run     = new SeededCaseTypePublication(objectService: $objects, register: '20');

		self::assertSame($objects, $run->objectService);
		self::assertSame('20', $run->register);
		self::assertSame('', $run->schema);
		self::assertSame(
			['published' => 0, 'skipped' => 0, 'failed' => 0, 'unreadable' => false],
			$run->tally()
		);
	}//end testANewRunStartsEmpty()

	/**
	 * The tally reports every count the run built up, in its fixed order.
	 *
	 * @return void
	 */
	public function testTheTallyReportsWhatTheRunCounted(): void {
		$run = new SeededCaseTypePublication(objectService: new stdClass(), register: '20');
		$run->schema     = '28';
		$run->published  = 3;
		$run->skipped    = 2;
		$run->failed     = 1;
		$run->unreadable = true;

		self::assertSame(
			['published' => 3, 'skipped' => 2, 'failed' => 1, 'unreadable' => true],
			$run->tally()
		);
		self::assertSame(['published', 'skipped', 'failed', 'unreadable'], array_keys($run->tally()));
	}//end testTheTallyReportsWhatTheRunCounted()
}//end class
