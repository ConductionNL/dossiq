<?php

/**
 * LocationBagValidationListener Unit Tests
 *
 * The `location` schema's `source: bag` contract at save time: a malformed id
 * is refused, and whether the BAG holds the id is asked of integriq only where
 * the check is switched on, refusing when no clear answer comes (decision 224).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCA\Dossiq\Service\Registry\BagObjectReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\LocationBagValidationListener
 *
 * @uses \OCA\Dossiq\Service\External\IntegrationMode
 */
class LocationBagValidationListenerTest extends TestCase {

	/**
	 * A well-formed nummeraanduiding id.
	 */
	private const ID = '0363200000123456';

	/**
	 * The value of `integration.bag.check` the next listener reads.
	 *
	 * @var string
	 */
	private string $check = '';

	/**
	 * The BAG reader double.
	 *
	 * @var BagObjectReader&MockObject
	 */
	private BagObjectReader&MockObject $bagReader;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->bagReader = $this->createMock(BagObjectReader::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The listener, reading `integration.bag.check` as set on this test.
	 *
	 * @return LocationBagValidationListener
	 */
	private function listener(): LocationBagValidationListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return $key === 'location_schema' ? 'location-schema-id' : $default;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === 'integration.bag.check' ? $this->check : $default
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $text
		);

