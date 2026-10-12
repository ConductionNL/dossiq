<?php

/**
 * Which save is the moment an object was received.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
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

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * A draft is not received; its create is no receipt, sending it is.
 *
 * @covers \OCA\Dossiq\Service\Intake\ReceiptMoment
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
 */
class ReceiptMomentTest extends TestCase {

	/**
	 * A case entity with a lifecycle status.
	 *
	 * @param string|null $status `draft`, `active` or null.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(?string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setObject(['title' => 'Aanvraag']);
		$entity->setStatus($status);

		return $entity;
	}//end entity()

	/**
	 * The entity's own status decides, and no status is never a draft.
	 *
	 * @return void
	 */
	public function testIsDraftReadsTheStatus(): void {
		$receipt = new ReceiptMoment();

		$this->assertTrue($receipt->isDraft(object: $this->entity(status: 'draft')));
		$this->assertFalse($receipt->isDraft(object: $this->entity(status: 'active')));
		$this->assertFalse($receipt->isDraft(object: $this->entity(status: null)));
	}//end testIsDraftReadsTheStatus()

	/**
	 * A serialised object is read through `@self.status`.
	 *
	 * @return void
	 */
	public function testIsDraftReadsASerialisedObject(): void {
		$receipt = new ReceiptMoment();

		$this->assertTrue($receipt->isDraft(object: ['@self' => ['status' => 'draft']]));
		$this->assertFalse($receipt->isDraft(object: ['@self' => ['status' => 'active']]));
		$this->assertFalse($receipt->isDraft(object: ['title' => 'no envelope']));
		$this->assertFalse($receipt->isDraft(object: 'not an object'));
	}//end testIsDraftReadsASerialisedObject()

	/**
	 * Only draft to not-draft is leaving draft; a create never is.
	 *
	 * @return void
	 */
	public function testLeavesDraft(): void {
		$receipt = new ReceiptMoment();

		$this->assertTrue($receipt->leavesDraft(before: $this->entity(status: 'draft'), after: $this->entity(status: 'active')));
		$this->assertFalse($receipt->leavesDraft(before: $this->entity(status: 'draft'), after: $this->entity(status: 'draft')));
		$this->assertFalse($receipt->leavesDraft(before: $this->entity(status: 'active'), after: $this->entity(status: 'active')));
		$this->assertFalse($receipt->leavesDraft(before: null, after: $this->entity(status: 'active')));
	}//end testLeavesDraft()

	/**
	 * The create of a non-draft is a receipt; the create of a draft is not.
	 *
	 * @return void
	 */
	public function testACreateIsAReceiptUnlessItIsADraft(): void {
		$receipt = new ReceiptMoment();

		$received = $receipt->receivedObject(event: new ObjectCreatedEvent($this->entity(status: null)));
		$this->assertSame('case-1', $received['id'] ?? null);
		$this->assertNull($receipt->receivedObject(event: new ObjectCreatedEvent($this->entity(status: 'draft'))));
	}//end testACreateIsAReceiptUnlessItIsADraft()

	/**
	 * Sending a draft is a receipt, at the moment OpenRegister recorded.
	 *
	 * @return void
	 */
	public function testAnActivationIsAReceipt(): void {
		$receipt = new ReceiptMoment();
		$moment = new DateTimeImmutable('2026-10-11T21:40:00+02:00');
		$event = new ObjectActivatedEvent($this->entity(status: 'active'), $moment);

		$this->assertSame('case-1', $receipt->receivedObject(event: $event)['id'] ?? null);
		$this->assertSame($moment, $receipt->activatedAt(event: $event));
	}//end testAnActivationIsAReceipt()

	/**
	 * Any other event is no receipt and has no activation moment.
	 *
	 * @return void
	 */
	public function testAnUpdateIsNoReceipt(): void {
		$receipt = new ReceiptMoment();
		$entity = $this->entity(status: 'active');
		$event = new ObjectUpdatedEvent($entity, $entity);

		$this->assertNull($receipt->receivedObject(event: $event));
		$this->assertNull($receipt->activatedAt(event: $event));
	}//end testAnUpdateIsNoReceipt()

	/**
	 * The save that receives an object: a create that is no draft, or the update that sends one.
	 *
	 * @return void
	 */
	public function testTheReceivingSaveIsACreateThatIsNoDraftOrTheSendOfADraft(): void {
		$receipt = new ReceiptMoment();
		$active = $this->entity(status: 'active');
		$draft = $this->entity(status: 'draft');

		$this->assertSame($active, $receipt->objectBeingReceived(event: new ObjectCreatingEvent($active)));
		$this->assertNull($receipt->objectBeingReceived(event: new ObjectCreatingEvent($draft)), 'A draft is not received when it is saved.');

		$sent = new ObjectUpdatingEvent($active, $draft);
		$this->assertSame($active, $receipt->objectBeingReceived(event: $sent), 'Sending the draft is its receipt.');
		$this->assertTrue($receipt->sendsDraft(event: $sent));

		$this->assertFalse($receipt->sendsDraft(event: new ObjectCreatingEvent($active)), 'A create sends no draft.');
	}//end testTheReceivingSaveIsACreateThatIsNoDraftOrTheSendOfADraft()

	/**
	 * Every other save receives nothing: an ordinary update, a draft kept a draft, a post-persist event.
	 *
	 * @return void
	 */
	public function testAnyOtherSaveReceivesNothing(): void {
		$receipt = new ReceiptMoment();
		$active = $this->entity(status: 'active');
		$draft = $this->entity(status: 'draft');

		foreach ([new ObjectUpdatingEvent($active, $active), new ObjectUpdatingEvent($draft, $draft), new ObjectUpdatingEvent($active, null)] as $event) {
			$this->assertNull($receipt->objectBeingReceived(event: $event));
			$this->assertFalse($receipt->sendsDraft(event: $event));
		}

		$this->assertNull($receipt->objectBeingReceived(event: new ObjectCreatedEvent($active)), 'Already stored: nothing is being received any more.');
	}//end testAnyOtherSaveReceivesNothing()
}//end class
