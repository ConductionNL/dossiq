<?php

/**
 * What is actually ours to move.
 *
 * A queue of forty open cases is not forty pieces of work. The twelve waiting
 * on the applicant and the six waiting on an advisory body are somebody else's
 * turn, and a team lead who cannot see that plans against a number that is
 * nearly twice the real one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\WorkQueueService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\WorkQueueService::tallyWaitingOn
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class WorkQueueWaitingCountTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The service under test.
	 *
	 * @var WorkQueueService
	 */
	private WorkQueueService $queue;

	/**
	 * Build the queue service; the tally itself touches nothing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->queue = new WorkQueueService(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			engineTasks: $this->createMock(originalClassName: EngineTaskInbox::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			dates: $this->caseDates(),
		);
	}//end setUp()

	/**
	 * Build a queue of open cases: twelve on the applicant, six on a third
	 * party, twenty-two ours.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fortyCases(): array {
		$cases = [];
		for ($i = 0; $i < 12; $i++) {
			$cases[] = ['endDate' => '', 'waitingOn' => 'applicant'];
		}

		for ($i = 0; $i < 6; $i++) {
			$cases[] = ['endDate' => '', 'waitingOn' => 'thirdParty'];
		}

		for ($i = 0; $i < 22; $i++) {
			$cases[] = ['endDate' => '', 'waitingOn' => 'us'];
		}

		return $cases;
	}//end fortyCases()

	/**
	 * A team sees what is theirs to move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheQueueIsCountedByWhoIsWaitedOn(): void {
		self::assertSame(
			expected: ['ours' => 22, 'applicant' => 12, 'thirdParty' => 6, 'total' => 40],
			actual: $this->queue->tallyWaitingOn(cases: $this->fortyCases()),
		);
	}//end testTheQueueIsCountedByWhoIsWaitedOn()

	/**
	 * An undeclared case counts as ours, not as a fourth bucket.
	 *
	 * On a case type nobody has annotated this is every case, and a fourth
	 * bucket would hold the whole queue.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testAnUndeclaredCaseCountsAsOursToMove(): void {
		$counts = $this->queue->tallyWaitingOn(
			cases: [
				['endDate' => ''],
				['endDate' => '', 'waitingOn' => ''],
				['endDate' => '', 'waitingOn' => 'applicant'],
			],
		);

		self::assertSame(
			expected: ['ours' => 2, 'applicant' => 1, 'thirdParty' => 0, 'total' => 3],
			actual: $counts,
		);
	}//end testAnUndeclaredCaseCountsAsOursToMove()

	/**
	 * A closed case is nobody's wait.
	 *
	 * Counted either way it is closed: by its end date, and by the calculated
	 * final-status flag, because a case can carry one without the other on an
	 * instance whose calculations have not been rematerialised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testAClosedCaseIsInNoneOfTheThree(): void {
		$counts = $this->queue->tallyWaitingOn(
			cases: [
				['endDate' => '2026-01-01', 'waitingOn' => 'applicant'],
				['endDate' => '', 'isFinalStatus' => true, 'waitingOn' => 'us'],
				['endDate' => '', 'waitingOn' => 'us'],
			],
		);

		self::assertSame(
			expected: ['ours' => 1, 'applicant' => 0, 'thirdParty' => 0, 'total' => 1],
			actual: $counts,
		);
	}//end testAClosedCaseIsInNoneOfTheThree()
}//end class
