<?php

/**
 * Cases and case types are read and written as the system, and a failure reads as absent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\SystemCaseStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Intake\SystemCaseStore
 * @uses   \OCA\Dossiq\Service\Support\UnscopedObjectReader
 * @uses   \OCA\Dossiq\Service\Intake\WrittenCase
 */
class SystemCaseStoreTest extends TestCase {

	/**
	 * The logger, to see failures reported.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The store under test, on the configured case and case type schemas.
	 *
	 * @return SystemCaseStore
	 */
	private function store(): SystemCaseStore {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ['case_schema' => 'case', 'case_type_schema' => 'caseType'][$key] ?? $default
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new SystemCaseStore(settingsService: $settings, logger: $this->logger);
	}//end store()

	/**
	 * The case type and the case are read from their configured schemas.
	 *
	 * @return void
	 */
	public function testTheCaseTypeAndTheCaseAreReadFromTheirSchemas(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'caseType', uuid: 'type-1', row: ['title' => 'Request']);
		$register->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'A case']);
		$store = $this->store();

		self::assertSame('Request', $store->caseType(objectService: $register, register: 'dossiq', caseTypeId: 'type-1')['title']);
		self::assertNull($store->caseType(objectService: $register, register: 'dossiq', caseTypeId: 'case-1'));
		self::assertSame('A case', $store->readCase(objectService: $register, register: 'dossiq', caseId: 'case-1')['title']);
		self::assertSame([], $store->readCase(objectService: $register, register: 'dossiq', caseId: 'missing'));
	}//end testTheCaseTypeAndTheCaseAreReadFromTheirSchemas()

	/**
	 * A read that fails is logged and reads as absent.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndReadsAsAbsent(): void {
		$broken = new class {
			public function find(int|string $id, int|string $register, int|string $schema, bool $_rbac, bool $_multitenancy): array {
				throw new RuntimeException('down');
			}
		};
		$store = $this->store();
		$this->logger->expects(self::once())->method('error');
		$this->logger->expects(self::once())->method('warning');

		self::assertNull($store->caseType(objectService: $broken, register: 'dossiq', caseTypeId: 'type-1'));
		self::assertSame([], $store->readCase(objectService: $broken, register: 'dossiq', caseId: 'case-1'));
	}//end testAFailedReadIsLoggedAndReadsAsAbsent()

	/**
	 * A written case answers its id, and the number and deadline it carries.
	 *
	 * @return void
	 */
	public function testAWrittenCaseAnswersItsIdNumberAndDeadline(): void {
		$register = new InMemoryRegister();

		$written = $this->store()->write(
			objectService: $register,
			register: 'dossiq',
			case: ['title' => 'A case', 'identifier' => 'Z-2026-1', 'deadline' => '2026-11-08']
		);

		self::assertNotNull($written);
		self::assertSame('generated-1', $written['id']);
		// The stored deadline, and the same day as the confirmation names it
		// (portal-woo-request-form-takes-dutch-input REQ-WDI-003).
		self::assertSame(
			['identifier' => 'Z-2026-1', 'deadline' => '2026-11-08', 'deadlineDay' => '8 november 2026'],
			$written['answer']
		);
		self::assertSame('A case', $register->row(schema: 'case', uuid: 'generated-1')['title']);
	}//end testAWrittenCaseAnswersItsIdNumberAndDeadline()

	/**
	 * A write that fails is logged and answers null.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedAndAnswersNull(): void {
		$broken = new class {
			public function saveObject(array $object, int|string $register, int|string $schema, bool $_rbac, bool $_multitenancy): array {
				throw new RuntimeException('down');
			}
		};
		$store = $this->store();
		$this->logger->expects(self::once())->method('error');

		self::assertNull($store->write(objectService: $broken, register: 'dossiq', case: ['title' => 'A case']));
	}//end testAFailedWriteIsLoggedAndAnswersNull()
}//end class
