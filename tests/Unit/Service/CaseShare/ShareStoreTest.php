<?php

/**
 * Unit tests for ShareStore: configuration, and reads and writes against the
 * configured register and schema cast to ids.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseShare;

use OCA\Dossiq\Service\CaseShare\ShareStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseShare\ShareStore
 */
class ShareStoreTest extends TestCase {

	/**
	 * An object service that records its calls and answers what it is given.
	 *
	 * @param mixed $found The answer to find().
	 *
	 * @return object
	 */
	private function objectService(mixed $found = null): object {
		return new class($found) {
			/** @var list<array<string, mixed>> */
			public array $calls = [];

			public function __construct(private mixed $found) {
			}

			public function find(string $id, int $register, int $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				return $this->found;
			}

			public function saveObject(array $object, int $register, int $schema): mixed {
				$this->calls[] = ['saveObject', $object, $register, $schema];
				return ['saved' => true] + $object;
			}
		};
	}//end objectService()

	public function testItIsConfiguredOnlyWithBothRegisterAndSchema(): void {
		$service = $this->objectService();

		$this->assertTrue((new ShareStore($service, '3', '7'))->isConfigured());
		$this->assertFalse((new ShareStore($service, '', '7'))->isConfigured());
		$this->assertFalse((new ShareStore($service, '3', ''))->isConfigured());
		$this->assertFalse((new ShareStore($service, '0', '7'))->isConfigured());
	}//end testItIsConfiguredOnlyWithBothRegisterAndSchema()

	public function testTheConfiguredValuesAreKeptAsGiven(): void {
		$store = new ShareStore($this->objectService(), '3', '7');

		$this->assertSame('3', $store->register);
		$this->assertSame('7', $store->schema);
	}//end testTheConfiguredValuesAreKeptAsGiven()

	public function testAnArrayAnswerIsReturnedAsIsWithIdsCast(): void {
		$service = $this->objectService(['id' => 'share-1', 'caseId' => 'case-1']);

		$this->assertSame(['id' => 'share-1', 'caseId' => 'case-1'], (new ShareStore($service, '3', '7'))->read('share-1'));
		$this->assertSame([['find', 'share-1', 3, 7]], $service->calls);
	}//end testAnArrayAnswerIsReturnedAsIsWithIdsCast()

	public function testAnObjectAnswerIsSerialised(): void {
		$object = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'share-2'];
			}
		};

		$this->assertSame(['id' => 'share-2'], (new ShareStore($this->objectService($object), '3', '7'))->read('share-2'));
	}//end testAnObjectAnswerIsSerialised()

	public function testAMissingShareIsNull(): void {
		$this->assertNull((new ShareStore($this->objectService(null), '3', '7'))->read('gone'));
	}//end testAMissingShareIsNull()

	public function testSaveWritesToTheConfiguredRegisterAndSchema(): void {
		$service = $this->objectService();

		$saved = (new ShareStore($service, '3', '7'))->save(['caseId' => 'case-1']);

		$this->assertSame(['saved' => true, 'caseId' => 'case-1'], $saved);
		$this->assertSame([['saveObject', ['caseId' => 'case-1'], 3, 7]], $service->calls);
	}//end testSaveWritesToTheConfiguredRegisterAndSchema()
}//end class
