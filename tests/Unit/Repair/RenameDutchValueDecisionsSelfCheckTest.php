<?php

/**
 * RenameDutchValueDecisionsSelfCheckTest.
 *
 * The vocabulary migration's self-check on its own map: an entry whose
 * replacement differs from the source by case or punctuation alone
 * translates nothing, and is named so the map can be corrected.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RenameDutchValueDecisions;
use PHPUnit\Framework\TestCase;

/**
 * Covers caseOnlyEntries() and the summary line.
 *
 * @covers \OCA\Dossiq\Repair\RenameDutchValueDecisions
 */
class RenameDutchValueDecisionsSelfCheckTest extends TestCase {

	/**
	 * Entries that differ only by case or by non-alphanumerics are named, in map order.
	 *
	 * @return void
	 */
	public function testCaseOnlyEntriesAreNamedInMapOrder(): void {
		$offenders = (new RenameDutchValueDecisions())->caseOnlyEntries(
			[
				'status' => [
					'open' => 'Open',
					'afgerond' => 'completed',
					'in_behandeling' => 'In-Behandeling',
				],
				'channel' => [
					'email' => 'email',
					'brief' => 'letter',
				],
			]
		);

		self::assertSame(
			[
				'status: open -> Open',
				'status: in_behandeling -> In-Behandeling',
				'channel: email -> email',
			],
			$offenders
		);
	}//end testCaseOnlyEntriesAreNamedInMapOrder()

	/**
	 * A map of real translations, or an empty map, has no offenders.
	 *
	 * @return void
	 */
	public function testRealTranslationsHaveNoOffenders(): void {
		$decisions = new RenameDutchValueDecisions();

		self::assertSame([], $decisions->caseOnlyEntries(['status' => ['nieuw' => 'new', 'gesloten' => 'closed']]));
		self::assertSame([], $decisions->caseOnlyEntries([]));
	}//end testRealTranslationsHaveNoOffenders()

	/**
	 * Numeric sources are compared as strings.
	 *
	 * @return void
	 */
	public function testNumericSourcesAreComparedAsStrings(): void {
		self::assertSame(
			['priority: 1 -> 1', 'priority: 2 -> 2.'],
			(new RenameDutchValueDecisions())->caseOnlyEntries(['priority' => [1 => '1', 2 => '2.', 3 => 'high', 4 => '0004']])
		);
	}//end testNumericSourcesAreComparedAsStrings()

	/**
	 * The summary line counts the rows rewritten.
	 *
	 * @return void
	 */
	public function testTheSummaryCountsTheRowsRewritten(): void {
		self::assertSame('RenameDutchValues: 7 row value(s) translated.', (new RenameDutchValueDecisions())->summaryMessage(7));
	}//end testTheSummaryCountsTheRowsRewritten()
}//end class