		return new LocationBagValidationListener(
			$settings,
			new IntegrationMode($appConfig),
			$this->bagReader,
			$l10n,
			$this->logger,
		);
	}//end listener()

	/**
	 * Build a location ObjectEntity with the given payload.
	 *
	 * @param array<string, mixed> $payload Location fields.
	 * @param string $schemaId Schema id (`@self.schema`).
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, string $schemaId = 'location-schema-id'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('11111111-1111-1111-1111-111111111111');

		return $entity;
	}//end entity()

	/**
	 * Save a BAG location with a well-formed id, with the check as set.
	 *
	 * @param array{available:bool,reason:string,found:bool,simulated:bool}|null $answer What integriq answers; null for "not asked".
	 *
	 * @return array{0:ObjectCreatingEvent,1:ObjectEntity}
	 */
	private function save(?array $answer): array {
		if ($answer === null) {
			$this->bagReader->expects($this->never())->method('read');
		} else {
			$this->bagReader->expects($this->once())->method('read')->with('nummeraanduiding', self::ID)->willReturn($answer);
		}

		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => self::ID]);
		$event = new ObjectCreatingEvent($entity);
		$this->listener()->handle($event);

		return [$event, $entity];
	}//end save()

	/**
	 * Test that a generic unrelated event is ignored.
	 *
	 * @return void
	 */
	public function testHandleIgnoresUnrelatedEvents(): void {
		$this->bagReader->expects($this->never())->method('read');
		$this->listener()->handle(new Event());
		$this->addToAssertionCount(1);
	}//end testHandleIgnoresUnrelatedEvents()

	/**
	 * Test that a non-location schema object is ignored regardless of
	 * payload contents.
	 *
	 * @return void
	 */
	public function testNonLocationSchemaIsIgnored(): void {
		$entity = $this->entity(['source' => 'bag'], schemaId: 'some-other-schema');
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testNonLocationSchemaIsIgnored()

	/**
	 * Test that an absent `source` field is a no-op.
	 *
	 * @return void
	 */
	public function testAbsentSourceIsIgnored(): void {
		$entity = $this->entity(['case' => 'uuid-x']);
		$event = new ObjectCreatingEvent($entity);

		$this->bagReader->expects($this->never())->method('read');
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAbsentSourceIsIgnored()

	/**
	 * Test that a non-bag source is a no-op.
	 *
	 * @return void
	 */
	public function testNonBagSourceIsIgnored(): void {
		$entity = $this->entity(['source' => 'pdok-reverse', 'latitude' => 52.0, 'longitude' => 5.0]);
		$event = new ObjectCreatingEvent($entity);

		$this->bagReader->expects($this->never())->method('read');
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testNonBagSourceIsIgnored()

	/**
	 * Test that source=bag with a missing nummeraanduidingId is rejected.
	 *
	 * @return void
	 */
	public function testBagSourceWithMissingIdIsRejected(): void {
		$entity = $this->entity(['source' => 'bag', 'case' => 'uuid-x']);
		$event = new ObjectCreatingEvent($entity);

		$this->bagReader->expects($this->never())->method('read');
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.required'], $event->getErrors()['codes']);
	}//end testBagSourceWithMissingIdIsRejected()

	/**
	 * Test that source=bag with a malformed (non-16-digit) id is rejected.
	 *
	 * @return void
	 */
	public function testBagSourceWithMalformedIdIsRejected(): void {
		$entity = $this->entity(['source' => 'bag', 'addressDesignationId' => '12345']);
		$event = new ObjectCreatingEvent($entity);

		$this->bagReader->expects($this->never())->method('read');
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.invalid'], $event->getErrors()['codes']);
	}//end testBagSourceWithMalformedIdIsRejected()

	/**
	 * With the check off, the location is saved and marked not-checked, and integriq is not asked.
	 *
	 * @return void
	 */
	public function testCheckOffSavesAndMarksNotChecked(): void {
		[$event, $entity] = $this->save(answer: null);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('not-checked', $entity->getObject()['bagCheck']);
	}//end testCheckOffSavesAndMarksNotChecked()

	/**
	 * With the check on and the BAG holding the id, the location is saved and marked checked.
	 *
	 * @return void
	 */
	public function testCheckOnAndFoundSavesAndMarksChecked(): void {
		$this->check = 'on';
		[$event, $entity] = $this->save(answer: ['available' => true, 'reason' => '', 'found' => true, 'simulated' => false]);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('checked', $entity->getObject()['bagCheck']);
	}//end testCheckOnAndFoundSavesAndMarksChecked()

	/**
	 * With the check on and the BAG holding no such id, the save is refused.
	 *
	 * @return void
	 */
	public function testCheckOnAndNotFoundIsRefused(): void {
		$this->check = 'on';
		[$event] = $this->save(answer: ['available' => true, 'reason' => '', 'found' => false, 'simulated' => false]);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.unknown'], $event->getErrors()['codes']);
	}//end testCheckOnAndNotFoundIsRefused()

	/**
	 * With the check on and no answer (integriq absent or too old, PDOK down, an
	 * answer about another object), the save is refused. Where integriq is absent
	 * or too old, the message says what the administrator must do about it.
	 *
	 * @return void
	 */
	public function testCheckOnWithoutAnAnswerIsRefusedNotPassed(): void {
		$expected = [
			'unreachable' => ['nummeraanduidingId.unchecked', 'could not be checked in the BAG'],
			'unclear-answer' => ['nummeraanduidingId.unchecked', 'could not be checked in the BAG'],
			'integriq-not-installed' => ['nummeraanduidingId.integriq-missing', 'integriq must be installed'],
			'integriq-has-no-bag-read' => ['nummeraanduidingId.integriq-outdated', 'integriq must be updated'],
		];
		foreach ($expected as $reason => [$code, $says]) {
			$this->setUp();
			$this->check = 'on';
			$this->logger->expects($this->once())->method('warning');
			[$event] = $this->save(answer: ['available' => false, 'reason' => $reason, 'found' => false, 'simulated' => false]);

			$this->assertTrue($event->isPropagationStopped(), $reason);
			$this->assertSame([$code], $event->getErrors()['codes'], $reason);
			$this->assertStringContainsString($says, $event->getErrors()['message'], $reason);
		}
	}//end testCheckOnWithoutAnAnswerIsRefusedNotPassed()

	/**
	 * A simulated answer is not a check: with the check on it is refused, even when it says found.
	 *
	 * @return void
	 */
	public function testCheckOnWithASimulatedAnswerIsRefused(): void {
		$this->check = 'on';
		[$event] = $this->save(answer: ['available' => true, 'reason' => '', 'found' => true, 'simulated' => true]);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.unchecked'], $event->getErrors()['codes']);
	}//end testCheckOnWithASimulatedAnswerIsRefused()

	/**
	 * Any value other than `on` leaves the check off.
	 *
	 * @return void
	 */
	public function testOnlyOnSwitchesTheCheckOn(): void {
		$this->check = 'live';
		[$event, $entity] = $this->save(answer: null);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('not-checked', $entity->getObject()['bagCheck']);
	}//end testOnlyOnSwitchesTheCheckOn()

	/**
	 * Test that the listener also validates on ObjectUpdatingEvent (not
	 * just create).
	 *
	 * @return void
	 */
	public function testUpdatingEventWithMissingIdIsRejected(): void {
		$entity = $this->entity(['source' => 'bag']);
		$event = new ObjectUpdatingEvent($entity, null);

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['nummeraanduidingId.required'], $event->getErrors()['codes']);
	}//end testUpdatingEventWithMissingIdIsRejected()

	/**
	 * Test that the listener is constructed successfully.
	 *
	 * @return void
	 */
	public function testListenerConstructedSuccessfully(): void {
		$this->assertInstanceOf(LocationBagValidationListener::class, $this->listener());
	}//end testListenerConstructedSuccessfully()
}//end class
