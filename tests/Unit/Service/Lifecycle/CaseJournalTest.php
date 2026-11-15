<?php

/**
 * The case journal's write side and its "what happened last" read: append
 * stamps the actor and keeps earlier entries, latest walks backwards and
 * stops on the first wanted type, and the actor is the session uid or nobody.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseJournal
 * @uses   \OCA\Dossiq\Service\Support\RowList
 */
class CaseJournalTest extends TestCase {

	/**
	 * A journal with this uid in session, or none.
	 *
	 * @param string|null $uid The uid.
	 *
	 * @return CaseJournal
	 */
	private function journal(?string $uid): CaseJournal {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new CaseJournal(userSession: $session);
	}//end journal()

	/**
	 * The actor is the session uid, or the empty string without a session.
	 *
	 * @return void
	 */
	public function testTheActorIsTheSessionUidOrNobody(): void {
		$this->assertSame('anna', $this->journal(uid: 'anna')->actor());
		$this->assertSame('', $this->journal(uid: null)->actor());
	}//end testTheActorIsTheSessionUidOrNobody()

	/**
	 * Append keeps earlier entries, stamps moment and actor, and encodes the field.
	 *
	 * @return void
	 */
	public function testAppendStampsAndKeepsEarlierEntries(): void {
		$case = ['id' => 'c-1', CaseJournal::FIELD => json_encode([['type' => 'note', 'at' => '2026-01-01', 'by' => 'piet']])];

		$updated = $this->journal(uid: 'anna')->append(case: $case, entry: ['type' => 'hold', 'until' => '2026-02-01']);

		$this->assertSame('c-1', $updated['id']);
		$this->assertIsString($updated[CaseJournal::FIELD]);
		$entries = json_decode($updated[CaseJournal::FIELD], true);
		$this->assertCount(2, $entries);
		$this->assertSame(['type' => 'note', 'at' => '2026-01-01', 'by' => 'piet'], $entries[0]);
		$this->assertSame(['hold', '2026-02-01', 'anna'], [$entries[1]['type'], $entries[1]['until'], $entries[1]['by']]);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $entries[1]['at']));
	}//end testAppendStampsAndKeepsEarlierEntries()

	/**
	 * Append on a case without a journal starts one.
	 *
	 * @return void
	 */
	public function testAppendStartsAJournal(): void {
		$updated = $this->journal(uid: null)->append(case: [], entry: ['type' => 'autoClose']);

		$entries = json_decode($updated[CaseJournal::FIELD], true);
		$this->assertCount(1, $entries);
		$this->assertSame('', $entries[0]['by']);
	}//end testAppendStartsAJournal()

	/**
	 * Latest returns the newest entry of any wanted type.
	 *
	 * @return void
	 */
	public function testLatestReturnsTheNewestWantedEntry(): void {
		$case = [
			CaseJournal::FIELD => json_encode(
				[
					['type' => 'hold', 'n' => 1],
					['type' => 'release', 'n' => 2],
					['type' => 'hold', 'n' => 3],
					['type' => 'note', 'n' => 4],
				]
			),
		];
		$journal = $this->journal(uid: null);

		$this->assertSame(['type' => 'hold', 'n' => 3], $journal->latest(case: $case, types: ['hold']));
		$this->assertSame(['type' => 'hold', 'n' => 3], $journal->latest(case: $case, types: ['release', 'hold']));
		$this->assertSame(['type' => 'release', 'n' => 2], $journal->latest(case: $case, types: ['release']));
	}//end testLatestReturnsTheNewestWantedEntry()

	/**
	 * Latest is empty when no entry has a wanted type, or there is no journal.
	 *
	 * @return void
	 */
	public function testLatestIsEmptyWithoutAMatch(): void {
		$journal = $this->journal(uid: null);

		$this->assertSame([], $journal->latest(case: [CaseJournal::FIELD => json_encode([['type' => 'note'], ['n' => 1]])], types: ['hold']));
		$this->assertSame([], $journal->latest(case: [], types: ['hold']));
	}//end testLatestIsEmptyWithoutAMatch()
}//end class
