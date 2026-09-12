<?php

/**
 * BeschikkingImmutabilityListener Unit Tests
 *
 * REQ-BES-008: a beschikking at `signed` or later is frozen. These tests
 * exercise the guard at the persistence boundary rather than the service
 * method behind the PATCH route, because the defect being closed was that
 * the route was the only door the rule covered.
 *
 * Every reject assertion is paired with an accept assertion on the same
 * mechanism, so a listener that rejected unconditionally (or one that never
 * ran) would fail the suite instead of passing it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\BeschikkingImmutabilityListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\BeschikkingImmutabilityListener
 *
 * @uses \OCA\Dossiq\Service\StateMachineService
 */
class BeschikkingImmutabilityListenerTest extends TestCase {
	/**
	 * Schema id the listener is configured to recognise.
	 */
	private const SCHEMA = 'beschikking-schema-id';

	/**
	 * The listener under test.
	 *
	 * @var BeschikkingImmutabilityListener
	 */
	private BeschikkingImmutabilityListener $listener;

	/**
	 * Set up the listener with the REAL StateMachineService, so the test
	 * exercises the actual REQ-BES-008 rule and not a mock of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return $key === 'beschikking_schema' ? self::SCHEMA : $default;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$this->listener = new BeschikkingImmutabilityListener(
			$settingsService,
			new StateMachineService($this->createMock(SettingsService::class), $logger),
			$logger,
		);
	}//end setUp()

	/**
	 * Build a beschikking entity.
	 *
	 * @param array<string, mixed> $payload Beschikking fields.
	 * @param string $schemaId Schema id (`@self.schema`).
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, string $schemaId = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('33333333-3333-3333-3333-333333333333');

		return $entity;
	}//end entity()

	/**
	 * A content edit on a draft is allowed through: the positive control for
	 * every reject case below.
	 *
	 * @return void
	 */
	public function testContentEditOnADraftIsAllowed(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['currentStatus' => 'draft', 'rationale' => 'herzien']),
			$this->entity(['currentStatus' => 'draft', 'rationale' => 'origineel'])
		);

		$this->listener->handle($event);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'A draft beschikking must stay editable'
		);
		$this->assertSame([], $event->getErrors());
	}//end testContentEditOnADraftIsAllowed()

	/**
	 * A content edit on a signed beschikking is rejected BEFORE the row is
	 * written (stopPropagation on the pre-persist event).
	 *
	 * @return void
	 */
	public function testContentEditAfterSigningIsRejected(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['currentStatus' => 'signed', 'rationale' => 'herzien']),
			$this->entity(['currentStatus' => 'signed', 'rationale' => 'origineel'])
		);

		$this->listener->handle($event);

		$this->assertTrue(
			$event->isPropagationStopped(),
			'A signed beschikking must refuse a content edit pre-persist'
		);
		$this->assertSame('beschikking.immutable', $event->getErrors()['code'] ?? null);
		$this->assertStringContainsString(
			'wijzigingsbeschikking',
			(string)($event->getErrors()['message'] ?? ''),
			'REQ-BES-008 requires the refusal to name the way forward'
		);
	}//end testContentEditAfterSigningIsRejected()

	/**
	 * The STORED state decides, not the incoming payload: claiming `draft`
	 * in the same request that rewrites the motivation must not unlock a
	 * beschikking that is `sent`. Without this the guard is bypassable by
	 * anyone who can write the status field.
	 *
	 * @return void
	 */
	public function testPayloadCannotClaimDraftToBypassTheGuard(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['currentStatus' => 'draft', 'rationale' => 'herzien']),
			$this->entity(['currentStatus' => 'sent', 'rationale' => 'origineel'])
		);

		$this->listener->handle($event);

		$this->assertTrue(
			$event->isPropagationStopped(),
			'The guard must read the stored status, not the incoming payload'
		);
	}//end testPayloadCannotClaimDraftToBypassTheGuard()

	/**
	 * Process events stay allowed after signing. A beschikking that could
	 * not record its own delivery would be unusable, so a guard that
	 * refused every write would be as wrong as one that refused none.
	 *
	 * @return void
	 */
	public function testProcessEventsStayAllowedAfterSigning(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(
				[
					'currentStatus' => 'sent',
					'rationale' => 'origineel',
					'dispatch' => ['notificationChannel' => 'berichtenbox-mijnoverheid'],
				]
			),
			$this->entity(['currentStatus' => 'sent', 'rationale' => 'origineel'])
		);

		$this->listener->handle($event);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'Recording a dispatch on a sent beschikking must be allowed'
		);
	}//end testProcessEventsStayAllowedAfterSigning()

	/**
	 * Repeating a stored value changes nothing. The generic object API sends
	 * the whole object on every write, so a presence-only check would refuse
	 * every legitimate process event.
	 *
	 * @return void
	 */
	public function testUnchangedContentFieldsAreNotTreatedAsAnEdit(): void {
		$payload = [
			'currentStatus' => 'archived',
			'rationale' => 'origineel',
			'feeAmount' => 4000,
		];

		$event = new ObjectUpdatingEvent($this->entity($payload), $this->entity($payload));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testUnchangedContentFieldsAreNotTreatedAsAnEdit()

	/**
	 * Deleting a signed beschikking is rejected too. An immutability rule
	 * that only covers UPDATE is bypassable by delete-and-recreate.
	 *
	 * @return void
	 */
	public function testDeleteOfAnArchivedBeschikkingIsRejected(): void {
		$event = new ObjectDeletingEvent($this->entity(['currentStatus' => 'archived']));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('beschikking.immutable', $event->getErrors()['code'] ?? null);
	}//end testDeleteOfAnArchivedBeschikkingIsRejected()

	/**
	 * Deleting a draft is allowed: the paired accept for the delete guard.
	 *
	 * @return void
	 */
	public function testDeleteOfADraftIsAllowed(): void {
		$event = new ObjectDeletingEvent($this->entity(['currentStatus' => 'draft']));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testDeleteOfADraftIsAllowed()

	/**
	 * Objects of another schema are untouched. The listener must not freeze
	 * unrelated registers just because they carry a `currentStatus` field.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['currentStatus' => 'signed', 'rationale' => 'herzien'], 'some-other-schema'),
			$this->entity(['currentStatus' => 'signed', 'rationale' => 'origineel'], 'some-other-schema')
		);

		$this->listener->handle($event);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'Only the beschikking schema is frozen by this listener'
		);
	}//end testAnotherSchemaIsIgnored()
}//end class
