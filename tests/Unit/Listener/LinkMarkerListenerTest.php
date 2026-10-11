<?php

/**
 * A saved source object sets the configured marker on the object it references.
 *
 * Real in-memory register, real rule, the event OpenRegister dispatches after a
 * save. Neutral fixture schemas: an `appeal` referencing a `record`.
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
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\LinkMarkerListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\LinkMarkerListener
 */
class LinkMarkerListenerTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The stored rules setting.
	 *
	 * @var string
	 */
	private string $rules = '';

	/**
	 * Seed a closed target record.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'record', uuid: 'rec-1', row: ['status' => 'closed', 'followUpOpen' => false]);
		$this->rules = (string)json_encode(
			[
				[
					'source' => 'appeal_schema',
					'field'  => 'sourceRecord',
					'target' => 'record_schema',
					'marker' => 'followUpOpen',
					'when'   => [
						['on' => 'target', 'field' => 'status', 'in' => ['closed']],
						['field' => 'filedOn', 'present' => true],
					],
				],
			]
		);
	}//end setUp()

	/**
	 * The listener over the register and the current rules.
	 *
	 * @return LinkMarkerListener The listener.
	 */
	private function listener(): LinkMarkerListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => match ($key) {
				'register'                     => 'dossiq',
				'appeal_schema'                => 'appeal',
				'record_schema'                => 'record',
				LinkMarkerListener::RULES_KEY  => $this->rules,
				default                        => '',
			}
		);

		return new LinkMarkerListener(settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An update event carrying an object.
	 *
	 * @param array<string, mixed> $object The saved object.
	 *
	 * @return ObjectUpdatedEvent The event.
	 */
	private function saved(array $object): ObjectUpdatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($object);

		return new ObjectUpdatedEvent($entity);
	}//end saved()

	/**
	 * A filed appeal against a closed record raises the marker.
	 *
	 * @return void
	 */
	public function testTheMarkerIsRaised(): void {
		$this->listener()->handle(
			event: $this->saved(['id' => 'ap-1', 'sourceRecord' => 'rec-1', 'filedOn' => '2026-03-01', '@self' => ['schema' => 'appeal']])
		);

		self::assertTrue($this->store->row(schema: 'record', uuid: 'rec-1')['followUpOpen']);
		self::assertSame('closed', $this->store->row(schema: 'record', uuid: 'rec-1')['status']);
	}//end testTheMarkerIsRaised()

	/**
	 * A marker that already has its value is not written again.
	 *
	 * @return void
	 */
	public function testAnUnchangedMarkerIsNotWritten(): void {
		$before = $this->store->writes;
		$this->listener()->handle(
			event: $this->saved(['id' => 'ap-1', 'sourceRecord' => 'rec-1', '@self' => ['schema' => 'appeal']])
		);

		self::assertSame($before, $this->store->writes);
		self::assertFalse($this->store->row(schema: 'record', uuid: 'rec-1')['followUpOpen']);
	}//end testAnUnchangedMarkerIsNotWritten()

	/**
	 * An object of another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$before = $this->store->writes;
		$this->listener()->handle(
			event: $this->saved(['id' => 'x-1', 'sourceRecord' => 'rec-1', 'filedOn' => '2026-03-01', '@self' => ['schema' => 'note']])
		);

		self::assertSame($before, $this->store->writes);
	}//end testAnotherSchemaIsIgnored()

	/**
	 * Rules that do not read do not throw into the save that triggered them.
	 *
	 * @return void
	 */
	public function testUnreadableRulesDoNotBlockTheSave(): void {
		$this->rules = '{broken';
		$this->listener()->handle(
			event: $this->saved(['id' => 'ap-1', 'sourceRecord' => 'rec-1', 'filedOn' => '2026-03-01', '@self' => ['schema' => 'appeal']])
		);

		self::assertFalse($this->store->row(schema: 'record', uuid: 'rec-1')['followUpOpen']);
	}//end testUnreadableRulesDoNotBlockTheSave()
}//end class
