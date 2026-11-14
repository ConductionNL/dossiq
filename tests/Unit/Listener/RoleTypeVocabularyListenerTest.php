<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\RoleTypeVocabularyListener;
use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A role type saved or removed after the upgrade still reaches the case
 * schema's link vocabulary, so the Roles card labels it.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */
class RoleTypeVocabularyListenerTest extends TestCase {

	/**
	 * The configured role type schema, as the instance stores it: an id.
	 */
	private const ROLE_TYPE_SCHEMA = '168';

	/**
	 * The doubled vocabulary.
	 *
	 * @var CaseRoleVocabulary&MockObject
	 */
	private CaseRoleVocabulary&MockObject $vocabulary;

	/**
	 * The listener under test.
	 *
	 * @var RoleTypeVocabularyListener
	 */
	private RoleTypeVocabularyListener $listener;

	/**
	 * Build the listener on a doubled vocabulary.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['role_type_schema' => self::ROLE_TYPE_SCHEMA][$key] ?? $default)
		);
		$this->vocabulary = $this->createMock(originalClassName: CaseRoleVocabulary::class);
		$this->listener = new RoleTypeVocabularyListener(
			settingsService: $settings,
			vocabulary: $this->vocabulary,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * An object of the given schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function objectOf(string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setUuid('3c0f5a00-0000-4000-a000-0000000c0001');
		$entity->setObject(['name' => 'Verzoeker']);

		return $entity;
	}//end objectOf()

	/**
	 * A role type created, updated or deleted re-syncs the vocabulary once.
	 *
	 * @return void
	 */
	public function testARoleTypeChangeResyncsTheVocabulary(): void {
		$this->vocabulary->expects($this->exactly(count: 3))->method('sync')->willReturn(7);

		$this->listener->handle(new ObjectCreatedEvent($this->objectOf(schema: self::ROLE_TYPE_SCHEMA)));
		$this->listener->handle(new ObjectUpdatedEvent($this->objectOf(schema: self::ROLE_TYPE_SCHEMA)));
		$this->listener->handle(new ObjectDeletedEvent($this->objectOf(schema: self::ROLE_TYPE_SCHEMA)));
	}//end testARoleTypeChangeResyncsTheVocabulary()

	/**
	 * Any other object leaves the case schema alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaDoesNotResync(): void {
		$this->vocabulary->expects($this->never())->method('sync');

		$this->listener->handle(new ObjectCreatedEvent($this->objectOf(schema: '172')));
		$this->listener->handle(new ObjectUpdatedEvent($this->objectOf(schema: '1168')));
		$this->listener->handle(new Event());
	}//end testAnotherSchemaDoesNotResync()

	/**
	 * A sync that fails does not fail the save that triggered it.
	 *
	 * @return void
	 */
	public function testAFailingSyncDoesNotFailTheSave(): void {
		$this->vocabulary->expects($this->once())->method('sync')->willThrowException(new RuntimeException('no mapper'));

		$this->listener->handle(new ObjectCreatedEvent($this->objectOf(schema: self::ROLE_TYPE_SCHEMA)));
	}//end testAFailingSyncDoesNotFailTheSave()
}//end class
