<?php

/**
 * The frozen-record listener on writes it cannot read: no old object, an unreadable payload, an unconfigured record.
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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\FreezeRegistryInterface;
use OCA\Dossiq\Kernel\Contracts\FrozenRecordGuardInterface;
use OCA\Dossiq\Kernel\Contracts\RecordSealInterface;
use OCA\Dossiq\Listener\FrozenRecordListener;
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of FrozenRecordListener.
 *
 * @covers \OCA\Dossiq\Listener\FrozenRecordListener
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class FrozenRecordListenerEdgesTest extends TestCase {

	/**
	 * The registry.
	 *
	 * @var FreezeRegistryInterface&MockObject
	 */
	private FreezeRegistryInterface&MockObject $registry;

	/**
	 * The guard.
	 *
	 * @var FrozenRecordGuardInterface&MockObject
	 */
	private FrozenRecordGuardInterface&MockObject $guard;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = $this->createMock(FreezeRegistryInterface::class);
		$this->guard = $this->createMock(FrozenRecordGuardInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An update without a stored version is not looked up at all.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutAStoredVersionIsLeftAlone(): void {
		$this->registry->expects(self::never())->method('forRecord');

		$event = new ObjectUpdatingEvent(newObject: self::entity(), oldObject: null);
		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUpdateWithoutAStoredVersionIsLeftAlone()

	/**
	 * An update of a record no freeze entry names is not checked.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRecordIsNotChecked(): void {
		$this->registry->method('forRecord')->willReturn(null);
		$this->guard->expects(self::never())->method('assertUpdatable');

		$event = new ObjectUpdatingEvent(newObject: self::entity(), oldObject: self::entity());
		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnconfiguredRecordIsNotChecked()

	/**
	 * An unreadable incoming version of a frozen record is refused with the entry's sentence.
	 *
	 * @return void
	 */
	public function testAnUnreadableWriteToAFrozenRecordIsRefused(): void {
		$definition = self::definition();
		$this->registry->method('forRecord')->willReturn($definition);
		$this->guard->expects(self::once())->method('isFrozen')->with($definition, self::anything())->willReturn(true);
		$this->guard->method('refusal')->with($definition)->willReturn(new RefusedException('record-frozen', 'This record is final.'));
		$this->guard->expects(self::never())->method('assertUpdatable');
		$this->logger->expects(self::once())->method('debug')->with('Dossiq frozen records: a payload could not be read: unreadable');
		$this->logger->expects(self::once())->method('info')->with('Dossiq: refused a write on a frozen record', ['rule' => 'record-frozen']);

		$event = new ObjectUpdatingEvent(newObject: self::unreadable(), oldObject: self::entity());
		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['error' => 'record-frozen', 'message' => 'This record is final.'], $event->getErrors());
	}//end testAnUnreadableWriteToAFrozenRecordIsRefused()

	/**
	 * An unreadable incoming version of a record not yet frozen passes.
	 *
	 * @return void
	 */
	public function testAnUnreadableWriteToAnOpenRecordPasses(): void {
		$this->registry->method('forRecord')->willReturn(self::definition());
		$this->guard->method('isFrozen')->willReturn(false);
		$this->guard->expects(self::never())->method('refusal');

		$event = new ObjectUpdatingEvent(newObject: self::unreadable(), oldObject: self::entity());
		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnreadableWriteToAnOpenRecordPasses()

	/**
	 * A delete whose stored object cannot be read is not checked.
	 *
	 * @return void
	 */
	public function testAnUnreadableDeleteIsNotChecked(): void {
		$this->registry->expects(self::never())->method('forRecord');

		$event = new ObjectDeletingEvent(object: self::unreadable());
		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnreadableDeleteIsNotChecked()

	/**
	 * A delete of a record no freeze entry names is not checked.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredDeleteIsNotChecked(): void {
		$this->registry->method('forRecord')->willReturn(null);
		$this->guard->expects(self::never())->method('assertDeletable');

		$event = new ObjectDeletingEvent(object: self::entity());
		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnconfiguredDeleteIsNotChecked()

	/**
	 * The listener.
	 *
	 * @return FrozenRecordListener The listener.
	 */
	private function listener(): FrozenRecordListener {
		return new FrozenRecordListener(
			registry: $this->registry,
			guard: $this->guard,
			seal: $this->createMock(RecordSealInterface::class),
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * A freeze entry.
	 *
	 * @return FreezeDefinition The definition.
	 */
	private static function definition(): FreezeDefinition {
		return new FreezeDefinition(['schema' => 'decision', 'frozenStatuses' => ['issued']]);
	}//end definition()

	/**
	 * A readable record.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('rec-1');
		$entity->setSchema('decision');
		$entity->setObject(['currentStatus' => 'issued']);

		return $entity;
	}//end entity()

	/**
	 * A record whose payload cannot be read.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function unreadable(): ObjectEntity {
		return new class extends ObjectEntity {
			/**
			 * Fail to serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('unreadable');
			}//end jsonSerialize()
		};
	}//end unreadable()
}//end class
