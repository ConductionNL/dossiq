<?php

/**
 * Unit tests for CaseFlowActions::planned() and stopSeries(): which planned
 * follow-ups are listed for a case, in what order and with what fields, and
 * the delegation of stopping a series.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
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

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The planned follow-ups of a case.
 */
class CaseFlowActionsPlannedTest extends TestCase {

	/**
	 * Mock document reader.
	 *
	 * @var PlannedFollowUpDocument&MockObject
	 */
	private PlannedFollowUpDocument&MockObject $document;

	/**
	 * Mock series ledger.
	 *
	 * @var PlannedSeriesLedger&MockObject
	 */
	private PlannedSeriesLedger&MockObject $ledger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->document = $this->createMock(PlannedFollowUpDocument::class);
		$this->ledger = $this->createMock(PlannedSeriesLedger::class);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return CaseFlowActions
	 */
	private function actions(): CaseFlowActions {
		return new CaseFlowActions(
			container: $this->createMock(ContainerInterface::class),
			settingsService: $this->createMock(SettingsService::class),
			document: $this->document,
			ledger: $this->ledger,
			logger: new NullLogger(),
		);

	}//end actions()

	/**
	 * A planned flow double.
	 *
	 * @param string $uuid The flow uuid
	 * @param bool $enabled Whether it is enabled
	 * @param string|null $lastRunAt When it last ran
	 *
	 * @return object
	 */
	private static function flow(string $uuid, bool $enabled = true, ?string $lastRunAt = null): object {
		return new class($uuid, $enabled, $lastRunAt) {
			/**
			 * Constructor.
			 *
			 * @param string $uuid The uuid
			 * @param bool $enabled Enabled
			 * @param string|null $lastRunAt Last run
			 */
			public function __construct(private string $uuid, private bool $enabled, private ?string $lastRunAt) {
			}

			/**
			 * Uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

			/**
			 * Enabled.
			 *
			 * @return bool
			 */
			public function getEnabled(): bool {
				return $this->enabled;
			}

			/**
			 * Nodes: the uuid, so the marker double can tell flows apart.
			 *
			 * @return array<int, string>
			 */
			public function getNodes(): array {
				return [$this->uuid];
			}

			/**
			 * Last run.
			 *
			 * @return string|null
			 */
			public function getLastRunAt(): ?string {
				return $this->lastRunAt;
			}
		};

	}//end flow()

	/**
	 * A marker for a case.
	 *
	 * @param string $case The case
	 * @param string $date The date
	 * @param string $recurrence The recurrence
	 *
	 * @return array<string, mixed>
	 */
	private static function marker(string $case, string $date, string $recurrence = PlannedFollowUpDocument::RECURRENCE_NONE): array {
		return [
			'case' => $case,
			'title' => 'Follow-up ' . $date,
			'date' => $date,
			'caseType' => 'ct-1',
			'recurrence' => $recurrence,
			'until' => '',
			'count' => 0,
		];

	}//end marker()

	/**
	 * Only enabled flows for this case are listed, sorted by date; a fired single follow-up is gone.
	 *
	 * @param array<string, array<string, mixed>|null> $markers Marker per flow uuid
	 * @param list<object> $flows The planned flows
	 *
	 * @return array<string, mixed>
	 */
	private function plannedFor(array $markers, array $flows): array {
		$this->ledger->method('plannedFlows')->willReturn($flows);
		$this->document->method('markerOf')->willReturnCallback(
			function (array $nodes, ?DateTimeImmutable $from = null) use ($markers): ?array {
				$this->assertEquals(new DateTimeImmutable('today'), $from);
				return ($markers[$nodes[0]] ?? null);
			}
		);

		return $this->actions()->planned('case-1');

	}//end plannedFor()

	/**
	 * Listed flows carry their marker fields and occurrences, sorted by date.
	 *
	 * @return void
	 */
	public function testPlannedListsThisCasesFlowsByDate(): void {
		$this->ledger->method('occurrencesOf')->willReturnCallback(
			static fn (string $flowId, bool $single): array => ($single === true ? [] : [['id' => 'case-9', 'title' => 'Opened by ' . $flowId]])
		);

		$result = $this->plannedFor(
			[
				'f-late' => self::marker('case-1', '2027-03-01'),
				'f-series' => self::marker('case-1', '2026-12-01', 'monthly'),
				'f-other' => self::marker('case-2', '2026-01-01'),
				'f-off' => self::marker('case-1', '2026-01-01'),
				'f-fired' => self::marker('case-1', '2026-02-01'),
				'f-none' => null,
			],
			[
				self::flow('f-late'),
				self::flow('f-series', true, '2026-11-01T00:00:00Z'),
				self::flow('f-other'),
				self::flow('f-off', false),
				self::flow('f-fired', true, '2026-02-01T08:00:00Z'),
				self::flow('f-none'),
			]
		);

		$this->assertSame(2, $result['total']);
		$this->assertSame(['f-series', 'f-late'], array_column($result['results'], 'id'));
		$this->assertSame(
			[
				'id' => 'f-series',
				'title' => 'Follow-up 2026-12-01',
				'date' => '2026-12-01',
				'caseType' => 'ct-1',
				'recurrence' => 'monthly',
				'until' => '',
				'count' => 0,
				'occurrences' => [['id' => 'case-9', 'title' => 'Opened by f-series']],
			],
			$result['results'][0]
		);
		$this->assertSame([], $result['results'][1]['occurrences']);

	}//end testPlannedListsThisCasesFlowsByDate()

	/**
	 * Without planned flows the list is empty.
	 *
	 * @return void
	 */
	public function testNoPlannedFlowsIsEmpty(): void {
		$this->assertSame(['results' => [], 'total' => 0], $this->plannedFor([], []));

	}//end testNoPlannedFlowsIsEmpty()

	/**
	 * Stopping a series is handed to the ledger and its answer returned.
	 *
	 * @return void
	 */
	public function testStopSeriesDelegatesToTheLedger(): void {
		$this->ledger->expects($this->once())
			->method('stopSeries')
			->with('case-1', 'f-series', 'alice')
			->willReturn(['id' => 'f-series', 'stopped' => true]);

		$this->assertSame(['id' => 'f-series', 'stopped' => true], $this->actions()->stopSeries('case-1', 'f-series', 'alice'));

	}//end testStopSeriesDelegatesToTheLedger()
}//end class
