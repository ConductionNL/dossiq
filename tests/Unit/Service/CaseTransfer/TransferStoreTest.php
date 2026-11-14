<?php

/**
 * Unit tests for TransferStore: configuration, and reads and writes against
 * the transfer register and schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseTransfer;

use OCA\Dossiq\Service\CaseTransfer\TransferStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseTransfer\TransferStore
 */
class TransferStoreTest extends TestCase {

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
				return 'saved-object';
			}
		};
	}//end objectService()

	public function testItIsConfiguredOnlyWithBothRegisterAndSchema(): void {
		$service = $this->objectService();

		$this->assertTrue((new TransferStore($service, 4, 9))->isConfigured());
		$this->assertFalse((new TransferStore($service, 0, 9))->isConfigured());
		$this->assertFalse((new TransferStore($service, 4, 0))->isConfigured());
	}//end testItIsConfiguredOnlyWithBothRegisterAndSchema()

	public function testAnObjectAnswerIsSerialised(): void {
		$object = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'transfer-1', 'status' => 'pending'];
			}
		};
		$service = $this->objectService($object);

		$this->assertSame(['id' => 'transfer-1', 'status' => 'pending'], (new TransferStore($service, 4, 9))->read('transfer-1'));
		$this->assertSame([['find', 'transfer-1', 4, 9]], $service->calls);
	}//end testAnObjectAnswerIsSerialised()

	public function testAnArrayAnswerIsReturnedAsAnArray(): void {
		$this->assertSame(['id' => 'transfer-2'], (new TransferStore($this->objectService(['id' => 'transfer-2']), 4, 9))->read('transfer-2'));
	}//end testAnArrayAnswerIsReturnedAsAnArray()

	public function testAMissingTransferIsNull(): void {
		$this->assertNull((new TransferStore($this->objectService(null), 4, 9))->read('gone'));
	}//end testAMissingTransferIsNull()

	public function testSaveWritesToTheTransferRegisterAndSchema(): void {
		$service = $this->objectService();

		$this->assertSame('saved-object', (new TransferStore($service, 4, 9))->save(['caseId' => 'case-1']));
		$this->assertSame([['saveObject', ['caseId' => 'case-1'], 4, 9]], $service->calls);
	}//end testSaveWritesToTheTransferRegisterAndSchema()
}//end class
