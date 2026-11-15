<?php

/**
 * The derived-status timeline listener on saves it cannot use and on a status lookup that fails.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseTimelineInterface;
use OCA\Dossiq\Listener\DerivedStatusTimelineListener;
use OCA\Dossiq\Service\Status\DerivedStatusJournal;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of DerivedStatusTimelineListener.
 *
 * @covers \OCA\Dossiq\Listener\DerivedStatusTimelineListener
 * @uses   \OCA\Dossiq\Service\Status\DerivedStatusJournal
 * @uses   \OCA\Dossiq\Service\Timeline\StatusDisplayName
 */
class DerivedStatusTimelineListenerEdgesTest extends TestCase {

	/**
	 * The journal staged moves are taken from.
	 *
	 * @var DerivedStatusJournal
	 */
	private DerivedStatusJournal $journal;

	/**
	 * The timeline.
	 *
	 * @var CaseTimelineInterface&MockObject
	 */
	private CaseTimelineInterface&MockObject $timeline;

	/**
	 * The status lookup.
	 *
	 * @var StatusTypeLookup&MockObject
	 */
	private StatusTypeLookup&MockObject $statuses;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->journal = new DerivedStatusJournal();
		$this->timeline = $this->createMock(CaseTimelineInterface::class);
		$this->statuses = $this->createMock(StatusTypeLookup::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A create is not a landed move, and the staged move stays for the update.
	 *
	 * @return void
	 */
	public function testACreateRecordsNothingAndLeavesTheStagedMove(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-open', toStatus: 'st-done');
		$this->timeline->expects(self::never())->method('record');

		$this->listener()->handle(new ObjectCreatedEvent(self::saved(id: 'case-1', status: 'st-done')));

		self::assertSame(['from' => 'st-open', 'to' => 'st-done'], array_intersect_key((array)$this->journal->take(caseId: 'case-1'), ['from' => 1, 'to' => 1]));
	}//end testACreateRecordsNothingAndLeavesTheStagedMove()

	/**
	 * An unreadable saved case is logged and records nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableSaveIsLoggedAndRecordsNothing(): void {
		$entity = new class extends ObjectEntity {
			/**
			 * Fail to serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('unreadable');
			}//end jsonSerialize()
		};
		$this->timeline->expects(self::never())->method('record');
		$this->logger->expects(self::once())->method('debug')->with('Dossiq timeline: the derived move could not read the saved case: unreadable');

		$this->listener()->handle(new ObjectUpdatedEvent($entity, $entity));
	}//end testAnUnreadableSaveIsLoggedAndRecordsNothing()

	/**
	 * A saved case without any id records nothing.
	 *
	 * @return void
	 */
	public function testASaveWithoutAnIdRecordsNothing(): void {
		$this->timeline->expects(self::never())->method('record');

		$entity = self::saved(id: null, status: 'st-done');
		$this->listener()->handle(new ObjectUpdatedEvent($entity, $entity));
	}//end testASaveWithoutAnIdRecordsNothing()

	/**
	 * A save with nothing staged for it records nothing.
	 *
	 * @return void
	 */
	public function testASaveWithNothingStagedRecordsNothing(): void {
		$this->timeline->expects(self::never())->method('record');

		$entity = self::saved(id: 'case-1', status: 'st-done');
		$this->listener()->handle(new ObjectUpdatedEvent($entity, $entity));
	}//end testASaveWithNothingStagedRecordsNothing()

	/**
	 * A public label that cannot be read keeps the move internal, with its derived wording.
	 *
	 * @return void
	 */
	public function testAFailingPublicLabelKeepsTheMoveInternal(): void {
		$this->journal->stage(caseId: 'case-1', fromStatus: 'st-open', toStatus: 'st-done');
		$this->statuses->method('nameFor')->willReturnMap([['st-open', 'Open'], ['st-done', 'Gereed']]);
		$this->statuses->method('announcedLabelOf')->willThrowException(new RuntimeException('lookup down'));

		$this->timeline->expects(self::once())->method('record')->with(
			'case-1',
			self::anything(),
			'Status ging van Open naar Gereed omdat de zaak daaraan voldoet',
			self::callback(static fn (array $fields): bool => $fields['from'] === 'st-open' && $fields['to'] === 'st-done'),
			CaseTimelineInterface::INTERNAL,
		);

		$entity = self::saved(id: 'case-1', status: 'st-done');
		$this->listener()->handle(new ObjectUpdatedEvent($entity, $entity));
	}//end testAFailingPublicLabelKeepsTheMoveInternal()

	/**
	 * The listener.
	 *
	 * @return DerivedStatusTimelineListener The listener.
	 */
	private function listener(): DerivedStatusTimelineListener {
		return new DerivedStatusTimelineListener(
			journal: $this->journal,
			timeline: $this->timeline,
			statuses: $this->statuses,
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * A saved case.
	 *
	 * @param string|null $id     The uuid, or null for none.
	 * @param string      $status The status it landed on.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function saved(?string $id, string $status): ObjectEntity {
		$entity = new ObjectEntity();
		if ($id !== null) {
			$entity->setUuid($id);
		}

		$entity->setSchema('case');
		$entity->setObject(['status' => $status]);

		return $entity;
	}//end saved()
}//end class
