<?php

/**
 * The chain of custody when a write goes wrong: a previous holding that
 * cannot be closed leaves the case held (by two, never by nobody) and is
 * logged; a holding without an id is logged and left; a register that
 * stores nothing refuses the move; and an unreadable move moment reads as now.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An object service over the in-memory register whose saves can be made to
 * fail on update or to answer something that is not a stored object.
 */
class CustodyFailingObjectService {

	/**
	 * What a save does: 'store', 'refuse-updates' or 'answer-nothing'.
	 *
	 * @var string
	 */
	public string $mode = 'store';

	/**
	 * Wrap a register.
	 *
	 * @param InMemoryRegister $inner The register.
	 */
	public function __construct(public InMemoryRegister $inner) {
	}//end __construct()

	/**
	 * Search as the register does.
	 *
	 * @param mixed ...$args The search arguments.
	 *
	 * @return array
	 */
	public function searchObjectsBySlug(mixed ...$args): array {
		return $this->inner->searchObjectsBySlug(...$args);
	}//end searchObjectsBySlug()

	/**
	 * Save, or fail as configured.
	 *
	 * @param array       $object   The object.
	 * @param mixed       $register The register.
	 * @param mixed       $schema   The schema.
	 * @param string|null $uuid     The uuid to update, or null to create.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, mixed $register='', mixed $schema='', ?string $uuid=null): mixed {
		if ($this->mode === 'answer-nothing') {
			return 'stored somewhere';
		}

		if ($this->mode === 'refuse-updates' && $uuid !== null) {
			throw new \RuntimeException('update refused');
		}

		return $this->inner->saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);
	}//end saveObject()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Custody\CaseCustodyChain
 * @uses \OCA\Dossiq\Tests\Support\InMemoryRegister
 * @uses \OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Custody\CustodyMove
 * @uses \OCA\Dossiq\Service\Custody\CustodyRows
 * @uses \OCA\Dossiq\Service\Custody\DossierRegister
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class CaseCustodyChainFailuresTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The object service the chain writes through.
	 *
	 * @var CustodyFailingObjectService
	 */
	private CustodyFailingObjectService $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A register holding case-1, open with Vergunningen since March.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new CustodyFailingObjectService(inner: new InMemoryRegister());
		$this->objects->inner->seed(
			schema: 'caseCustody',
			uuid: 'h-1',
			row: ['caseId' => 'case-1', 'organisationUnit' => 'vergunningen', 'from' => '2026-03-03T09:00:00+01:00', 'until' => '', 'open' => true, 'sequence' => 1],
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The chain under test, its clock frozen.
	 *
	 * @return CaseCustodyChain
	 */
	private function chain(): CaseCustodyChain {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => ([
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_custody_schema' => 'caseCustody',
			][$key] ?? $default)
		);

		return new CaseCustodyChain(
			settingsService: $settings,
			logger: $this->logger,
			dates: $this->caseDatesFrozenAt(instant: '2026-05-01T10:00:00+02:00'),
		);
	}//end chain()

	/**
	 * Move case-1 to Toezicht.
	 *
	 * @param string $movedAt The move moment.
	 *
	 * @return array The opened holding.
	 */
	private function move(string $movedAt='2026-04-12T14:30:00+02:00'): array {
		return $this->chain()->move(
			caseId: 'case-1',
			organisationUnit: 'toezicht',
			handler: 'sofie',
			reason: 'Handhaving',
			movedBy: 'jan',
			movedAt: $movedAt,
		);
	}//end move()

	/**
	 * A previous holding that cannot be closed stays open beside the new one, and is logged.
	 *
	 * @return void
	 */
	public function testAPreviousHoldingThatCannotBeClosedStaysOpenAndIsLogged(): void {
		$this->objects->mode = 'refuse-updates';
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq custody: the previous holding stayed open',
			['caseId' => 'case-1', 'holding' => 'h-1']
		);

		$opened = $this->move();

		self::assertSame(['toezicht', 2, true], [$opened['organisationUnit'], $opened['sequence'], $opened['open']]);
		$open = array_filter($this->objects->inner->all('caseCustody'), static fn (array $h): bool => $h['open'] === true);
		self::assertCount(2, $open, 'held by two, never by nobody');
	}//end testAPreviousHoldingThatCannotBeClosedStaysOpenAndIsLogged()

	/**
	 * A previous holding without an id is logged and left as it is.
	 *
	 * @return void
	 */
	public function testAPreviousHoldingWithoutAnIdIsLoggedAndLeft(): void {
		unset($this->objects->inner->rows['caseCustody']['h-1']['id']);
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq custody: a holding without an id could not be closed',
			['caseId' => 'case-1']
		);

		$opened = $this->move();

		self::assertSame(2, $opened['sequence']);
		self::assertTrue($this->objects->inner->rows['caseCustody']['h-1']['open']);
		self::assertSame(1, $this->objects->inner->writes, 'only the new holding was written');
	}//end testAPreviousHoldingWithoutAnIdIsLoggedAndLeft()

	/**
	 * A register that answers no stored object refuses the move as indeterminate.
	 *
	 * @return void
	 */
	public function testARegisterThatStoresNothingRefusesTheMove(): void {
		$this->objects->mode = 'answer-nothing';

		try {
			$this->move();
			self::fail('Expected the move to be refused.');
		} catch (RefusedException $e) {
			self::assertSame(CaseCustodyChain::CUSTODY_UNWRITABLE, $e->getRule());
			self::assertSame('The chain of custody could not be written, so the case was not moved.', $e->getSentence());
			self::assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
			self::assertNull($e->getPrevious());
		}

		self::assertTrue($this->objects->inner->rows['caseCustody']['h-1']['open'], 'the previous holding is not closed');
	}//end testARegisterThatStoresNothingRefusesTheMove()

	/**
	 * An unreadable move moment reads as now, on both sides of the join.
	 *
	 * @return void
	 */
	public function testAnUnreadableMomentReadsAsNow(): void {
		$now = $this->caseDatesFrozenAt(instant: '2026-05-01T10:00:00+02:00')->nowAsMoment();

		$opened = $this->move(movedAt: 'next tuesday');

		self::assertSame($now, $opened['from']);
		self::assertSame($now, $this->objects->inner->rows['caseCustody']['h-1']['until']);
	}//end testAnUnreadableMomentReadsAsNow()
}//end class
