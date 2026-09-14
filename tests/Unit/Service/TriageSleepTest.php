<?php

/**
 * Nothing happens until 1 March, and then it comes back to the queue.
 *
 * The three scenarios the spec separates are driven separately, because they
 * fail in different directions. An item slept leaves the queue. An item whose
 * date has passed is back in the queue and carries nobody's name, because the
 * person who slept it may have left. And an accepted case with a running term
 * cannot be slept at all: a sleep that quietly paused a statutory clock would
 * be a suspension nobody declared, with none of the grounds or notices a
 * suspension owes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTime;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Intake\TriageSleep;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the triage sleep and the sweep that wakes it.
 *
 * @covers \OCA\Dossiq\Service\Intake\TriageSleep
 */
class TriageSleepTest extends TestCase {

	/**
	 * The day the clock reads.
	 */
	private const TODAY = '2026-09-14 11:00:00';

	/**
	 * The intake log, mocked.
	 *
	 * @var IntakeLog&MockObject
	 */
	private IntakeLog $log;

	/**
	 * Entries the log answers with, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * Amendments the log was asked to write, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $amended = [];

	/**
	 * Whether the log accepts an amendment.
	 *
	 * @var boolean
	 */
	private bool $amendLands = true;

	/**
	 * Build the mocked log and the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->entries = [];
		$this->amended = [];
		$this->amendLands = true;

		$this->log = $this->createMock(originalClassName: IntakeLog::class);
		$this->log->method('find')->willReturnCallback(
			fn (string $entryId): ?array => ($this->entries[$entryId] ?? null)
		);
		$this->log->method('amend')->willReturnCallback(
			function (string $entryId, array $changes): bool {
				if ($this->amendLands === false) {
					return false;
				}

				$this->amended[$entryId] = array_merge(($this->amended[$entryId] ?? []), $changes);
				$this->entries[$entryId] = array_merge(($this->entries[$entryId] ?? []), $changes);

				return true;
			}
		);
		$this->log->method('search')->willReturnCallback(
			function (array $filters = []): array {
				$outcome = (string)($filters['outcome'] ?? '');

				return array_values(
					array_filter(
						$this->entries,
						static fn (array $entry): bool => ((string)($entry['outcome'] ?? '') === $outcome)
					)
				);
			}
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return TriageSleep The sleep.
	 */
	private function sleepService(): TriageSleep {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime(self::TODAY));

