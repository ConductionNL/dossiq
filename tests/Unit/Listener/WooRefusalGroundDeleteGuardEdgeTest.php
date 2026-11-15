<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\WooRefusalGroundDeleteGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The Woo refusal-ground delete guard on objects it cannot read or place, and
 * with a citing schema left unconfigured.
 *
 * @covers \OCA\Dossiq\Listener\WooRefusalGroundDeleteGuard
 * @uses   \OCA\Dossiq\Listener\CitationCount
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooRefusalGroundDeleteGuardEdgeTest extends TestCase {

	/**
	 * @var InMemoryRegister The register the guard reads.
	 */
	private InMemoryRegister $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'wooDocumentAssessment', uuid: 'a-1', row: ['weigeringsgronden' => ['5.1.1.d']]);
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['weigeringsgronden' => ['5.1.1.d']]);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testAnUnconfiguredCitingSchemaCountsNoCitations(): void {
		$event = $this->deleteOf(entity: $this->ground(schema: 'wooRefusalGround'));

		$this->guard(withDecisions: false)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('woo-refusal-ground-cited', $event->getErrors()['error']);

		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['weigeringsgronden' => ['5.1.1.d']]);
		$uncited = $this->deleteOf(entity: $this->ground(schema: 'wooRefusalGround'));
		$this->guard(withDecisions: false)->handle($uncited);
		$this->assertFalse($uncited->isPropagationStopped());
	}//end testAnUnconfiguredCitingSchemaCountsNoCitations()

	/**
	 * @return void
	 */
	public function testAnObjectThatCannotBeSerialisedIsLeftAlone(): void {
		$entity = new class extends ObjectEntity {
			public function jsonSerialize(): array {
				throw new RuntimeException('unreadable');
			}
		};
		$event = $this->deleteOf(entity: $entity);

		$this->guard()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAnObjectThatCannotBeSerialisedIsLeftAlone()

	/**
	 * @return void
	 */
	public function testAnObjectWithoutASchemaIsLeftAlone(): void {
		$withoutSchema = $this->deleteOf(entity: $this->ground(schema: ''));
		$selfNotAnObject = $this->deleteOf(
			entity: new class extends ObjectEntity {
				public function jsonSerialize(): array {
					return ['code' => '5.1.1.d', '@self' => 'wooRefusalGround'];
				}
			}
		);

		$this->guard()->handle($withoutSchema);
		$this->guard()->handle($selfNotAnObject);
		$this->guard()->handle(new Event());

		$this->assertFalse($withoutSchema->isPropagationStopped());
		$this->assertFalse($selfNotAnObject->isPropagationStopped());
	}//end testAnObjectWithoutASchemaIsLeftAlone()

	/**
	 * @param string $schema The schema the ground carries.
	 *
	 * @return ObjectEntity The ground 5.1.1.d.
	 */
	private function ground(string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['code' => '5.1.1.d', 'label' => 'Eerbiediging van de persoonlijke levenssfeer']);
		$entity->setSchema($schema);
		$entity->setUuid('99999999-0000-4000-8000-000000000001');

		return $entity;
	}//end ground()

	/**
	 * @param ObjectEntity $entity The deleted object.
	 *
	 * @return ObjectDeletingEvent The event.
	 */
	private function deleteOf(ObjectEntity $entity): ObjectDeletingEvent {
		return new ObjectDeletingEvent($entity);
	}//end deleteOf()

	/**
	 * @param bool $withDecisions Whether the decision schema is configured.
	 *
	 * @return WooRefusalGroundDeleteGuard The guard over the store.
	 */
	private function guard(bool $withDecisions = true): WooRefusalGroundDeleteGuard {
		$config = ['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment', 'woo_refusal_ground_schema' => 'wooRefusalGround'];
		if ($withDecisions === true) {
			$config['decision_schema'] = 'decision';
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$settings->method('getObjectService')->willReturnCallback(fn (): InMemoryRegister => $this->store);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		return new WooRefusalGroundDeleteGuard(settingsService: $settings, l10n: $l10n, logger: new NullLogger());
	}//end guard()
}//end class
