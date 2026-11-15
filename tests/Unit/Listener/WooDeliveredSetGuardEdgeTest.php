<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\WooDeliveredSetWriterInterface;
use OCA\Dossiq\Listener\WooDeliveredSetGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The delivered-set guard on writes it does not guard, objects it cannot
 * read, and a withdraw stamp that hides another change.
 *
 * @covers \OCA\Dossiq\Listener\WooDeliveredSetGuard
 */
class WooDeliveredSetGuardEdgeTest extends TestCase {

	private const FROZEN = [
		'case' => 'case-1',
		'decision' => 'dec-1',
		'status' => 'frozen',
		'deliveredAt' => '2026-10-07T10:00:00+02:00',
		'items' => [['assessment' => 'as-1', 'classification' => 'openbaar']],
		'setHash' => 'bbbb',
	];

	/**
	 * @var int How often the frozen sets of a case were read.
	 */
	private int $setReads = 0;

	/**
	 * @return void
	 */
	public function testAWithdrawStampThatAlsoChangesTheSetIsRefused(): void {
		$old = $this->entity(schema: 'wooDeliveredSet', data: self::FROZEN);
		$new = $this->entity(schema: 'wooDeliveredSet', data: array_merge(self::FROZEN, ['withdrawnAt' => '2026-10-09T10:00:00+02:00', 'setHash' => 'cccc']));
		$event = new ObjectUpdatingEvent($new, $old);

		$this->guard()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			['error' => 'woo-delivered-set-frozen', 'message' => 'This was delivered for publication on 2026-10-07, so it cannot be changed. Publish again to deliver a new set.'],
			$event->getErrors()
		);
	}//end testAWithdrawStampThatAlsoChangesTheSetIsRefused()

	/**
	 * @return void
	 */
	public function testAnAssessmentWithoutACaseIsNotLookedUp(): void {
		$old = $this->entity(schema: 'wooDocumentAssessment', data: ['classification' => 'openbaar']);
		$event = new ObjectUpdatingEvent($this->entity(schema: 'wooDocumentAssessment', data: ['classification' => 'geheim']), $old);

		$this->guard()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(0, $this->setReads);
	}//end testAnAssessmentWithoutACaseIsNotLookedUp()

	/**
	 * @return void
	 */
	public function testObjectsOfOtherSchemasOrWithoutOneAreLeftAlone(): void {
		$guard = $this->guard();
		$otherSchema = new ObjectDeletingEvent($this->entity(schema: 'case', data: ['caseRef' => 'case-1', 'status' => 'frozen']));
		$noSchema = new ObjectDeletingEvent($this->entity(schema: '', data: self::FROZEN));

		$guard->handle($otherSchema);
		$guard->handle($noSchema);
		$guard->handle(new Event());

		$this->assertFalse($otherSchema->isPropagationStopped());
		$this->assertFalse($noSchema->isPropagationStopped());
		$this->assertSame(0, $this->setReads);
	}//end testObjectsOfOtherSchemasOrWithoutOneAreLeftAlone()

	/**
	 * @return void
	 */
	public function testAnObjectThatCannotBeSerialisedIsLeftAlone(): void {
		$entity = new class extends ObjectEntity {
			public function jsonSerialize(): array {
				throw new RuntimeException('unreadable');
			}
		};
		$event = new ObjectDeletingEvent($entity);

		$this->guard()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAnObjectThatCannotBeSerialisedIsLeftAlone()

	/**
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema($schema);
		$entity->setUuid('obj-1');

		return $entity;
	}//end entity()

	/**
	 * @return WooDeliveredSetGuard The guard over a set reader that counts its reads.
	 */
	private function guard(): WooDeliveredSetGuard {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['woo_assessment_schema' => 'wooDocumentAssessment', 'woo_delivered_set_schema' => 'wooDeliveredSet'][$key] ?? $default)
		);

		$sets = $this->createMock(WooDeliveredSetWriterInterface::class);
		$sets->method('frozenSets')->willReturnCallback(
			function (): array {
				$this->setReads++;
				return [self::FROZEN];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		return new WooDeliveredSetGuard(settings: $settings, sets: $sets, l10n: $l10n, logger: new NullLogger());
	}//end guard()
}//end class
