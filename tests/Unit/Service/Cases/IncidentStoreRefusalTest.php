<?php

/**
 * The incident store's guards and refusals, against a register that fails.
 *
 * `IncidentStoreTest` watches the happy path over a working register. This
 * suite watches what happens when the input is blank or the register cannot
 * be read or written: a blank input must not reach the register at all, an
 * unreadable report must be refused with the right status, and an unwritable
 * one must never be reported as recorded.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\IncidentRecord;
use OCA\Dossiq\Service\Cases\IncidentStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Blank inputs, unreadable reports and unwritable records.
 *
 * @covers \OCA\Dossiq\Service\Cases\IncidentStore
 * @uses \OCA\Dossiq\Service\Cases\IncidentRecord
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\DayCount
 * @uses \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses \OCA\Dossiq\Service\Custody\StoredInstant
 *
 * @spec openspec/specs/case-management/spec.md
 */
class IncidentStoreRefusalTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The register the store reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The logger the store reports to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A fresh register and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A blank case or person reads nothing, and never asks the register.
	 *
	 * @return void
	 */
	public function testBlankInputsReadNothingAndNeverAskTheRegister(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');
		$settings->expects($this->never())->method('getConfigValue');

		$store = $this->storeOver(settings: $settings);

		self::assertSame([], $store->onCase(caseId: '   '));
		self::assertSame([], $store->ownedBy(userId: ''));
		self::assertSame([], $store->openCountsFor(caseIds: ['', '  ']));
	}//end testBlankInputsReadNothingAndNeverAskTheRegister()

	/**
	 * A failed read is logged and reads as no reports, not as an error.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndReadsAsNoReports(): void {
		$this->register->seed(schema: 'caseIncident', uuid: 'i-1', row: ['case' => 'case-1', 'state' => 'open']);
		$this->register->missingSchemas = ['caseIncident'];

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq incident: the reports could not be read',
				['exception' => 'No schema caseIncident in this register'],
			);

		self::assertSame([], $this->store()->onCase(caseId: 'case-1'));
	}//end testAFailedReadIsLoggedAndReadsAsNoReports()

	/**
	 * A hand-off on a register that cannot be reached is refused as indeterminate.
	 *
	 * @return void
	 */
	public function testAHandOffOnAnUnreachableRegisterIsIndeterminate(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$this->logger->expects($this->never())->method('info');

		try {
			$this->storeOver(settings: $settings)->assign(incidentId: 'i-1', assignee: 'inspecteur');
			self::fail('An unreadable report must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(IncidentStore::UNKNOWN, $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertSame('We could not read that report, so nothing was changed.', $e->getSentence());
			self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
			self::assertSame('OpenRegister is not available', $e->getPrevious()->getMessage());
		}
	}//end testAHandOffOnAnUnreachableRegisterIsIndeterminate()

	/**
	 * Settling a report that does not exist is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testSettlingAnUnknownReportIsRefused(): void {
		try {
			$this->store()->settle(incidentId: 'nope', outcome: 'Afgehandeld');
			self::fail('An unknown report must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(IncidentStore::UNKNOWN, $e->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			self::assertSame('That report could not be found.', $e->getSentence());
			self::assertNull($e->getPrevious());
		}

		self::assertSame(0, $this->register->writes);
	}//end testSettlingAnUnknownReportIsRefused()

	/**
	 * A write the register throws on is refused as unwritable.
	 *
	 * @return void
	 */
	public function testAThrowingWriteIsRefusedAsUnwritable(): void {
		$this->register->refusedSchemas = ['caseIncident'];

		try {
			$this->store()->record(caseId: 'case-1', eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart');
			self::fail('A refused write must not read as recorded.');
		} catch (RefusedException $e) {
			self::assertSame(IncidentStore::UNWRITABLE, $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertSame('The report could not be recorded.', $e->getSentence());
			self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
			self::assertSame('Save refused for caseIncident', $e->getPrevious()->getMessage());
		}

		self::assertSame([], $this->register->all(schema: 'caseIncident'));
	}//end testAThrowingWriteIsRefusedAsUnwritable()

	/**
	 * A write that returns nothing representable is refused, not reported as stored.
	 *
	 * @return void
	 */
	public function testAWriteReturningNoObjectIsRefused(): void {
		$service = new class {
			/**
			 * Answers a save with nothing an array can be made of.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return string Not an object.
			 */
			public function saveObject(array $object, int|string $register = '', int|string $schema = '', ?string $uuid = null): string {
				return 'saved';
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_incident_schema' => 'caseIncident'][$key] ?? $default
		);

		try {
			$this->storeOver(settings: $settings)->record(
				caseId: 'case-1',
				eventDate: '2026-03-21T09:00:00+01:00',
				description: 'Maart',
			);
			self::fail('A write with no stored object must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(IncidentStore::UNWRITABLE, $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertNull($e->getPrevious());
		}
	}//end testAWriteReturningNoObjectIsRefused()

	/**
	 * A store over the in-memory register.
	 *
	 * @return IncidentStore The store.
	 */
	private function store(): IncidentStore {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'case_incident_schema' => 'caseIncident'][$key] ?? $default
		);

		return $this->storeOver(settings: $settings);
	}//end store()

	/**
	 * A store over the given settings.
	 *
	 * @param SettingsService $settings The settings.
	 *
	 * @return IncidentStore The store.
	 */
	private function storeOver(SettingsService $settings): IncidentStore {
		return new IncidentStore(
			settingsService: $settings,
			record: new IncidentRecord(dates: $this->caseDates()),
			logger: $this->logger,
		);
	}//end storeOver()
}//end class
