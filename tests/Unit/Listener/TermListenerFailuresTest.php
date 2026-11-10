<?php

/**
 * Term listener failure unit tests.
 *
 * The term deadline and the applicant term note never refuse a save: an
 * unreadable payload, a case without an id, terms that cannot be read and a
 * note that cannot be worked out all leave the save as it was, each with its
 * own log line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Listener\ApplicantTermNoteListener;
use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Term\ApplicantTermNote;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the two term listeners do when a read fails.
 *
 * @covers \OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener
 * @covers \OCA\Dossiq\Listener\ApplicantTermNoteListener
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */
class TermListenerFailuresTest extends TestCase {

	/**
	 * The settings: the case schema is `case-schema-id`.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the settings and the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['case_schema' => 'case-schema-id'][$key] ?? ''
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A case update whose entity is real, with the given uuid.
	 *
	 * @param string $uuid The case's uuid.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function caseUpdate(string $uuid): ObjectUpdatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject(['deadline' => '2026-11-02']);
		$entity->setSchema('case-schema-id');
		$entity->setUuid($uuid);

		return new ObjectUpdatingEvent($entity, null);
	}//end caseUpdate()

	/**
	 * A case update whose payload cannot be read.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function unreadableUpdate(): ObjectUpdatingEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willThrowException(new RuntimeException('broken payload'));

		return new ObjectUpdatingEvent($entity, null);
	}//end unreadableUpdate()

	/**
	 * The deadline listener over a mirror double.
	 *
	 * @param CaseDeadlineMirror $mirror The mirror.
	 *
	 * @return CaseDeadlineFollowsTermListener
	 */
	private function deadlineListener(CaseDeadlineMirror $mirror): CaseDeadlineFollowsTermListener {
		return new CaseDeadlineFollowsTermListener(settingsService: $this->settings, mirror: $mirror, logger: $this->logger);
	}//end deadlineListener()

	/**
	 * The note listener over a note double.
	 *
	 * @param ApplicantTermNote $note The note.
	 *
	 * @return ApplicantTermNoteListener
	 */
	private function noteListener(ApplicantTermNote $note): ApplicantTermNoteListener {
		return new ApplicantTermNoteListener(
			settingsService: $this->settings,
			note: $note,
			caseTypes: $this->createMock(CaseTypeReader::class),
			logger: $this->logger
		);
	}//end noteListener()

	/**
	 * An unreadable payload is logged at debug and the deadline is not asked.
	 *
	 * @return void
	 */
	public function testAnUnreadablePayloadLeavesTheDeadline(): void {
		$mirror = $this->createMock(CaseDeadlineMirror::class);
		$mirror->expects($this->never())->method('deadlineFor');
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq: the term deadline listener could not read the payload: broken payload');
		$event = $this->unreadableUpdate();

		$this->deadlineListener(mirror: $mirror)->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAnUnreadablePayloadLeavesTheDeadline()

	/**
	 * A case without an id is left alone and the deadline is not asked.
	 *
	 * @return void
	 */
	public function testACaseWithoutAnIdLeavesTheDeadline(): void {
		$mirror = $this->createMock(CaseDeadlineMirror::class);
		$mirror->expects($this->never())->method('deadlineFor');
		$event = $this->caseUpdate(uuid: '  ');

		$this->deadlineListener(mirror: $mirror)->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testACaseWithoutAnIdLeavesTheDeadline()

	/**
	 * Terms that cannot be read leave the deadline as the save carried it, with a warning.
	 *
	 * @return void
	 */
	public function testUnreadableTermsLeaveTheDeadlineWithAWarning(): void {
		$mirror = $this->createMock(CaseDeadlineMirror::class);
		$mirror->expects($this->once())->method('deadlineFor')->with('case-1')
			->willThrowException(RefusedException::indeterminate(rule: 'terms-readable', sentence: 'The terms could not be read.'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq termijn: the terms of a case could not be read, so its deadline was left as the save carried it',
			['case' => 'case-1', 'error' => 'The terms could not be read.']
		);
		$event = $this->caseUpdate(uuid: 'case-1');

		$this->deadlineListener(mirror: $mirror)->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testUnreadableTermsLeaveTheDeadlineWithAWarning()

	/**
	 * An empty deadline keeps the fallback.
	 *
	 * @return void
	 */
	public function testAnEmptyDeadlineKeepsTheFallback(): void {
		$mirror = $this->createMock(CaseDeadlineMirror::class);
		$mirror->method('deadlineFor')->willReturn('');
		$event = $this->caseUpdate(uuid: 'case-1');

		$this->deadlineListener(mirror: $mirror)->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAnEmptyDeadlineKeepsTheFallback()

	/**
	 * An unreadable payload is logged at debug and no note is worked out.
	 *
	 * @return void
	 */
	public function testAnUnreadablePayloadLeavesTheNote(): void {
		$note = $this->createMock(ApplicantTermNote::class);
		$note->expects($this->never())->method('fieldsFor');
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq: the applicant term note listener could not read the payload: broken payload');
		$event = $this->unreadableUpdate();

		$this->noteListener(note: $note)->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testAnUnreadablePayloadLeavesTheNote()

	/**
	 * A note that cannot be worked out leaves the save as it was, with a warning.
	 *
	 * @return void
	 */
	public function testANoteThatCannotBeWorkedOutLeavesTheSave(): void {
		$note = $this->createMock(ApplicantTermNote::class);
		$note->expects($this->once())->method('fieldsFor')->willThrowException(new RuntimeException('no case type'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq termijn: the applicant term note could not be worked out, so it was left as the save carried it',
			['error' => 'no case type']
		);
		$event = $this->caseUpdate(uuid: 'case-1');
		$event->setModifiedData(['deadline' => '2026-11-16']);

		$this->noteListener(note: $note)->handle($event);

		$this->assertSame(['deadline' => '2026-11-16'], $event->getModifiedData());
	}//end testANoteThatCannotBeWorkedOutLeavesTheSave()

	/**
	 * The note is worked out over the payload with what earlier listeners changed, and only changed fields are written.
	 *
	 * @return void
	 */
	public function testOnlyTheChangedNoteFieldsAreWritten(): void {
		$note = $this->createMock(ApplicantTermNote::class);
		$seen = [];
		$note->expects($this->once())->method('fieldsFor')->willReturnCallback(
			static function (array $case, string $deadline) use (&$seen): array {
				$seen = ['deadline' => $deadline, 'caseDeadline' => $case['deadline'], 'termNote' => $case['termNote']];
				return ['termNote' => 'same', 'termDate' => '2026-11-16', 'termEmpty' => null];
			}
		);
		$entity = new ObjectEntity();
		$entity->setObject(['deadline' => '2026-11-02', 'termNote' => 'same']);
		$entity->setSchema('case-schema-id');
		$event = new ObjectUpdatingEvent($entity, null);
		$event->setModifiedData(['deadline' => '2026-11-16']);

		$this->noteListener(note: $note)->handle($event);

		$this->assertSame(['deadline' => '2026-11-16', 'caseDeadline' => '2026-11-16', 'termNote' => 'same'], $seen);
		$this->assertSame(['deadline' => '2026-11-16', 'termDate' => '2026-11-16'], $event->getModifiedData());
	}//end testOnlyTheChangedNoteFieldsAreWritten()
}//end class
