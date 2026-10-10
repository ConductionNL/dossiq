<?php

/**
 * A frozen delivered set and the assessments it names refuse change.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\WooDeliveredSetGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WDS-002 through the real OpenRegister event classes.
 *
 * @covers \OCA\Dossiq\Listener\WooDeliveredSetGuard
 *
 * @uses \OCA\Dossiq\Woo\WooDeliveredSetWriter
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooDeliveredSetGuardTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A frozen set naming assessment as-1 of case-1.
	 *
	 * @var array<string, mixed>
	 */
	private array $frozen = [];

	/**
	 * Seed one frozen set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->frozen = [
			'case' => 'case-1',
			'decision' => 'dec-1',
			'status' => 'frozen',
			'deliveredAt' => '2026-10-07T10:00:00+02:00',
			'publication' => 'pub-1',
			'items' => [['assessment' => 'as-1', 'classification' => 'openbaar', 'deliveredRef' => 'doc-1', 'sha256' => str_repeat('a', 64)]],
			'setHash' => str_repeat('b', 64),
		];
		$this->store->seed(schema: 'wooDeliveredSet', uuid: 'set-1', row: $this->frozen);
	}//end setUp()

	/**
	 * An entity as OpenRegister hands it to the event.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema($schema);
		$entity->setUuid($uuid);

		return $entity;
	}//end entity()

	/**
	 * The guard over the store.
	 *
	 * @return WooDeliveredSetGuard
	 */
	private function guard(): WooDeliveredSetGuard {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment', 'woo_delivered_set_schema' => 'wooDeliveredSet'][$key] ?? $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		return new WooDeliveredSetGuard(
			settings: $settings,
			sets: new WooDeliveredSetWriter(settings: $settings),
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end guard()

	/**
	 * Changing a delivered verdict is refused with the delivered-on sentence.
	 *
	 * @return void
	 */
	public function testADeliveredVerdictCannotBeChanged(): void {
		$old = $this->entity(schema: 'wooDocumentAssessment', uuid: 'as-1', data: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'openbaar']);
		$new = $this->entity(schema: 'wooDocumentAssessment', uuid: 'as-1', data: ['caseRef' => 'case-1', 'documentRef' => 'doc-1', 'classification' => 'niet_openbaar']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('woo-delivered-set-frozen', $event->getErrors()['error']);
		$this->assertStringContainsString('2026-10-07', $event->getErrors()['message']);
	}//end testADeliveredVerdictCannotBeChanged()

	/**
	 * A frozen set cannot be deleted.
	 *
	 * @return void
	 */
	public function testAFrozenSetCannotBeDeleted(): void {
		$event = new ObjectDeletingEvent($this->entity(schema: 'wooDeliveredSet', uuid: 'set-1', data: $this->frozen));

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAFrozenSetCannotBeDeleted()

	/**
	 * An assessment no frozen set names can still change.
	 *
	 * @return void
	 */
	public function testAnAssessmentOutsideTheSetCanStillChange(): void {
		$old = $this->entity(schema: 'wooDocumentAssessment', uuid: 'as-2', data: ['caseRef' => 'case-1', 'documentRef' => 'doc-2', 'classification' => 'openbaar']);
		$new = $this->entity(schema: 'wooDocumentAssessment', uuid: 'as-2', data: ['caseRef' => 'case-1', 'documentRef' => 'doc-2', 'classification' => 'niet_openbaar']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->guard()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnAssessmentOutsideTheSetCanStillChange()

	/**
	 * The first withdraw stamp passes; any other change to a frozen set does not.
	 *
	 * @return void
	 */
	public function testOnlyTheWithdrawStampPassesOnAFrozenSet(): void {
		$old = $this->entity(schema: 'wooDeliveredSet', uuid: 'set-1', data: $this->frozen);
		$stamp = new ObjectUpdatingEvent($this->entity(schema: 'wooDeliveredSet', uuid: 'set-1', data: array_merge($this->frozen, ['withdrawnAt' => '2026-10-09T10:00:00+02:00'])), $old);
		$tamper = new ObjectUpdatingEvent($this->entity(schema: 'wooDeliveredSet', uuid: 'set-1', data: array_merge($this->frozen, ['setHash' => str_repeat('c', 64)])), $old);

		$this->guard()->handle($stamp);
		$this->guard()->handle($tamper);

		$this->assertFalse($stamp->isPropagationStopped());
		$this->assertTrue($tamper->isPropagationStopped());
	}//end testOnlyTheWithdrawStampPassesOnAFrozenSet()

	/**
	 * A pending set is not guarded: freezing and discarding it must work.
	 *
	 * @return void
	 */
	public function testAPendingSetMayBeFrozenOrDiscarded(): void {
		$pending = array_merge($this->frozen, ['status' => 'pending']);
		$event = new ObjectDeletingEvent($this->entity(schema: 'wooDeliveredSet', uuid: 'set-2', data: $pending));

		$this->guard()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAPendingSetMayBeFrozenOrDiscarded()
}//end class
