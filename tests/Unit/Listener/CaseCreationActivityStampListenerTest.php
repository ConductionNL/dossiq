<?php

/**
 * CaseCreationActivityStampListener Unit Tests
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

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\CaseCreationActivityStampListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A case being created gets `lastActivityAt` in the same save; other objects and a case that has one are left alone.
 *
 * @covers \OCA\Dossiq\Listener\CaseCreationActivityStampListener
 */
class CaseCreationActivityStampListenerTest extends TestCase {

	private const SCHEMA = '41';

	private function listener(): CaseCreationActivityStampListener {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'case_schema' ? self::SCHEMA : $default)
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-23T09:30:00+00:00'));

		return new CaseCreationActivityStampListener(
			settingsService: $settings,
			time: $time,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end listener()

	private function entity(array $payload, string $schema = self::SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schema);
		$entity->setUuid('case-1');

		return $entity;
	}//end entity()

	public function testANewCaseIsStampedInsideTheCreate(): void {
		$event = new ObjectCreatingEvent($this->entity(payload: ['title' => 'Street light']));
		$event->setModifiedData(['priority' => 'normal']);

		$this->listener()->handle(event: $event);

		self::assertSame(
			expected: ['priority' => 'normal', 'lastActivityAt' => '2026-11-23T09:30:00+00:00'],
			actual: $event->getModifiedData()
		);
	}//end testANewCaseIsStampedInsideTheCreate()

	public function testACaseThatCarriesAStampKeepsIt(): void {
		$event = new ObjectCreatingEvent($this->entity(payload: ['title' => 'Imported', 'lastActivityAt' => '2025-01-01T00:00:00+00:00']));

		$this->listener()->handle(event: $event);

		self::assertSame(expected: [], actual: $event->getModifiedData());
	}//end testACaseThatCarriesAStampKeepsIt()

	public function testAnotherSchemaAndAnotherEventAreLeftAlone(): void {
		$other = new ObjectCreatingEvent($this->entity(payload: ['title' => 'Task'], schema: '99'));
		$this->listener()->handle(event: $other);
		self::assertSame(expected: [], actual: $other->getModifiedData());

		$created = new ObjectCreatedEvent($this->entity(payload: ['title' => 'Street light']));
		$this->listener()->handle(event: $created);
		self::assertArrayNotHasKey(key: 'lastActivityAt', array: $created->getObject()->getObject());
	}//end testAnotherSchemaAndAnotherEventAreLeftAlone()
}//end class
