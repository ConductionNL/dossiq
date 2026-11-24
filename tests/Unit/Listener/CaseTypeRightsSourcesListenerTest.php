<?php

/**
 * CaseTypeRightsSourcesListener unit tests
 *
 * A create, update or delete of a case type or an organisation role drops the
 * kept case type rights rows; any other object or event leaves them.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\CaseTypeRightsSourcesListener;
use OCA\Dossiq\Service\Access\CaseTypeRightsRowsCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for dropping the kept rights rows.
 *
 * @covers \OCA\Dossiq\Listener\CaseTypeRightsSourcesListener
 */
class CaseTypeRightsSourcesListenerTest extends TestCase {

	/**
	 * The listener with case types in schema 65 and organisation roles in 210.
	 *
	 * @param CaseTypeRightsRowsCache $cache The cache double.
	 *
	 * @return CaseTypeRightsSourcesListener
	 */
	private function listener(CaseTypeRightsRowsCache $cache): CaseTypeRightsSourcesListener {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['case_type_schema' => '65', 'organisatie_rol_schema' => '210'][$key] ?? ''
		);

		return new CaseTypeRightsSourcesListener(settings: $settings, cache: $cache);
	}//end listener()

	/**
	 * An object of the given schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function objectOf(string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);

		return $object;
	}//end objectOf()

	/**
	 * Creating, updating or deleting a case type or organisation role drops the rows.
	 *
	 * @return void
	 */
	public function testASourceChangeDropsTheRows(): void {
		$cache = $this->createMock(originalClassName: CaseTypeRightsRowsCache::class);
		$cache->expects($this->exactly(count: 3))->method('forget');
		$listener = $this->listener(cache: $cache);

		$listener->handle(new ObjectCreatedEvent($this->objectOf(schema: '65')));
		$listener->handle(new ObjectUpdatedEvent($this->objectOf(schema: '210'), $this->objectOf(schema: '210')));
		$listener->handle(new ObjectDeletedEvent($this->objectOf(schema: '65')));
	}//end testASourceChangeDropsTheRows()

	/**
	 * Another schema's object, or another event, leaves the rows.
	 *
	 * @return void
	 */
	public function testAnyOtherChangeLeavesTheRows(): void {
		$cache = $this->createMock(originalClassName: CaseTypeRightsRowsCache::class);
		$cache->expects($this->never())->method('forget');
		$listener = $this->listener(cache: $cache);

		$listener->handle(new ObjectCreatedEvent($this->objectOf(schema: '72')));
		$listener->handle(new ObjectUpdatedEvent($this->objectOf(schema: '72'), $this->objectOf(schema: '72')));
		$listener->handle(new Event());
	}//end testAnyOtherChangeLeavesTheRows()
}//end class
