<?php

/**
 * CaseTypeStore request reads unit tests
 *
 * A row or list read once is kept for the rest of the request; forget() and a
 * failed read leave nothing kept; an object change drops what is kept.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Listener\CaseTypeStoreForgetListener;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the store's request reads.
 *
 * @covers \OCA\Dossiq\Service\CaseTypeStore
 * @covers \OCA\Dossiq\Listener\CaseTypeStoreForgetListener
 */
class CaseTypeStoreRequestReadsTest extends TestCase {

	/**
	 * The object service double: counts its reads, throws while $fail is set.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Build the object service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			public int $finds = 0;
			public int $searches = 0;
			public bool $fail = false;

			public function find(string $id, mixed $options = null): array {
				$this->finds++;
				if ($this->fail === true) {
					throw new RuntimeException('store down');
				}

				return ['id' => $id, 'title' => 'Read '.$this->finds];
			}

			public function searchObjects(array $query): array {
				$this->searches++;
				return [['id' => 'st-'.$this->searches, 'caseType' => ($query['caseType'] ?? '')]];
			}
		};
	}//end setUp()

	/**
	 * The store over the double, with every schema configured.
	 *
	 * @return CaseTypeStore
	 */
	private function store(): CaseTypeStore {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $key === 'register' ? '42' : '65');

		return new CaseTypeStore(settingsService: $settings);
	}//end store()

	/**
	 * A case type and a list read twice ask OpenRegister once each.
	 *
	 * @return void
	 */
	public function testASecondReadInTheRequestIsKept(): void {
		$store = $this->store();

		$first = $store->readCaseType(caseTypeId: 'ct-1');
		$this->assertSame(expected: $first, actual: $store->readCaseType(caseTypeId: 'ct-1'));
		$store->readCaseType(caseTypeId: 'ct-2');
		$rows = $store->rowsOfType(schemaKey: 'status_type_schema', caseTypeId: 'ct-1');
		$this->assertSame(expected: $rows, actual: $store->rowsOfType(schemaKey: 'status_type_schema', caseTypeId: 'ct-1'));

		$this->assertSame(expected: 2, actual: $this->objects->finds);
		$this->assertSame(expected: 1, actual: $this->objects->searches);
	}//end testASecondReadInTheRequestIsKept()

	/**
	 * A failed read is not kept: the next read asks again.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotKept(): void {
		$store = $this->store();
		$this->objects->fail = true;
		$this->assertSame(expected: [], actual: $store->readCaseType(caseTypeId: 'ct-1'));

		$this->objects->fail = false;
		$this->assertSame(expected: 'ct-1', actual: $store->readCaseType(caseTypeId: 'ct-1')['id']);
		$this->assertSame(expected: 2, actual: $this->objects->finds);
	}//end testAFailedReadIsNotKept()

	/**
	 * An object change drops what is kept; any other event does not.
	 *
	 * @return void
	 */
	public function testAnObjectChangeDropsTheKeptReads(): void {
		$store = $this->store();
		$listener = new CaseTypeStoreForgetListener(store: $store);
		$store->readCaseType(caseTypeId: 'ct-1');

		$listener->handle(new Event());
		$store->readCaseType(caseTypeId: 'ct-1');
		$this->assertSame(expected: 1, actual: $this->objects->finds);

		$listener->handle(new ObjectUpdatedEvent(new ObjectEntity(), new ObjectEntity()));
		$this->assertSame(expected: 'Read 2', actual: $store->readCaseType(caseTypeId: 'ct-1')['title']);
	}//end testAnObjectChangeDropsTheKeptReads()
}//end class
