<?php

/**
 * The reading and writing half of the planned next action (row 3.28).
 *
 * The decision about what follows what is pinned in
 * `PlannedActionChainTest`. What is pinned here is everything around it, and
 * each of these has its own way of being wrong invisibly:
 *
 *  - completing an action writing the successor but not the completion, so the
 *    case grows a second open action every time somebody ticks one off;
 *  - `nextFor()` answering whichever row came back first, which makes "what
 *    happens next" depend on the register's row order;
 *  - a completed or cancelled action still counting as planned;
 *  - an organisation-wide action type being filtered out by a server-side
 *    `caseType` filter, which would leave exactly the shared types unreachable;
 *  - an unreadable register throwing instead of answering nothing, taking the
 *    case page down over a planning aid.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\PlannedAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\PlannedAction;

use DateTimeImmutable;
use OCA\Dossiq\Service\PlannedAction\PlannedActionChain;
use OCA\Dossiq\Service\PlannedAction\PlannedActionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the planned action service.
 *
 * @covers \OCA\Dossiq\Service\PlannedAction\PlannedActionService
 */
class PlannedActionServiceTest extends TestCase {

	private SettingsService $settingsService;

	private LoggerInterface $logger;

	/** @var array<int, array<string, mixed>> Every saveObject() call, in order. */
	private array $writes = [];

