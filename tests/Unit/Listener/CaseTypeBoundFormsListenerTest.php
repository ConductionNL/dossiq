<?php

/**
 * CaseTypeBoundFormsListener unit tests.
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
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\BoundFormsCheckInterface;
use OCA\Dossiq\Listener\CaseTypeBoundFormsListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case type save is refused, or its broken forms unpublished, as the check decides.
 *
 * @covers \OCA\Dossiq\Listener\CaseTypeBoundFormsListener
 */
class CaseTypeBoundFormsListenerTest extends TestCase {

	/**
	 * The forms check.
	 *
	 * @var BoundFormsCheckInterface&MockObject
	 */
	private BoundFormsCheckInterface&MockObject $check;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var CaseTypeBoundFormsListener
	 */
	private CaseTypeBoundFormsListener $listener;

	/**
	 * Wire the listener over a mocked check.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->check = $this->createMock(originalClassName: BoundFormsCheckInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new CaseTypeBoundFormsListener(check: $this->check, logger: $this->logger);
	}//end setUp()

	/**
	 * A case type entity.
	 *
	 * @param array<string, mixed> $data Its fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ct-1');
		$entity->setSchema('caseType');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A refusing assessment.
	 *
	 * @return array<string, mixed> The assessment.
	 */
	private static function refusing(): array {
		return ['refused' => true, 'affected' => [['id' => 'f-1']]];
	}//end refusing()

	/**
	 * A create of a case type whose forms the owning app refuses to break is stopped.
	 *
	 * @return void
	 */
	public function testACreateIsRefusedWhenTheCheckRefuses(): void {
		$this->check->method('isCaseType')->willReturn(true);
		$this->check->expects($this->once())->method('assess')
			->with($this->callback(callback: static fn (array $ct): bool => $ct['title'] === 'Bezwaar' && $ct['id'] === 'ct-1'))
			->willReturn(self::refusing());
		$this->check->method('sentenceFor')->willReturn('Form X would open no case.');
		$this->check->method('listed')->willReturn(['Form X']);

		$event = new ObjectCreatingEvent(object: $this->entity(data: ['title' => 'Bezwaar']));
		$this->listener->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: [
				'message' => 'Form X would open no case.',
				'error' => BoundFormsCheckInterface::RULE,
				'code' => BoundFormsCheckInterface::RULE,
				'forms' => ['Form X'],
			],
			actual: $event->getErrors()
		);
	}//end testACreateIsRefusedWhenTheCheckRefuses()

	/**
	 * An update is judged on the new object, and refused the same way.
	 *
	 * @return void
	 */
	public function testAnUpdateIsJudgedOnTheNewObject(): void {
		$this->check->method('isCaseType')->willReturn(true);
		$this->check->expects($this->once())->method('assess')
			->with($this->callback(callback: static fn (array $ct): bool => $ct['title'] === 'New'))
			->willReturn(self::refusing());
		$this->check->method('sentenceFor')->willReturn('refused');
		$this->check->method('listed')->willReturn([]);

		$event = new ObjectUpdatingEvent(newObject: $this->entity(data: ['title' => 'New']), oldObject: $this->entity(data: ['title' => 'Old']));
		$this->listener->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'refused', actual: $event->getErrors()['message']);
	}//end testAnUpdateIsJudgedOnTheNewObject()

	/**
	 * A save the check does not refuse goes through, and nothing is applied before it.
	 *
	 * @return void
	 */
	public function testANonRefusedSaveIsLeftAlone(): void {
		$this->check->method('isCaseType')->willReturn(true);
		$this->check->method('assess')->willReturn(['refused' => false, 'affected' => [['id' => 'f-1']]]);
		$this->check->expects($this->never())->method('apply');
		$this->check->expects($this->never())->method('sentenceFor');

		$event = new ObjectCreatingEvent(object: $this->entity(data: ['title' => 'Bezwaar']));
		$this->listener->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [], actual: $event->getErrors());
	}//end testANonRefusedSaveIsLeftAlone()

	/**
	 * An object that is not a case type is never assessed.
	 *
	 * @return void
	 */
	public function testAnotherObjectIsNotAssessed(): void {
		$this->check->method('isCaseType')->willReturn(false);
		$this->check->expects($this->never())->method('assess');

		$event = new ObjectCreatingEvent(object: $this->entity(data: ['title' => 'A case']));
		$this->listener->handle($event);
		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(data: ['title' => 'A case'])));

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnotherObjectIsNotAssessed()

	/**
	 * An object that cannot be serialised is skipped with a debug line.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectIsSkipped(): void {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willThrowException(new RuntimeException('broken row'));
		$this->check->expects($this->never())->method('isCaseType');
		$this->check->expects($this->never())->method('assess');
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq intake: a saved object could not be read: broken row');

		$event = new ObjectCreatingEvent($entity);
		$this->listener->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUnreadableObjectIsSkipped()

	/**
	 * After a create, broken forms of an unpublishing app are unpublished and logged.
	 *
	 * @return void
	 */
	public function testAfterACreateBrokenFormsAreUnpublished(): void {
		$assessment = ['refused' => false, 'affected' => [['id' => 'f-1']]];
		$this->check->method('isCaseType')->willReturn(true);
		$this->check->method('assess')->willReturn($assessment);
		$this->check->expects($this->once())->method('apply')->with($assessment)->willReturn(['f-1' => 'unpublished']);
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq intake: saving a case type left published forms that open no case of it; they were unpublished',
			['caseType' => 'ct-1', 'forms' => ['f-1' => 'unpublished']]
		);

		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(data: ['title' => 'Bezwaar'])));
	}//end testAfterACreateBrokenFormsAreUnpublished()

	/**
	 * After an update the new object is the one assessed and applied.
	 *
	 * @return void
	 */
	public function testAfterAnUpdateTheNewObjectIsApplied(): void {
		$this->check->method('isCaseType')->willReturn(true);
		$this->check->expects($this->once())->method('assess')
			->with($this->callback(callback: static fn (array $ct): bool => $ct['title'] === 'New'))
			->willReturn(['refused' => false, 'affected' => [['id' => 'f-2']]]);
		$this->check->expects($this->once())->method('apply')->willReturn([]);

		$this->listener->handle(
			new ObjectUpdatedEvent(newObject: $this->entity(data: ['title' => 'New']), oldObject: $this->entity(data: ['title' => 'Old']))
		);
	}//end testAfterAnUpdateTheNewObjectIsApplied()

	/**
	 * Nothing is applied after the save when no form is affected, or when the save was refused.
	 *
	 * @return void
	 */
	public function testNothingIsAppliedWithoutAffectedFormsOrOnARefusal(): void {
		$this->check->method('isCaseType')->willReturn(true);
		$this->check->method('assess')->willReturnOnConsecutiveCalls(
			['refused' => false, 'affected' => []],
			self::refusing(),
		);
		$this->check->expects($this->never())->method('apply');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(data: ['title' => 'A'])));
		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(data: ['title' => 'B'])));
	}//end testNothingIsAppliedWithoutAffectedFormsOrOnARefusal()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnyOtherEventIsIgnored(): void {
		$this->check->expects($this->never())->method('isCaseType');

		$this->listener->handle(new Event());
	}//end testAnyOtherEventIsIgnored()
}//end class
