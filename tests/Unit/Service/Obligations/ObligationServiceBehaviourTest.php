<?php

/**
 * Tests for the obligation service's reads and writes.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Obligations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The in-memory register, updating the row an object's own id names, as
 * OpenRegister's saveObject() does when no uuid is passed.
 */
class ObligationRegister extends InMemoryRegister {

	/**
	 * Store one object, updating it in place when it carries an id.
	 *
	 * @param array<string, mixed> $object        The row.
	 * @param int|string           $register      Ignored.
	 * @param int|string           $schema        The schema slug.
	 * @param string|null          $uuid          The uuid to update, or null.
	 * @param bool                 $_rbac         Ignored.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function saveObject(
		array $object,
		int|string $register = '',
		int|string $schema = '',
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$id = (string)($object['id'] ?? '');

		return parent::saveObject(object: $object, register: $register, schema: $schema, uuid: ($uuid ?? ($id !== '' ? $id : null)));
	}//end saveObject()
}//end class

/**
 * Obligations are placed open, settled as met or withdrawn, and read back per
 * case and per source; every failure to read or write answers empty and is logged.
 *
 * @covers \OCA\Dossiq\Service\Obligations\ObligationService
 * @uses   \OCA\Dossiq\Service\Obligations\ObligationDeclaration
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/specs/consultation-management/spec.md
 */
class ObligationServiceBehaviourTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The moment the frozen clock reports, in the administered zone.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-20T00:30:00+02:00';

