<?php

/**
 * QueueSourceStatus tests: a source that did not answer is named, and the
 * sentence says whether dossiq's own cases are complete.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueSourceStatus;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for QueueSourceStatus.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueSourceStatusTest extends TestCase {

	/**
	 * Every source answered: nothing named, no sentence.
	 *
	 * @return void
	 */
	public function testEverySourceAnswered(): void {
		$this->assertSame(['available' => true, 'unavailable' => [], 'unavailableText' => ''], $this->sourceStatus(unavailable: [])->forReader(userId: 'anouk'));
	}//end testEverySourceAnswered()

	/**
	 * A source that failed is named; the cases are complete while their own source answered.
	 *
	 * @return void
	 */
	public function testAFailedSourceIsNamed(): void {
		$answer = $this->sourceStatus(unavailable: [['source' => 'engine-tasks', 'label' => 'Tasks', 'reason' => 'timeout']])->forReader(userId: 'anouk');

		$this->assertSame([['source' => 'engine-tasks', 'label' => 'Tasks']], $answer['unavailable']);
		$this->assertSame('Source not available: Tasks. Those are not in this queue now. The cases of dossiq itself are complete.', $answer['unavailableText']);
	}//end testAFailedSourceIsNamed()

	/**
	 * When the case source itself failed, the sentence does not call the cases complete.
	 *
	 * @return void
	 */
	public function testTheCasesAreNotCalledCompleteWhenTheirSourceFailed(): void {
		$answer = $this->sourceStatus(unavailable: [['source' => 'assigned-cases', 'label' => 'Cases assigned to you']])->forReader(userId: 'anouk');

		$this->assertSame('Source not available: Cases assigned to you. Those are not in this queue now.', $answer['unavailableText']);
	}//end testTheCasesAreNotCalledCompleteWhenTheirSourceFailed()

	/**
	 * The status over a queue that reports these sources unavailable.
	 *
	 * @param list<array<string, string>> $unavailable The sources.
	 *
	 * @return QueueSourceStatus
	 */
	private function sourceStatus(array $unavailable): QueueSourceStatus {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->expects($this->once())->method('forPerson')->with('anouk')->willReturn(['items' => [], 'unavailable' => $unavailable]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new QueueSourceStatus(queue: $queue, l10n: $l10n);
	}//end status()
}//end class
