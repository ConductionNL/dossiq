<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\PersonLinkListener;
use OCA\Dossiq\Service\People\CaseRoleProjection;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The listener hands a person link to the projection, and never throws.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
 */
class PersonLinkListenerTest extends TestCase {

	/**
	 * The projection.
	 *
	 * @var CaseRoleProjection&MockObject
	 */
	private CaseRoleProjection&MockObject $projection;

	/**
	 * The listener under test.
	 *
	 * @var PersonLinkListener
	 */
	private PersonLinkListener $listener;

	/**
	 * Build the listener on a doubled projection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->projection = $this->createMock(originalClassName: CaseRoleProjection::class);
		$this->listener = new PersonLinkListener(
			projection: $this->projection,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A link entity as OpenRegister hands it over.
	 *
	 * @return object The link.
	 */
	private function link(): object {
		return new class {
			/**
			 * The link as a row.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function jsonSerialize(): array {
				return ['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1'];
			}
		};
	}//end link()

	/**
	 * A made or changed link is projected.
	 *
	 * @return void
	 */
	public function testAMadeOrChangedLinkIsProjected(): void {
		$this->projection->expects($this->exactly(2))
			->method('project')
			->with(['objectUuid' => 'case-1', 'contactUid' => 'user:jan', 'role' => 'rt-1'])
			->willReturn('role-7');
		$this->projection->expects($this->never())->method('retire');

		$this->listener->handle(event: new PersonLinkedEvent($this->link()));
		$this->listener->handle(event: new PersonLinkUpdatedEvent($this->link()));
	}//end testAMadeOrChangedLinkIsProjected()

	/**
	 * A removed link retires its record.
	 *
	 * @return void
	 */
	public function testARemovedLinkRetiresItsRecord(): void {
		$this->projection->expects($this->never())->method('project');
		$this->projection->expects($this->once())->method('retire')->willReturn(true);

		$this->listener->handle(event: new PersonUnlinkedEvent($this->link()));
	}//end testARemovedLinkRetiresItsRecord()

	/**
	 * Another event is not ours.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->projection->expects($this->never())->method('project');
		$this->projection->expects($this->never())->method('retire');

		$this->listener->handle(event: new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * A failing projection must not fail the link the handler just made.
	 *
	 * @return void
	 */
	public function testAFailingProjectionNeverThrows(): void {
		$this->projection->method('project')->willThrowException(new RuntimeException('OpenRegister is not available'));

		$this->listener->handle(event: new PersonLinkedEvent($this->link()));

		$this->assertTrue(condition: true, message: 'the listener swallowed the failure');
	}//end testAFailingProjectionNeverThrows()
}//end class