		return new TriageSleep(log: $this->log, time: $time);
	}//end sleepService()

	/**
	 * Seed one triage item.
	 *
	 * @param array<string, mixed> $overrides Fields to set on the entry.
	 *
	 * @return string The entry id.
	 */
	private function seedItem(array $overrides = []): string {
		$entry = array_merge(
			[
				'id' => 'entry-1',
				'subject' => 'Melding kapotte lantaarnpaal',
				'outcome' => IntakeLog::OUTCOME_QUARANTINED,
				'case' => '',
			],
			$overrides
		);

		$this->entries[(string)$entry['id']] = $entry;

		return (string)$entry['id'];
	}//end seedItem()

	/**
	 * Nothing happens until 1 March, and the reason is recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testAnItemSleepsUntilADateWithItsReason(): void {
		$entryId = $this->seedItem();

		$record = $this->sleepService()->sleep(
			entryId: $entryId,
			until: '2027-03-01',
			reason: 'Wachten op het bestemmingsplan.',
			actorId: 'jdevries'
		);

		$this->assertSame('2027-03-01', $record['sleepUntil']);
		$this->assertSame('Wachten op het bestemmingsplan.', $record['sleepReason']);
		$this->assertSame('jdevries', $this->amended[$entryId]['sleptBy']);
	}//end testAnItemSleepsUntilADateWithItsReason()

	/**
	 * A sleeping item leaves the triage queue.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testASleepingItemLeavesTheQueue(): void {
		$entryId = $this->seedItem();
		$service = $this->sleepService();

		$service->sleep(
			entryId: $entryId,
			until: '2027-03-01',
			reason: 'Wachten op het bestemmingsplan.',
			actorId: 'jdevries'
		);

		$queue = $service->awake(queue: array_values($this->entries));

		$this->assertSame([], $queue);
		$this->assertTrue($service->isAsleep(entry: $this->entries[$entryId]));
	}//end testASleepingItemLeavesTheQueue()

	/**
	 * An item whose date has passed is in the queue, and unassigned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testAnItemReturnsToTheQueueUnassigned(): void {
		$entryId = $this->seedItem(
			overrides: [
				'sleepUntil' => '2026-09-13',
				'sleepReason' => 'Wachten op het bestemmingsplan.',
				'sleptBy' => 'someone-who-left',
			]
		);
		$service = $this->sleepService();

		$woken = $service->wakeDue();

		$this->assertSame([$entryId], $woken);
		$this->assertSame('', $this->entries[$entryId]['sleepUntil']);
		$this->assertSame('', $this->entries[$entryId]['sleptBy']);
		$this->assertCount(1, $service->awake(queue: array_values($this->entries)));
	}//end testAnItemReturnsToTheQueueUnassigned()

	/**
	 * Waking keeps the reason, so the queue can still say why it slept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testWakingKeepsTheReason(): void {
		$entryId = $this->seedItem(
			overrides: ['sleepUntil' => '2026-09-13', 'sleepReason' => 'Wachten op het bestemmingsplan.']
		);

		$this->sleepService()->wakeDue();

		$this->assertSame('Wachten op het bestemmingsplan.', $this->entries[$entryId]['sleepReason']);
	}//end testWakingKeepsTheReason()

	/**
	 * An item that is still asleep is not woken by the sweep.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testTheSweepLeavesAnItemThatIsStillAsleep(): void {
		$this->seedItem(overrides: ['sleepUntil' => '2027-03-01']);

		$this->assertSame([], $this->sleepService()->wakeDue());
	}//end testTheSweepLeavesAnItemThatIsStillAsleep()

	/**
	 * Sleeping never stops a statutory clock.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testAnAcceptedCaseCannotBeSleptAndTheRefusalNamesTheSuspension(): void {
		$entryId = $this->seedItem(
			overrides: ['outcome' => IntakeLog::OUTCOME_CASE, 'case' => 'case-1']
		);

		try {
			$this->sleepService()->sleep(
				entryId: $entryId,
				until: '2027-03-01',
				reason: 'Wachten.',
				actorId: 'jdevries'
			);
			$this->fail('The sleep should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(TriageSleep::RULE_ALREADY_A_CASE, $e->getRule());
			$this->assertStringContainsString('Suspend the term', $e->getSentence());
		}

		$this->assertSame([], $this->amended);
	}//end testAnAcceptedCaseCannotBeSleptAndTheRefusalNamesTheSuspension()

	/**
	 * A sleep with no reason is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testASleepWithNoReasonIsRefused(): void {
		$entryId = $this->seedItem();

		try {
			$this->sleepService()->sleep(
				entryId: $entryId,
				until: '2027-03-01',
				reason: '  ',
				actorId: 'jdevries'
			);
			$this->fail('The sleep should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(TriageSleep::RULE_NO_REASON, $e->getRule());
		}

		$this->assertSame([], $this->amended);
	}//end testASleepWithNoReasonIsRefused()

	/**
	 * A date that is not a date, and one that has passed, are both refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-triage-item-sleeps-until-a-date-and-comes-back-req-triage-05
	 */
	public function testADateThatIsNotADateOrHasPassedIsRefused(): void {
		$entryId = $this->seedItem();
		$service = $this->sleepService();

		try {
			$service->sleep(entryId: $entryId, until: 'ooit', reason: 'Wachten.', actorId: 'jdevries');
			$this->fail('The sleep should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(TriageSleep::RULE_NO_DATE, $e->getRule());
		}

		try {
			$service->sleep(
				entryId: $entryId,
				until: '2026-09-01',
				reason: 'Wachten.',
				actorId: 'jdevries'
			);
			$this->fail('The sleep should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(TriageSleep::RULE_DATE_PASSED, $e->getRule());
		}

		$this->assertSame([], $this->amended);
	}//end testADateThatIsNotADateOrHasPassedIsRefused()

	/**
	 * An amendment the log did not take answers 503, not a sleep record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function testASleepThatDidNotLandRefusesRatherThanReportingSuccess(): void {
		$entryId = $this->seedItem();
		$this->amendLands = false;

		try {
			$this->sleepService()->sleep(
				entryId: $entryId,
				until: '2027-03-01',
				reason: 'Wachten.',
				actorId: 'jdevries'
			);
			$this->fail('The sleep should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testASleepThatDidNotLandRefusesRatherThanReportingSuccess()

	/**
	 * An item nothing can find is not slept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function testAnItemThatDoesNotExistIsNotSlept(): void {
		try {
			$this->sleepService()->sleep(
				entryId: 'no-such-entry',
				until: '2027-03-01',
				reason: 'Wachten.',
				actorId: 'jdevries'
			);
			$this->fail('The sleep should have been refused.');
		} catch (RefusedException $e) {
			$this->assertSame(TriageSleep::RULE_NO_ITEM, $e->getRule());
		}
	}//end testAnItemThatDoesNotExistIsNotSlept()
}//end class
