<?php

/**
 * CasePriorityRaiseService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePriorityRaiseService;
use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The term rule lifts a case's priority floor to the declared minimum, only
 * upwards, writes it as the system, and falls back to the current priority
 * whenever it cannot read or write the case.
 *
 * @covers \OCA\Dossiq\Service\CasePriorityRaiseService
 * @uses   \OCA\Dossiq\Service\CasePriorityService
 * @uses   \OCA\Dossiq\Service\PriorityFloorRaise
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CasePriorityRaiseServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A fake object service holding one case.
	 *
	 * @param array<string,mixed>|null $case  The stored case, null when absent.
	 * @param mixed                    $patch What patchObject returns, or a Throwable it throws.
	 *
	 * @return object The fake.
	 */
	private function objectService(?array $case, mixed $patch = null): object {
		return new class($case, $patch) {
			/**
			 * Calls made.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed>|null $case  The case.
			 * @param mixed                    $patch The patch result.
			 */
			public function __construct(private ?array $case, private mixed $patch) {
			}

			/**
			 * Run an operation as the system.
			 *
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->calls[] = ['runAsSystem'];
				return $operation();
			}

			/**
			 * Find an object.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function find(string $id, string $register, string $schema): array {
				$this->calls[] = ['find', $id, $register, $schema];
				if ($this->case === null) {
					throw new DoesNotExistException('gone');
				}

				return $this->case;
			}

			/**
			 * Patch an object.
			 *
			 * @param string              $objectId The id.
			 * @param array<string,mixed> $data     The changes.
			 * @param string              $register The register.
			 * @param string              $schema   The schema.
			 *
			 * @return mixed
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): mixed {
				$this->calls[] = ['patchObject', $objectId, $data, $register, $schema];
				if ($this->patch instanceof \Throwable) {
					throw $this->patch;
				}

				return $this->patch;
			}
		};
	}//end objectService()

	/**
	 * The service under test, wired to the given object service.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $schema        The configured case schema.
	 *
	 * @return CasePriorityRaiseService The service.
	 */
	private function service(?object $objectService, string $schema = 'case'): CasePriorityRaiseService {
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['case_schema', '', $schema],
			]
		);

		return new CasePriorityRaiseService(
			settingsService: $this->settings,
			priorityService: new CasePriorityService(
				resolver: $this->createMock(CaseTypeResolver::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			logger: $this->logger
		);
	}//end service()

	/**
	 * Two days before the term a normal case gets a high floor, written as the
	 * system with the rule's id, and the stored priority is returned.
	 *
	 * @return void
	 */
	public function testRaisesTheFloorAndReturnsTheStoredPriority(): void {
		$objects = $this->objectService(case: ['priority' => 'normal'], patch: ['priority' => 'high']);
		$this->logger->expects($this->once())->method('info')->with(
			'Dossiq: the term rule raised a case priority',
			['case' => 'case-1', 'floor' => 'high', 'rule' => 'case-priority-term-raise']
		);
		$this->logger->expects($this->never())->method('warning');

		$result = $this->service(objectService: $objects)->raiseForThreshold(caseId: 'case-1', threshold: 2);

		$this->assertSame('high', $result);
		$this->assertSame(
			[
				['runAsSystem'],
				['find', 'case-1', 'dossiq', 'case'],
				['runAsSystem'],
				['patchObject', 'case-1', ['priorityFloor' => 'high', 'priorityRaisedBy' => 'case-priority-term-raise'], 'dossiq', 'case'],
			],
			$objects->calls
		);
	}//end testRaisesTheFloorAndReturnsTheStoredPriority()

	/**
	 * A floor already at or above the threshold's minimum is not written again.
	 *
	 * @return void
	 */
	public function testAFloorAlreadyMetIsNotWrittenAgain(): void {
		$objects = $this->objectService(case: ['priority' => 'urgent', 'priorityFloor' => 'urgent']);
		$this->logger->expects($this->never())->method('info');

		$this->assertSame('urgent', $this->service(objectService: $objects)->raiseForThreshold(caseId: 'case-1', threshold: 2));
		$this->assertCount(2, $objects->calls);
	}//end testAFloorAlreadyMetIsNotWrittenAgain()

	/**
	 * A threshold the rule does not declare leaves the case as it is.
	 *
	 * @return void
	 */
	public function testAnUndeclaredThresholdReturnsTheCurrentPriority(): void {
		$objects = $this->objectService(case: ['priority' => 'low']);

		$this->assertSame('low', $this->service(objectService: $objects)->raiseForThreshold(caseId: 'case-1', threshold: 5));
		$this->assertSame([['runAsSystem'], ['find', 'case-1', 'dossiq', 'case']], $objects->calls);
	}//end testAnUndeclaredThresholdReturnsTheCurrentPriority()

	/**
	 * A failed write is logged as a warning and returns the current priority.
	 *
	 * @return void
	 */
	public function testAFailedWriteFallsBackToTheCurrentPriority(): void {
		$objects = $this->objectService(case: ['priority' => 'normal'], patch: new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Dossiq: the term rule could not raise a case priority',
			['case' => 'case-1', 'floor' => 'urgent', 'error' => 'locked']
		);
		$this->logger->expects($this->never())->method('info');

		$this->assertSame('normal', $this->service(objectService: $objects)->raiseForThreshold(caseId: 'case-1', threshold: 0));
	}//end testAFailedWriteFallsBackToTheCurrentPriority()

	/**
	 * When the write returns nothing readable the priority is the current one raised to the floor.
	 *
	 * @return void
	 */
	public function testAnUnreadableWriteResultRaisesTheCurrentPriority(): void {
		$objects = $this->objectService(case: ['priority' => 'normal'], patch: null);

		$this->assertSame('urgent', $this->service(objectService: $objects)->raiseForThreshold(caseId: 'case-1', threshold: 0));
	}//end testAnUnreadableWriteResultRaisesTheCurrentPriority()

	/**
	 * A stored case without a priority yields the current priority raised to the floor,
	 * which never lowers a higher current priority.
	 *
	 * @return void
	 */
	public function testAStoredCaseWithoutPriorityKeepsAHigherCurrentPriority(): void {
		$objects = $this->objectService(case: ['priority' => 'urgent', 'priorityFloor' => 'low'], patch: ['id' => 'case-1']);

		$this->assertSame('urgent', $this->service(objectService: $objects)->raiseForThreshold(caseId: 'case-1', threshold: 2));
		$this->assertSame(['priorityFloor' => 'high', 'priorityRaisedBy' => 'case-priority-term-raise'], $objects->calls[3][2]);
	}//end testAStoredCaseWithoutPriorityKeepsAHigherCurrentPriority()

	/**
	 * A case the store does not hold gives an empty priority.
	 *
	 * @return void
	 */
	public function testUnreadableCasesGiveAnEmptyPriority(): void {
		$this->assertSame('', $this->service(objectService: $this->objectService(case: null))->raiseForThreshold(caseId: 'case-1', threshold: 2));

	}//end testUnreadableCasesGiveAnEmptyPriority()

	/**
	 * A blank case id is not looked up.
	 *
	 * @return void
	 */
	public function testABlankCaseIdIsNotLookedUp(): void {
		$objects = $this->objectService(case: ['priority' => 'low']);

		$this->assertSame('', $this->service(objectService: $objects)->priorityOf(caseId: '   '));
		$this->assertSame([], $objects->calls);
	}//end testUnreadableCasesGiveAnEmptyPriority()

	/**
	 * Without an object service nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectServiceThePriorityIsEmpty(): void {
		$this->assertSame('', $this->service(objectService: null)->priorityOf(caseId: 'case-1'));
	}//end testWithoutAnObjectServiceThePriorityIsEmpty()

	/**
	 * Without a configured case schema nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutACaseSchemaThePriorityIsEmpty(): void {
		$objects = $this->objectService(case: ['priority' => 'high']);

		$this->assertSame('', $this->service(objectService: $objects, schema: '')->raiseForThreshold(caseId: 'case-1', threshold: 2));
		$this->assertSame([], $objects->calls);
	}//end testWithoutACaseSchemaThePriorityIsEmpty()

	/**
	 * The priority of a stored case is its priority field, empty when it has none.
	 *
	 * @return void
	 */
	public function testPriorityOfReadsThePriorityField(): void {
		$this->assertSame('high', $this->service(objectService: $this->objectService(case: ['priority' => 'high']))->priorityOf(caseId: 'case-1'));
	}//end testPriorityOfReadsThePriorityField()

	/**
	 * A stored case without a priority field has an empty priority.
	 *
	 * @return void
	 */
	public function testPriorityOfACaseWithoutPriorityIsEmpty(): void {
		$this->assertSame('', $this->service(objectService: $this->objectService(case: ['id' => 'case-1']))->priorityOf(caseId: 'case-1'));
	}//end testPriorityOfACaseWithoutPriorityIsEmpty()
}//end class