	/**
	 * The stand-in OpenRegister store.
	 *
	 * @var ObligationRegister
	 */
	private ObligationRegister $register;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The configuration the service reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'obligation_schema' => 'obligation'];

	/**
	 * Build the register and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new ObligationRegister();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test, over the given object service.
	 *
	 * @param object|null $objectService The object service; the in-memory register when omitted.
	 * @param bool        $withService   Whether OpenRegister is available at all.
	 *
	 * @return ObligationService
	 */
	private function service(?object $objectService = null, bool $withService = true): ObligationService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($withService === true ? ($objectService ?? $this->register) : null));
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		return new ObligationService(
			settingsService: $settings,
			declaration: new ObligationDeclaration(),
			dates: $this->caseDatesFrozenAt(),
			logger: $this->logger,
		);
	}//end service()

	/**
	 * An object service whose every call fails.
	 *
	 * @return object
	 */
	private function failingService(): object {
		return new class {
			/**
			 * Fail every find.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return never
			 */
			public function find(string $id, int|string $register = '', int|string $schema = ''): never {
				throw new RuntimeException('read failed');
			}

			/**
			 * Fail every search.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return never
			 */
			public function searchObjectsBySlug(string $register = '', string $schema = '', array $filters = []): never {
				throw new RuntimeException('search failed');
			}

			/**
			 * Fail every save.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return never
			 */
			public function saveObject(array $object = [], int|string $register = '', int|string $schema = ''): never {
				throw new RuntimeException('write failed');
			}
		};
	}//end failingService()

	/**
	 * A placed obligation is stored open, stamped with the moment, and keeps what was placed.
	 *
	 * @return void
	 */
	public function testPlaceStoresAnOpenObligation(): void {
		$id = $this->service()->place(obligation: ['case' => 'case-1', 'kind' => 'advice', 'title' => 'Advies brandweer', 'state' => 'met']);

		$this->assertNotSame('', $id);
		$this->assertSame(
			['case' => 'case-1', 'kind' => 'advice', 'title' => 'Advies brandweer', 'state' => 'open', 'placedAt' => self::NOW, 'id' => $id],
			$this->register->row(schema: 'obligation', uuid: $id)
		);
	}//end testPlaceStoresAnOpenObligation()

	/**
	 * Without a case or a kind nothing is placed.
	 *
	 * @return void
	 */
	public function testPlaceWithoutCaseOrKindPlacesNothing(): void {
		$service = $this->service();

		$this->assertSame('', $service->place(obligation: ['kind' => 'advice']));
		$this->assertSame('', $service->place(obligation: ['case' => 'case-1', 'kind' => '  ']));
		$this->assertSame(0, $this->register->writes);
	}//end testPlaceWithoutCaseOrKindPlacesNothing()

	/**
	 * Without OpenRegister or its schema nothing is placed.
	 *
	 * @return void
	 */
	public function testPlaceWithoutAStoreAnswersEmpty(): void {
		$this->assertSame('', $this->service(withService: false)->place(obligation: ['case' => 'case-1', 'kind' => 'advice']));

		$this->config['obligation_schema'] = '';
		$this->assertSame('', $this->service()->place(obligation: ['case' => 'case-1', 'kind' => 'advice']));
	}//end testPlaceWithoutAStoreAnswersEmpty()

	/**
	 * A write that fails answers empty and is logged with the operation.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLogged(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with('Dossiq obligations: the obligation could not be written', ['operation' => 'place', 'error' => 'write failed']);

		$this->assertSame('', $this->service(objectService: $this->failingService())->place(obligation: ['case' => 'case-1', 'kind' => 'advice']));
	}//end testAFailedWriteIsLogged()

	/**
	 * Meeting an obligation records met, the moment and the note.
	 *
	 * @return void
	 */
	public function testMeetSettlesAsMet(): void {
		$this->register->seed(schema: 'obligation', uuid: 'ob-1', row: ['case' => 'case-1', 'kind' => 'advice', 'state' => 'open']);

		$this->assertTrue($this->service()->meet(obligationId: 'ob-1', note: 'Advies ontvangen'));

		$row = $this->register->row(schema: 'obligation', uuid: 'ob-1');
		$this->assertSame('met', $row['state']);
		$this->assertSame(self::NOW, $row['metAt']);
		$this->assertSame('Advies ontvangen', $row['settlementNote']);
		$this->assertArrayNotHasKey('withdrawnAt', $row);
	}//end testMeetSettlesAsMet()

	/**
	 * Withdrawing records withdrawn and the reason, never met.
	 *
	 * @return void
	 */
	public function testWithdrawSettlesAsWithdrawn(): void {
		$this->register->seed(schema: 'obligation', uuid: 'ob-1', row: ['case' => 'case-1', 'kind' => 'advice', 'state' => 'open']);

		$this->assertTrue($this->service()->withdraw(obligationId: 'ob-1', reason: 'Aanvraag ingetrokken'));

		$row = $this->register->row(schema: 'obligation', uuid: 'ob-1');
		$this->assertSame('withdrawn', $row['state']);
		$this->assertSame(self::NOW, $row['withdrawnAt']);
		$this->assertSame('Aanvraag ingetrokken', $row['withdrawalReason']);
		$this->assertArrayNotHasKey('metAt', $row);
	}//end testWithdrawSettlesAsWithdrawn()

	/**
	 * A meet without a note writes no note field.
	 *
	 * @return void
	 */
	public function testMeetWithoutANoteWritesNoNote(): void {
		$this->register->seed(schema: 'obligation', uuid: 'ob-1', row: ['state' => 'open']);

		$this->assertTrue($this->service()->meet(obligationId: 'ob-1'));
		$this->assertArrayNotHasKey('settlementNote', $this->register->row(schema: 'obligation', uuid: 'ob-1'));
	}//end testMeetWithoutANoteWritesNoNote()

	/**
	 * Settling a blank, missing or unreadable obligation answers false.
	 *
	 * @return void
	 */
	public function testSettlingWhatCannotBeReadAnswersFalse(): void {
		$service = $this->service();
		$this->assertFalse($service->meet(obligationId: ' '));
		$this->assertFalse($service->meet(obligationId: 'missing'));
		$this->assertFalse($this->service(withService: false)->withdraw(obligationId: 'ob-1', reason: 'x'));
		$this->assertSame(0, $this->register->writes);

		$this->logger->expects($this->once())
			->method('warning')
			->with('Dossiq obligations: the obligation to settle could not be read', ['obligation' => 'ob-1', 'error' => 'read failed']);
		$this->assertFalse($this->service(objectService: $this->failingService())->withdraw(obligationId: 'ob-1', reason: 'x'));
	}//end testSettlingWhatCannotBeReadAnswersFalse()

	/**
	 * Every obligation of a case is read, and only the blocking ones are open.
	 *
	 * @return void
	 */
	public function testAllForAndOpenForReadTheCase(): void {
		$this->register->seed(schema: 'obligation', uuid: 'ob-1', row: ['case' => 'case-1', 'state' => 'open']);
		$this->register->seed(schema: 'obligation', uuid: 'ob-2', row: ['case' => 'case-1', 'state' => 'met']);
		$this->register->seed(schema: 'obligation', uuid: 'ob-3', row: ['case' => 'case-2', 'state' => 'open']);
		$this->register->seed(schema: 'obligation', uuid: 'ob-4', row: ['case' => 'case-1', 'state' => 'unknown']);

		$service = $this->service();
		$this->assertSame(['ob-1', 'ob-2', 'ob-4'], array_column($service->allFor(caseId: 'case-1'), 'id'));
		$this->assertSame(['ob-1', 'ob-4'], array_column($service->openFor(caseId: 'case-1'), 'id'));
		$this->assertSame([], $service->allFor(caseId: ' '));
		$this->assertSame([], $this->service(withService: false)->allFor(caseId: 'case-1'));
	}//end testAllForAndOpenForReadTheCase()

	/**
	 * A case whose obligations cannot be read has none, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAllForOfAnUnreadableRegisterIsLoggedAndEmpty(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with('Dossiq obligations: the obligations of a case could not be read', ['case' => 'case-1', 'error' => 'search failed']);

		$this->assertSame([], $this->service(objectService: $this->failingService())->allFor(caseId: 'case-1'));
	}//end testAllForOfAnUnreadableRegisterIsLoggedAndEmpty()

	/**
	 * The obligation a source placed is found by that source.
	 *
	 * @return void
	 */
	public function testIdForSourceFindsThePlacedObligation(): void {
		$this->register->seed(schema: 'obligation', uuid: 'ob-1', row: ['source' => 'consultation-1']);
		$this->register->seed(schema: 'obligation', uuid: 'ob-2', row: ['source' => 'consultation-2']);

		$service = $this->service();
		$this->assertSame('ob-2', $service->idForSource(source: 'consultation-2'));
		$this->assertSame('', $service->idForSource(source: 'consultation-9'));
		$this->assertSame('', $service->idForSource(source: ''));
		$this->assertSame('', $this->service(withService: false)->idForSource(source: 'consultation-1'));
	}//end testIdForSourceFindsThePlacedObligation()

	/**
	 * A source lookup that fails answers empty and is logged.
	 *
	 * @return void
	 */
	public function testIdForSourceOfAnUnreadableRegisterIsLogged(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with('Dossiq obligations: the obligation of a source could not be read', ['source' => 'consultation-1', 'error' => 'search failed']);

		$this->assertSame('', $this->service(objectService: $this->failingService())->idForSource(source: 'consultation-1'));
	}//end testIdForSourceOfAnUnreadableRegisterIsLogged()

	/**
	 * Only open obligations that block the destination status are in the way.
	 *
	 * @return void
	 */
	public function testBlockingNamesWhatWithholdsTheMove(): void {
		$this->register->seed(schema: 'obligation', uuid: 'ob-1', row: ['case' => 'case-1', 'state' => 'open', 'blocks' => ['closing']]);
		$this->register->seed(schema: 'obligation', uuid: 'ob-2', row: ['case' => 'case-1', 'state' => 'open', 'blocks' => ['status-besluit']]);
		$this->register->seed(schema: 'obligation', uuid: 'ob-3', row: ['case' => 'case-1', 'state' => 'withdrawn', 'blocks' => ['status-besluit']]);

		$service = $this->service();
		$this->assertSame(['ob-2'], array_column($service->blocking(caseId: 'case-1', statusId: 'status-besluit', isClosing: false), 'id'));
		$this->assertSame(['ob-1'], array_column($service->blocking(caseId: 'case-1', statusId: 'status-afgerond', isClosing: true), 'id'));
		$this->assertSame([], $service->blocking(caseId: 'case-1', statusId: 'status-intake', isClosing: false));
	}//end testBlockingNamesWhatWithholdsTheMove()

}//end class
