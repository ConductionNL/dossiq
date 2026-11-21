<?php

/**
 * Case status records tests
 *
 * The status records of a process mining report, grouped per case, in time
 * order, with a case's own status history as the fallback.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
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

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\CaseStatusRecords;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Grouping, ordering and the history fallback.
 *
 * @covers \OCA\Dossiq\Service\ProcessMining\CaseStatusRecords
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CaseStatusRecordsTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The grouper under test.
	 *
	 * @var CaseStatusRecords
	 */
	private CaseStatusRecords $records;

	/**
	 * Set up over a frozen date path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->records = new CaseStatusRecords(dates: $this->caseDatesFrozenAt());
	}//end setUp()

	/**
	 * Records of unknown cases are dropped; the rest are sorted by either timestamp key.
	 *
	 * @return void
	 */
	public function testGroupsKnownCasesAndSortsByEitherTimestamp(): void {
		$result = $this->records->byCase(
			records: [
				['case' => 'a', 'statusType' => 's2', '@self' => ['created' => '2026-03-02T10:00:00Z']],
				['case' => 'stranger', 'statusType' => 'x', 'createdAt' => '2026-03-01T10:00:00Z'],
				['case' => 'a', 'statusType' => 's1', 'createdAt' => '2026-03-01T10:00:00Z'],
				['case' => '', 'statusType' => 'y'],
			],
			cases: ['a' => ['id' => 'a']]
		);

		$this->assertSame(['a'], array_keys($result));
		$this->assertSame(['s1', 's2'], array_column($result['a'], 'statusType'));
	}//end testGroupsKnownCasesAndSortsByEitherTimestamp()

	/**
	 * A case without records is read from its JSON status history, held by the actor, by or the assignee.
	 *
	 * @return void
	 */
	public function testACaseWithoutRecordsIsReadFromItsHistory(): void {
		$history = [
			['status' => 'late', 'enteredAt' => '2026-03-03T00:00:00Z', 'by' => ' piet '],
			['status' => 'early', 'enteredAt' => '2026-03-01T00:00:00Z', 'actor' => 'jan'],
			['status' => 'mid', 'enteredAt' => '2026-03-02T00:00:00Z'],
			['status' => '', 'enteredAt' => '2026-03-02T00:00:00Z'],
			['status' => 'no-moment'],
			'not an entry',
		];

		$result = $this->records->byCase(
			records: [],
			cases: [
				'b' => ['assignee' => ' henk ', 'statusHistory' => json_encode($history)],
				'c' => ['statusHistory' => []],
			]
		);

		$this->assertSame(['b'], array_keys($result));
		$this->assertSame(
			[
				['case' => 'b', 'statusType' => 'early', 'createdAt' => '2026-03-01T00:00:00Z', 'actor' => 'jan'],
				['case' => 'b', 'statusType' => 'mid', 'createdAt' => '2026-03-02T00:00:00Z', 'actor' => 'henk'],
				['case' => 'b', 'statusType' => 'late', 'createdAt' => '2026-03-03T00:00:00Z', 'actor' => 'piet'],
			],
			$result['b']
		);
	}//end testACaseWithoutRecordsIsReadFromItsHistory()

	/**
	 * A case that has records is not also read from its history.
	 *
	 * @return void
	 */
	public function testRecordsWinOverTheHistory(): void {
		$result = $this->records->byCase(
			records: [['case' => 'd', 'statusType' => 'stored', 'createdAt' => '2026-03-01T00:00:00Z']],
			cases: ['d' => ['statusHistory' => [['status' => 'history', 'enteredAt' => '2026-03-01T00:00:00Z']]]]
		);

		$this->assertSame(['stored'], array_column($result['d'], 'statusType'));
	}//end testRecordsWinOverTheHistory()

	/**
	 * Records without a usable timestamp keep the order they arrived in.
	 *
	 * A missing key, an empty string and a non-string value all read as "no
	 * timestamp", and a comparison involving one never reorders the pair.
	 *
	 * @return void
	 */
	public function testRecordsWithoutATimestampKeepTheirPlace(): void {
		$result = $this->records->byCase(
			records: [
				['case' => 'e', 'statusType' => 'none'],
				['case' => 'e', 'statusType' => 'later', 'createdAt' => '2026-03-05T00:00:00Z'],
				['case' => 'e', 'statusType' => 'empty', 'createdAt' => ''],
				['case' => 'e', 'statusType' => 'number', '@self' => ['created' => 20260301]],
			],
			cases: ['e' => ['id' => 'e']]
		);

		$this->assertSame(['none', 'later', 'empty', 'number'], array_column($result['e'], 'statusType'));
	}//end testRecordsWithoutATimestampKeepTheirPlace()
}//end class
