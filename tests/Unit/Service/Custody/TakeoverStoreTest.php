<?php

/**
 * The takeover request store: reading and writing requests, and reading and writing the case they concern.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Custody\CaseTakeoverRequest;
use OCA\Dossiq\Service\Custody\TakeoverStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Custody\TakeoverStore
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Custody\DossierRegister
 */
class TakeoverStoreTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A register with one case and two requests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed('case', 'case-1', ['title' => 'Bouwvergunning', 'handler' => 'jan']);
		$this->register->seed('takeover', 'req-1', ['caseId' => 'case-1', 'status' => 'open']);
		$this->register->seed('takeover', 'req-2', ['caseId' => 'case-2', 'status' => 'open']);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A store over the register, with every key configured unless named.
	 *
	 * @param array<int, string> $unconfigured Keys that answer ''.
	 * @param bool               $available    Whether OpenRegister is available.
	 *
	 * @return TakeoverStore
	 */
	private function store(array $unconfigured = [], bool $available = true): TakeoverStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($available === true ? $this->register : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($unconfigured): string {
				if (in_array($key, $unconfigured, true) === true) {
					return '';
				}

				return (['register' => 'dossiq', 'case_schema' => 'case', 'case_takeover_schema' => 'takeover'][$key] ?? '');
			}
		);

		return new TakeoverStore(settingsService: $settings, logger: $this->logger);
	}//end store()

	public function testRowsAreTheMatchingRequests(): void {
		$rows = $this->store()->rows(filters: ['caseId' => 'case-1']);

		self::assertSame(['req-1'], array_column($rows, 'id'));
	}//end testRowsAreTheMatchingRequests()

	public function testUnreadableRequestsAreAWarningAndNone(): void {
		$this->logger->expects($this->once())->method('warning')
			->with('Dossiq takeover: the requests could not be read', ['exception' => 'Dossiq schema case_takeover_schema not configured']);

		self::assertSame([], $this->store(unconfigured: ['case_takeover_schema'])->rows(filters: []));
	}//end testUnreadableRequestsAreAWarningAndNone()

	public function testWriteDropsTheEnvelopeAndSavesUnderTheGivenUuid(): void {
		$saved = $this->store()->write(record: ['caseId' => 'case-1', 'status' => 'granted', 'id' => 'spoofed', 'uuid' => 'x', '@self' => ['owner' => 'x']], uuid: 'req-1');

		self::assertSame(['caseId' => 'case-1', 'status' => 'granted', 'id' => 'req-1'], $saved);
		self::assertSame('granted', $this->register->row('takeover', 'req-1')['status']);
	}//end testWriteDropsTheEnvelopeAndSavesUnderTheGivenUuid()

	public function testAnUnwritableRequestIsAnIndeterminateRefusal(): void {
		$this->register->refusedSchemas = ['takeover'];

		try {
			$this->store()->write(record: ['caseId' => 'case-1'], uuid: null);
			self::fail('must refuse');
		} catch (RefusedException $e) {
			self::assertSame(CaseTakeoverRequest::UNWRITABLE, $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertSame('Save refused for takeover', $e->getPrevious()?->getMessage());
		}
	}//end testAnUnwritableRequestIsAnIndeterminateRefusal()

	public function testUuidOfPrefersTheIdThenTheUuid(): void {
		$store = $this->store();

		self::assertSame('a', $store->uuidOf(row: ['id' => ' a ', 'uuid' => 'b']));
		self::assertSame('b', $store->uuidOf(row: ['uuid' => 'b']));
		self::assertSame('', $store->uuidOf(row: []));
	}//end testUuidOfPrefersTheIdThenTheUuid()

	public function testRequireCaseAnswersTheCase(): void {
		self::assertSame('Bouwvergunning', $this->store()->requireCase(caseId: ' case-1 ')['title']);
	}//end testRequireCaseAnswersTheCase()

	public function testAnUnknownCaseIsUnprocessable(): void {
		try {
			$this->store()->requireCase(caseId: 'case-ghost');
			self::fail('must refuse');
		} catch (RefusedException $e) {
			self::assertSame(CaseTakeoverRequest::CASE_UNREADABLE, $e->getRule());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}
	}//end testAnUnknownCaseIsUnprocessable()

	public function testAnUnreadableCaseIsIndeterminate(): void {
		try {
			$this->store(available: false)->requireCase(caseId: 'case-1');
			self::fail('must refuse');
		} catch (RefusedException $e) {
			self::assertSame(CaseTakeoverRequest::CASE_UNREADABLE, $e->getRule());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertSame('OpenRegister is not available', $e->getPrevious()?->getMessage());
		}
	}//end testAnUnreadableCaseIsIndeterminate()

	public function testWriteCaseMergesTheChangesIntoTheCase(): void {
		$this->store()->writeCase(case: ['id' => 'case-1', 'title' => 'Bouwvergunning', 'handler' => 'jan', '@self' => []], changes: ['handler' => 'piet']);

		self::assertSame(['title' => 'Bouwvergunning', 'handler' => 'piet', 'id' => 'case-1'], $this->register->row('case', 'case-1'));
	}//end testWriteCaseMergesTheChangesIntoTheCase()

	public function testWriteCaseWithoutAnIdOrChangesWritesNothing(): void {
		$store = $this->store();
		$store->writeCase(case: ['title' => 'x'], changes: ['handler' => 'piet']);
		$store->writeCase(case: ['id' => 'case-1'], changes: []);

		self::assertSame(0, $this->register->writes);
	}//end testWriteCaseWithoutAnIdOrChangesWritesNothing()

	public function testAFailedCaseWriteIsLoggedNotThrown(): void {
		$this->register->refusedSchemas = ['case'];
		$this->logger->expects($this->once())->method('error')
			->with('Dossiq takeover: the case seat could not be written', ['caseId' => 'case-1', 'exception' => 'Save refused for case']);

		$this->store()->writeCase(case: ['id' => 'case-1'], changes: ['handler' => 'piet']);
	}//end testAFailedCaseWriteIsLoggedNotThrown()
	public function testARegisterThatStoresNothingIsAnIndeterminateRefusal(): void {
		$register = new class {
			/**
			 * @param array       $object   The object.
			 * @param mixed       $register The register.
			 * @param mixed       $schema   The schema.
			 * @param string|null $uuid     The uuid.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, mixed $register='', mixed $schema='', ?string $uuid=null): mixed {
				return 'stored somewhere';
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => 'dossiq', 'case_takeover_schema' => 'takeover'][$key] ?? '')
		);

		try {
			(new TakeoverStore(settingsService: $settings, logger: $this->logger))->write(record: ['caseId' => 'case-1'], uuid: null);
			self::fail('must refuse');
		} catch (RefusedException $e) {
			self::assertSame(CaseTakeoverRequest::UNWRITABLE, $e->getRule());
			self::assertSame('The request could not be recorded, so nobody was asked.', $e->getSentence());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertNull($e->getPrevious());
		}
	}//end testARegisterThatStoresNothingIsAnIndeterminateRefusal()
}//end class
