<?php

/**
 * Unit tests for CaseMoveLedger.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What a move writes: the conflict check, the closing result, the one save, the record and its side effects.
 *
 * @covers \OCA\Dossiq\Service\Transitions\CaseMoveLedger
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CaseMoveLedgerTest extends TestCase {
	private const MOVE = [
		'fromStatus'         => 'st-open',
		'toStatus'           => 'st-closed',
		'label'              => 'Besluiten',
		'comment'            => 'Klaar.',
		'actor'              => 'jan',
		'evaluatedGuards'    => [['type' => 'role', 'passed' => true]],
		'noWorkflowTemplate' => false,
	];

	/**
	 * What the store, the result writer and the dispatcher were given.
	 *
	 * @var array<int, array>
	 */
	private array $seen = [];

	/**
	 * The errors logged.
	 *
	 * @var array<int, array>
	 */
	private array $errors = [];

	/**
	 * Build the ledger over doubles.
	 *
	 * @param array|null $stored The case the store answers, or null for none.
	 * @param bool $final Whether the status entered is final.
	 * @param string|null $result The closing result the writer resolves.
	 * @param \Throwable|null $updateFails What updating a status record throws.
	 *
	 * @return CaseMoveLedger
	 */
	private function ledger(?array $stored = null, bool $final = false, ?string $result = 'result-1', ?\Throwable $updateFails = null): CaseMoveLedger {
		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturn($stored);
		$store->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->seen[] = ['saveCase', $case];
				return (['@self' => ['version' => 9]] + $case);
			}
		);
		$store->method('writeStatusRecord')->willReturnCallback(
			function (...$arguments): array {
				$this->seen[] = ['writeStatusRecord', $arguments];
				return ['id' => 'rec-1'];
			}
		);
		$store->method('updateStatusRecord')->willReturnCallback(
			static function (array $record) use ($updateFails): array {
				if ($updateFails !== null) {
					throw $updateFails;
				}

				return ($record + ['updated' => true]);
			}
		);
		$store->method('findStatusRecords')->willReturn([['id' => 'rec-0']]);
		$store->method('assertStatusBelongsToCaseType')->willReturnCallback(
			function (string $caseTypeId, string $statusTypeId): void {
				$this->seen[] = ['assertStatusBelongsToCaseType', $caseTypeId, $statusTypeId];
			}
		);

		$results = $this->createMock(CaseResultWriter::class);
		$results->method('isFinalStatus')->willReturn($final);
		$results->method('resolveClosingResult')->willReturnCallback(
			function (string $caseId, string $caseTypeId, ?string $resultTypeId) use ($result): ?string {
				$this->seen[] = ['resolveClosingResult', $caseId, $caseTypeId, $resultTypeId];
				return $result;
			}
		);
		$results->method('archivalFuture')->willReturnCallback(
			static fn (array $case, string $resultTypeId, string $endDate): array => ['archiveNomination' => 'vernietigen', 'archivedFor' => $resultTypeId.' '.$endDate]
		);

		$dispatcher = $this->createMock(SideEffectDispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (array $actions, array $case, array $transitionContext): array {
				$this->seen[] = ['dispatch', $actions, $case, $transitionContext];
				return [['type' => 'notify', 'ok' => true]];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new CaseMoveLedger(store: $store, resultWriter: $results, dispatcher: $dispatcher, logger: $logger);
	}//end ledger()

	/**
	 * The stored case and the history come from the store.
	 *
	 * @return void
	 */
	public function testItReadsTheStore(): void {
		$ledger = $this->ledger(stored: ['id' => 'case-1']);

		$this->assertSame(['id' => 'case-1'], $ledger->load(caseId: 'case-1'));
		$this->assertNull($this->ledger()->load(caseId: 'case-1'));
		$this->assertSame([['id' => 'rec-0']], $ledger->history(caseId: 'case-1'));

		$ledger->assertStatusOfCaseType(caseTypeId: 'ct-1', statusTypeId: 'st-open');
		$this->assertSame([['assertStatusBelongsToCaseType', 'ct-1', 'st-open']], $this->seen);
	}//end testItReadsTheStore()

	/**
	 * A case that is as it was read is handed back; another version, another status or no case is not.
	 *
	 * @return void
	 */
	public function testAChangedCaseIsAConflict(): void {
		$case = ['id' => 'case-1', 'status' => 'st-open', '@self' => ['version' => 3]];

		$this->assertSame($case, $this->ledger(stored: $case)->reloadUnchanged(caseId: 'case-1', readVersion: 3, currentId: 'st-open'));
		$this->assertSame(
			['id' => 'case-1', 'status' => 'st-open', 'version' => 3],
			$this->ledger(stored: ['id' => 'case-1', 'status' => 'st-open', 'version' => 3])->reloadUnchanged(caseId: 'case-1', readVersion: 3, currentId: 'st-open')
		);

		$refused = [];
		foreach ([[4, 'st-open'], [3, 'st-other']] as [$version, $status]) {
			try {
				$this->ledger(stored: $case)->reloadUnchanged(caseId: 'case-1', readVersion: $version, currentId: $status);
			} catch (RefusedException $conflict) {
				$refused[] = [$conflict->getRule(), $conflict->getStatus(), $conflict->getSentence()];
			}
		}

		$sentence = 'Another change reached this case first. Reload it and try again.';
		$this->assertSame([['transition-conflict', 409, $sentence], ['transition-conflict', 409, $sentence]], $refused);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_found');
		$this->ledger()->reloadUnchanged(caseId: 'case-1', readVersion: 3, currentId: 'st-open');
	}//end testAChangedCaseIsAConflict()

	/**
	 * Closing settles the end date, the result and the archival future on the payload and saves nothing.
	 *
	 * Controls: a status that is not final leaves the payload alone, and a close without a result
	 * sets the end date only.
	 *
	 * @return void
	 */
	public function testClosingSettlesThePayload(): void {
		$case = ['id' => 'case-1', 'caseType' => 'ct-1', 'endDate' => '2026-10-01T09:00:00+02:00'];

		$this->assertSame($case, $this->ledger()->close(case: $case, caseId: 'case-1', toStatus: 'st-open', resultTypeId: 'rt-1'));
		$this->assertSame(
			['id' => 'case-1', 'caseType' => 'ct-1', 'endDate' => '2026-10-01'],
			$this->ledger(final: true, result: null)->close(case: $case, caseId: 'case-1', toStatus: 'st-closed', resultTypeId: null)
		);
		$this->assertSame(
			[
				'id'                => 'case-1',
				'caseType'          => 'ct-1',
				'endDate'           => '2026-10-01',
				'result'            => 'result-1',
				'archiveNomination' => 'vernietigen',
				'archivedFor'       => 'rt-1 2026-10-01',
			],
			$this->ledger(final: true)->close(case: $case, caseId: 'case-1', toStatus: 'st-closed', resultTypeId: 'rt-1')
		);
		$this->assertSame(
			date('Y-m-d'),
			$this->ledger(final: true)->close(case: ['id' => 'case-1'], caseId: 'case-1', toStatus: 'st-closed', resultTypeId: 'rt-1')['endDate']
		);
		$this->assertSame([], array_filter($this->seen, static fn (array $call): bool => $call[0] === 'saveCase'));
		$this->assertSame(['resolveClosingResult', 'case-1', 'ct-1', 'rt-1'], $this->seen[1]);
	}//end testClosingSettlesThePayload()

	/**
	 * One save: the status entered, and with a read version that version as `@self.version`.
	 *
	 * @return void
	 */
	public function testTheSaveCarriesTheStatusAndTheVersionRead(): void {
		$ledger = $this->ledger();

		$withVersion = $ledger->save(case: ['id' => 'case-1', 'status' => 'st-open', '@self' => ['version' => 3, 'owner' => 'jan']], toStatus: 'st-closed', readVersion: 3);
		$noSelf      = $ledger->save(case: ['id' => 'case-1', '@self' => 'broken'], toStatus: 'st-closed', readVersion: 7);
		$freeForm    = $ledger->save(case: ['id' => 'case-1', 'status' => 'st-open'], toStatus: 'st-other');

		$this->assertSame(['id' => 'case-1', 'status' => 'st-closed', '@self' => ['version' => 3, 'owner' => 'jan']], $this->seen[0][1]);
		$this->assertSame(['id' => 'case-1', '@self' => ['version' => 7], 'status' => 'st-closed'], $this->seen[1][1]);
		$this->assertSame(['id' => 'case-1', 'status' => 'st-other'], $this->seen[2][1], 'without a read version none is sent');
		$this->assertSame(9, $withVersion[1]);
		$this->assertSame('st-closed', $noSelf[0]['status']);
		$this->assertSame(9, $freeForm[1]);
	}//end testTheSaveCarriesTheStatusAndTheVersionRead()

	/**
	 * The record is written from the move, and the actions run with the move and the record as context.
	 *
	 * @return void
	 */
	public function testTheRecordAndTheSideEffectsFollowTheMove(): void {
		$ledger = $this->ledger();

		$record     = $ledger->record(caseId: 'case-1', move: self::MOVE);
		$dispatched = $ledger->dispatch(actions: [['type' => 'notify']], case: ['id' => 'case-1'], move: self::MOVE, record: $record);

		$this->assertSame(['id' => 'rec-1'], $record);
		$this->assertSame(
			['case-1', 'st-closed', 'st-open', 'Besluiten', 'Klaar.', [['type' => 'role', 'passed' => true]], false, 'jan'],
			$this->seen[0][1]
		);
		$this->assertSame([['type' => 'notify', 'ok' => true]], $dispatched);
		$this->assertSame(
			[
				'dispatch',
				[['type' => 'notify']],
				['id' => 'case-1'],
				['fromStatus' => 'st-open', 'toStatus' => 'st-closed', 'transitionLabel' => 'Besluiten', 'userId' => 'jan', 'statusRecordUuid' => 'rec-1'],
			],
			$this->seen[1]
		);
	}//end testTheRecordAndTheSideEffectsFollowTheMove()

	/**
	 * The results go back onto the record. A record without an id is left alone, and a write that
	 * fails is logged at error and answers the record as it stood.
	 *
	 * @return void
	 */
	public function testTheResultsAreWrittenBackOrLogged(): void {
		$rows = [['type' => 'notify', 'ok' => true]];

		$this->assertSame(
			['id' => 'rec-1', 'dispatchedActions' => $rows, 'updated' => true],
			$this->ledger()->writeBack(record: ['id' => 'rec-1'], dispatched: $rows)
		);
		$this->assertSame(['label' => 'x'], $this->ledger()->writeBack(record: ['label' => 'x'], dispatched: $rows));
		$this->assertSame([], $this->errors);

		$this->assertSame(
			['id' => 'rec-1', 'dispatchedActions' => $rows],
			$this->ledger(updateFails: new RuntimeException('store down'))->writeBack(record: ['id' => 'rec-1'], dispatched: $rows)
		);
		$this->assertSame(
			[['StatusTransitionService: dispatchedActions persist failed', ['exception' => 'store down', 'statusRecord' => 'rec-1']]],
			$this->errors
		);
	}//end testTheResultsAreWrittenBackOrLogged()
}//end class