	/**
	 * Set up shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->writes = [];
	}//end setUp()

	/**
	 * Script the object service with rows per schema, and record every write.
	 *
	 * The double answers by SCHEMA, because the service reads two of them and
	 * a double that answered the same rows to both would let a service that
	 * confused actions with action types pass every test here.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Rows per schema slug.
	 *
	 * @return void
	 */
	private function givenRegister(array $bySchema): void {
		$writes = &$this->writes;
		$objectService = new class($bySchema, $writes) {

			/**
			 * @param array<string, array<int, array<string, mixed>>> $bySchema Rows per schema.
			 * @param array<int, array<string, mixed>> $writes The write log, by reference.
			 */
			public function __construct(
				private readonly array $bySchema,
				private array &$writes,
			) {
			}

			/**
			 * Mimic ObjectService::searchObjectsBySlug(), filtering on `case`.
			 *
			 * This and not `searchObjects()`, because the register and schema
			 * the service passes are SLUGS, and `SearchesObjects` sends a slug
			 * down the `searchObjectsBySlug` bridge. A double that implemented
			 * only the numeric entry point would answer nothing here and read
			 * as a case with no planned actions.
			 *
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(
				string $register,
				string $schema,
				array $filters = [],
			): array {
				$rows = ($this->bySchema[$schema] ?? []);
				$caseId = (string)($filters['case'] ?? '');
				if ($caseId === '') {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static fn (array $row): bool => ((string)($row['case'] ?? '') === $caseId)
					)
				);
			}

			/**
			 * Mimic ObjectService::saveObject(), recording what was written.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 * @param string|null $uuid The uuid, or null to create.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(
				array $object,
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
			): array {
				$saved = array_merge($object, ['id' => ($uuid ?? 'new-action')]);
				$this->writes[] = ['uuid' => $uuid, 'object' => $saved];

				return $saved;
			}
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (
				$key === 'register' ? 'dossiq' : ''
			)
		);
	}//end givenRegister()

	/**
	 * Build the service under test.
	 *
	 * @return PlannedActionService The service.
	 */
	private function service(): PlannedActionService {
		return new PlannedActionService(
			settingsService: $this->settingsService,
			chain: new PlannedActionChain(workingDays: new WorkingDayCalculator()),
			logger: $this->logger,
		);
	}//end service()

	/**
	 * The action types used across these tests.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function typeRows(): array {
		return [
			[
				'identifier' => 'confirm',
				'label' => 'Send the confirmation',
				'successor' => 'follow-up-call',
				'successorOffsetWorkingDays' => 10,
				'ownerRole' => 'behandelaar',
				'caseType' => 'ct-1',
			],
			[
				'identifier' => 'follow-up-call',
				'label' => 'Call after ten days',
				'successor' => '',
				'ownerRole' => 'behandelaar',
			],
		];
	}//end typeRows()

	/**
	 * The next action is the EARLIEST planned one, not the first row back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testTheNextActionIsTheEarliestPlannedOne(): void {
		$this->givenRegister(
			[
				'plannedAction' => [
					['id' => 'a2', 'case' => 'c1', 'plannedFor' => '2026-04-01', 'state' => 'planned'],
					['id' => 'a1', 'case' => 'c1', 'plannedFor' => '2026-03-10', 'state' => 'planned'],
				],
			]
		);

		$next = $this->service()->nextFor('c1');

		$this->assertSame('a1', $next['id']);
	}//end testTheNextActionIsTheEarliestPlannedOne()

	/**
	 * A completed or cancelled action is not what happens next.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testACompletedActionIsNotPlanned(): void {
		$this->givenRegister(
			[
				'plannedAction' => [
					['id' => 'a1', 'case' => 'c1', 'plannedFor' => '2026-03-01', 'state' => 'completed'],
					['id' => 'a2', 'case' => 'c1', 'plannedFor' => '2026-03-02', 'state' => 'cancelled'],
					['id' => 'a3', 'case' => 'c1', 'plannedFor' => '2026-03-03', 'state' => 'planned'],
				],
			]
		);

		$this->assertSame('a3', $this->service()->nextFor('c1')['id']);
		$this->assertCount(1, $this->service()->plannedOn('c1'));
	}//end testACompletedActionIsNotPlanned()

	/**
	 * A case with nothing planned answers null, and the page says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testACaseWithNothingPlannedAnswersNull(): void {
		$this->givenRegister(['plannedAction' => []]);

		$this->assertNull($this->service()->nextFor('c1'));
	}//end testACaseWithNothingPlannedAnswersNull()

	/**
	 * The actions of one case never leak into another's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testAnotherCasesActionsStayOut(): void {
		$this->givenRegister(
			[
				'plannedAction' => [
					['id' => 'a1', 'case' => 'c2', 'plannedFor' => '2026-03-01', 'state' => 'planned'],
				],
			]
		);

		$this->assertNull($this->service()->nextFor('c1'));
	}//end testAnotherCasesActionsStayOut()

	/**
	 * 🔴 Completing writes BOTH the completion and the successor.
	 *
	 * A completion that only wrote the successor would grow the case a second
	 * open action every time somebody ticked one off, and the list would look
	 * like a busy case rather than a bug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testCompletingWritesTheCompletionAndTheSuccessor(): void {
		$this->givenRegister(['plannedActionType' => $this->typeRows()]);

		$planned = $this->service()->complete(
			action: [
				'id' => 'a1',
				'case' => 'c1',
				'caseType' => 'ct-1',
				'actionType' => 'confirm',
				'state' => 'planned',
			],
			completedBy: 'alice',
			roleHolders: ['behandelaar' => 'bob'],
			completedOn: new DateTimeImmutable('2026-03-02')
		);

		$this->assertCount(2, $this->writes);

		$completion = $this->writes[0];
		$this->assertSame('a1', $completion['uuid'], 'the completion UPDATES the action');
		$this->assertSame('completed', $completion['object']['state']);
		$this->assertSame('alice', $completion['object']['completedBy']);

		$successor = $this->writes[1];
		$this->assertNull($successor['uuid'], 'the successor is a NEW action');
		$this->assertSame('follow-up-call', $successor['object']['actionType']);
		$this->assertSame('bob', $successor['object']['owner']);
		$this->assertSame('a1', $successor['object']['plannedFrom']);
		$this->assertSame('follow-up-call', $planned['actionType']);
	}//end testCompletingWritesTheCompletionAndTheSuccessor()

	/**
	 * Ending a chain still records the completion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testEndingTheChainStillRecordsTheCompletion(): void {
		$this->givenRegister(['plannedActionType' => $this->typeRows()]);

		$planned = $this->service()->complete(
			action: [
				'id' => 'a2',
				'case' => 'c1',
				'caseType' => 'ct-1',
				'actionType' => 'follow-up-call',
			],
			completedBy: 'alice',
			completedOn: new DateTimeImmutable('2026-03-02')
		);

		$this->assertNull($planned, 'the chain ends');
		$this->assertCount(1, $this->writes);
		$this->assertSame('completed', $this->writes[0]['object']['state']);
	}//end testEndingTheChainStillRecordsTheCompletion()

	/**
	 * A type naming no case type is offered on every case type.
	 *
	 * Filtering on `caseType` server-side would silently drop exactly the
	 * organisation-wide types, which is the opposite of what an empty field
	 * means.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testAnOrganisationWideTypeIsOfferedEverywhere(): void {
		$this->givenRegister(['plannedActionType' => $this->typeRows()]);

		$types = $this->service()->typesFor('ct-2');

		$this->assertArrayHasKey('follow-up-call', $types, 'the shared type is offered');
		$this->assertArrayNotHasKey('confirm', $types, 'the ct-1 type is not');
	}//end testAnOrganisationWideTypeIsOfferedEverywhere()

	/**
	 * An unreadable register answers nothing rather than throwing.
	 *
	 * The opposite of `CaseAccessGuard`, on purpose: this is a planning aid,
	 * and a case page that cannot show its next action is better than a case
	 * page that will not render.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function testAnUnreadableRegisterAnswersNothing(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$this->settingsService->method('getConfigValue')->willReturn('');

		$service = $this->service();

		$this->assertNull($service->nextFor('c1'));
		$this->assertSame([], $service->plannedOn('c1'));
		$this->assertSame([], $service->typesFor('ct-1'));
	}//end testAnUnreadableRegisterAnswersNothing()
}//end class
