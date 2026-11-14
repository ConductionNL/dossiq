<?php

/**
 * Announcing a case the intake wrote straight to the register.
 *
 * A case filed through the system store skips OpenRegister's own save events,
 * so the announcer dispatches ObjectCreatedEvent itself. Pinned here: only a
 * real OpenRegister object is announced, the dispatch runs as the system when
 * OpenRegister can elevate, and a failing listener is logged against the case
 * and reported as "not announced" rather than thrown into the intake.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\CaseCreationAnnouncer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The created-event dispatch for intake-written cases.
 *
 * @covers \OCA\Dossiq\Service\Intake\CaseCreationAnnouncer
 */
class CaseCreationAnnouncerTest extends TestCase {

	/**
	 * A saved case object with a uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function savedCase(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');

		return $entity;
	}//end savedCase()

	/**
	 * An object service that can elevate, counting its system runs.
	 *
	 * @return object The fake.
	 */
	private function elevatingService(): object {
		return new class {
			public int $systemRuns = 0;

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;
				return $operation();
			}
		};
	}//end elevatingService()

	/**
	 * The created event carries the saved object and is dispatched as the system.
	 *
	 * @return void
	 */
	public function testTheCreatedEventIsDispatchedAsTheSystem(): void {
		$saved = $this->savedCase();
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->with(
			$this->callback(static fn (object $event): bool => $event instanceof ObjectCreatedEvent && $event->getObject() === $saved)
		);
		$service = $this->elevatingService();

		$announcer = new CaseCreationAnnouncer(dispatcher: $dispatcher, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertTrue(condition: $announcer->announce(objectService: $service, saved: $saved));
		$this->assertSame(expected: 1, actual: $service->systemRuns);
	}//end testTheCreatedEventIsDispatchedAsTheSystem()

	/**
	 * Without runAsSystem the event is dispatched as the caller.
	 *
	 * @return void
	 */
	public function testWithoutElevationTheEventIsStillDispatched(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped');

		$announcer = new CaseCreationAnnouncer(dispatcher: $dispatcher, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertTrue(condition: $announcer->announce(objectService: new \stdClass(), saved: $this->savedCase()));
	}//end testWithoutElevationTheEventIsStillDispatched()

	/**
	 * Anything other than an OpenRegister object is not announced, with a warning.
	 *
	 * @return void
	 */
	public function testANonEntityIsNotAnnounced(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'CaseCreationAnnouncer: the written case is not an OpenRegister object, so it was not announced'
		);

		$announcer = new CaseCreationAnnouncer(dispatcher: $dispatcher, logger: $logger);

		$this->assertFalse(condition: $announcer->announce(objectService: $this->elevatingService(), saved: ['uuid' => 'case-1']));
	}//end testANonEntityIsNotAnnounced()

	/**
	 * A failing listener is logged against the case and answers false.
	 *
	 * @return void
	 */
	public function testAFailingListenerIsLoggedNotThrown(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('listener broke'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'CaseCreationAnnouncer: a listener failed on the new case',
			['case' => 'case-1', 'error' => 'listener broke']
		);

		$announcer = new CaseCreationAnnouncer(dispatcher: $dispatcher, logger: $logger);

		$this->assertFalse(condition: $announcer->announce(objectService: $this->elevatingService(), saved: $this->savedCase()));
	}//end testAFailingListenerIsLoggedNotThrown()

	/**
	 * Whatever the elevation answers is cast to the boolean result.
	 *
	 * @return void
	 */
	public function testTheElevatedAnswerIsTheResult(): void {
		$service = new class {
			/**
			 * @param callable $operation The operation, deliberately not run.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				return 0;
			}
		};
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$announcer = new CaseCreationAnnouncer(dispatcher: $dispatcher, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertFalse(condition: $announcer->announce(objectService: $service, saved: $this->savedCase()));
	}//end testTheElevatedAnswerIsTheResult()
}//end class
