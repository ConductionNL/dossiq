<?php

/**
 * The term fields are part of the save that receives a case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Listener\IntakeTermStartListener;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sunday 11 October 2026, 21:40: the term starts Monday 12 October at 08:30.
 *
 * @covers \OCA\Dossiq\Listener\IntakeTermStartListener
 * @uses \OCA\Dossiq\Service\Intake\IntakeTermStart
 * @uses \OCA\Dossiq\Service\Intake\ReceiptMoment
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */
class IntakeTermStartListenerTest extends TestCase {

	private const SUNDAY = '2026-10-11T21:40:00+02:00';

	private const MONDAY = '2026-10-12T08:30:00+02:00';

	/**
	 * Build the listener: a calendar that opens Monday 08:30, a clock on Sunday 21:40.
	 *
	 * @param string $slug What the schema resolves to.
	 *
	 * @return IntakeTermStartListener The listener.
	 */
	private function listener(string $slug = 'case'): IntakeTermStartListener {
		$calendar = $this->createMock(WorkingDayRoll::class);
		$calendar->method('firstWorkingMomentAtOrAfter')->willReturnCallback(
			static function (DateTimeImmutable $moment): DateTimeImmutable {
				$monday = new DateTimeImmutable(self::MONDAY);
				return ($moment < $monday) ? $monday : $moment;
			}
		);

		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturn($slug);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturnCallback(static fn (): DateTimeImmutable => new DateTimeImmutable(self::SUNDAY));

		return new IntakeTermStartListener(
			intake: new IntakeTermStart(calendar: $calendar),
			receipt: new ReceiptMoment(),
			slugResolver: $resolver,
			time: $time,
			logger: new NullLogger(),
		);
	}//end listener()

	/**
	 * A case entity.
	 *
	 * @param array<string, mixed> $data   The case fields.
	 * @param string|null          $status The lifecycle status.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data, ?string $status = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema('12');
		$entity->setObject($data);
		$entity->setStatus($status);

		return $entity;
	}//end entity()

	/**
	 * The create carries the three fields and the start date, on the entity and the event.
	 *
	 * @return void
	 */
	public function testASundayCreateCarriesItsTermInTheCreate(): void {
		$entity = $this->entity(data: ['title' => 'Aanvraag', 'receivedAt' => self::SUNDAY]);
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$data = $entity->getObject();
		$this->assertSame(self::SUNDAY, $data['receivedAt']);
		$this->assertSame(self::MONDAY, $data['termStartsAt']);
		$this->assertTrue($data['receivedOutsideWorkingHours']);
		$this->assertSame('2026-10-12', $data['startDate']);
		$this->assertSame('2026-10-12', $event->getModifiedData()['startDate']);
		$this->assertSame(self::MONDAY, $event->getModifiedData()['termStartsAt']);
	}//end testASundayCreateCarriesItsTermInTheCreate()

	/**
	 * A start date the handler gave stays.
	 *
	 * @return void
	 */
	public function testAGivenStartDateStays(): void {
		$entity = $this->entity(data: ['receivedAt' => self::SUNDAY, 'startDate' => '2026-10-01']);

		$this->listener()->handle(new ObjectCreatingEvent($entity));

		$this->assertSame('2026-10-01', $entity->getObject()['startDate']);
		$this->assertSame(self::MONDAY, $entity->getObject()['termStartsAt']);
	}//end testAGivenStartDateStays()

	/**
	 * A draft case starts no term.
	 *
	 * @return void
	 */
	public function testADraftCreateIsNotStamped(): void {
		$entity = $this->entity(data: ['title' => 'Half'], status: 'draft');
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$this->assertArrayNotHasKey('receivedAt', $entity->getObject());
		$this->assertArrayNotHasKey('termStartsAt', $entity->getObject());
		$this->assertSame([], $event->getModifiedData());
	}//end testADraftCreateIsNotStamped()

	/**
	 * Sending the draft stamps it with the moment it was sent, not when it was begun.
	 *
	 * @return void
	 */
	public function testSendingADraftStartsTheTerm(): void {
		$before = $this->entity(data: ['title' => 'Half', 'registrationDate' => '2026-10-09T10:00:00+02:00'], status: 'draft');
		$after = $this->entity(data: ['title' => 'Complete', 'registrationDate' => '2026-10-09T10:00:00+02:00'], status: 'active');
		$event = new ObjectUpdatingEvent($after, $before);

		$this->listener()->handle($event);

		$this->assertSame((new DateTimeImmutable(self::SUNDAY))->format('c'), $after->getObject()['receivedAt']);
		$this->assertSame(self::MONDAY, $after->getObject()['termStartsAt']);
	}//end testSendingADraftStartsTheTerm()

	/**
	 * An ordinary update stamps nothing; the stamp is written once.
	 *
	 * @return void
	 */
	public function testAnOrdinaryUpdateIsLeftAlone(): void {
		$before = $this->entity(data: ['title' => 'A']);
		$after = $this->entity(data: ['title' => 'B']);
		$event = new ObjectUpdatingEvent($after, $before);

		$this->listener()->handle($event);

		$this->assertArrayNotHasKey('termStartsAt', $after->getObject());
	}//end testAnOrdinaryUpdateIsLeftAlone()

	/**
	 * A case already stamped is not stamped again.
	 *
	 * @return void
	 */
	public function testAStampedCaseIsNotRestamped(): void {
		$entity = $this->entity(data: ['termStartsAt' => '2026-01-05T08:30:00+01:00']);
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$this->assertSame('2026-01-05T08:30:00+01:00', $entity->getObject()['termStartsAt']);
		$this->assertSame([], $event->getModifiedData());
	}//end testAStampedCaseIsNotRestamped()

	/**
	 * Another schema, and the post-persist create, are left alone: no second save.
	 *
	 * @return void
	 */
	public function testAnotherSchemaAndThePostPersistCreateAreLeftAlone(): void {
		$other = $this->entity(data: ['receivedAt' => self::SUNDAY]);
		$this->listener(slug: 'caseTask')->handle(new ObjectCreatingEvent($other));
		$this->assertArrayNotHasKey('termStartsAt', $other->getObject());

		$created = $this->entity(data: ['receivedAt' => self::SUNDAY]);
		$this->listener()->handle(new ObjectCreatedEvent($created));
		$this->assertArrayNotHasKey('termStartsAt', $created->getObject());
	}//end testAnotherSchemaAndThePostPersistCreateAreLeftAlone()
}//end class
