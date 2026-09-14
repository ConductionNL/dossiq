<?php

/**
 * Planned follow-up sweep unit tests.
 *
 * The sweep is the only thing standing between "plan a follow-up" and a flow
 * that opens a case every year forever, and between a series of three and a
 * series with no end. Both failures are silent — a flow that should have been
 * switched off looks exactly like one that should still be armed — so the three
 * cases are asserted over a flow stub rather than trusted.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTime;
use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One planned flow, stubbed, swept.
 *
 * @covers \OCA\Dossiq\Service\Flow\CaseFlowActions
 * @covers \OCA\Dossiq\Service\Flow\PlannedSeriesLedger
 */
class PlannedFollowUpSweepTest extends TestCase {

	/**
	 * The stubbed flow every test in this class sweeps.
	 *
	 * @var object
	 */
	private object $flow;

	/**
	 * What the stubbed flow service was asked to save, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The in-memory app config the fire counter lives in.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Reset the recorder between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saves = [];
		$this->config = [];
	}//end setUp()

	/**
	 * A single follow-up is switched off the first time the sweep sees it fired.
	 *
	 * @return void
	 */
	public function testASingleFollowUpIsRetiredAfterItFires(): void {
		$service = $this->serviceFor(
			document: $this->document(recurrence: 'none'),
			lastRunAt: new DateTime('2026-10-15 06:00:00')
		);

		$this->assertSame(1, $service->retireSpent(today: new DateTimeImmutable('2026-10-15')));
		$this->assertSame(false, $this->saves[0]['data']['enabled']);
		$this->assertSame('follow-up created', $this->saves[0]['data']['notes']);
	}//end testASingleFollowUpIsRetiredAfterItFires()

	/**
	 * A series that has fired once of three survives the sweep.
	 *
	 * @return void
	 */
	public function testASeriesSurvivesUntilItsCountIsReached(): void {
		$service = $this->serviceFor(
			document: $this->document(recurrence: 'yearly', count: 3),
			lastRunAt: new DateTime('2026-10-15 06:00:00')
		);

		$this->assertSame(0, $service->retireSpent(today: new DateTimeImmutable('2026-10-15')));
		$this->assertSame([], $this->saves);

		// The hourly sweep runs again on the same firing and must not count it
		// twice: a yearly series would otherwise be spent inside three hours.
		$this->assertSame(0, $service->retireSpent(today: new DateTimeImmutable('2026-10-15')));
		$this->assertSame('1', $this->config['planned_series_fired_flow-1']);
	}//end testASeriesSurvivesUntilItsCountIsReached()

	/**
	 * The third occurrence is the last, and the flow says why it stopped.
	 *
	 * @return void
	 */
	public function testTheThirdOccurrenceEndsACountOfThree(): void {
		$service = $this->serviceFor(
			document: $this->document(recurrence: 'yearly', count: 3),
			lastRunAt: new DateTime('2026-10-15 06:00:00')
		);

		$this->assertSame(0, $service->retireSpent(today: new DateTimeImmutable('2026-10-15')));

		$this->flow->lastRunAt = new DateTime('2027-10-15 06:00:00');
		$this->assertSame(0, $service->retireSpent(today: new DateTimeImmutable('2027-10-15')));

		$this->flow->lastRunAt = new DateTime('2028-10-15 06:00:00');
		$this->assertSame(1, $service->retireSpent(today: new DateTimeImmutable('2028-10-15')));

		$this->assertCount(1, $this->saves);
		$this->assertSame(false, $this->saves[0]['data']['enabled']);
		$this->assertSame('series complete', $this->saves[0]['data']['notes']);
	}//end testTheThirdOccurrenceEndsACountOfThree()

	/**
	 * A series with no end is never swept away.
	 *
	 * @return void
	 */
	public function testAnOpenEndedSeriesIsNeverRetired(): void {
		$service = $this->serviceFor(
			document: $this->document(recurrence: 'monthly'),
			lastRunAt: new DateTime('2026-10-15 06:00:00')
		);

		for ($fire = 1; $fire <= 12; $fire++) {
			$this->flow->lastRunAt = new DateTime(sprintf('2026-10-%02d 06:00:00', $fire));
			$this->assertSame(0, $service->retireSpent(today: new DateTimeImmutable('2026-10-15')));
		}

		$this->assertSame([], $this->saves);
	}//end testAnOpenEndedSeriesIsNeverRetired()

	/**
	 * A flow that has never fired is left alone.
	 *
	 * @return void
	 */
	public function testAFlowThatHasNotFiredIsLeftAlone(): void {
		$service = $this->serviceFor(
			document: $this->document(recurrence: 'none'),
			lastRunAt: null
		);

		$this->assertSame(0, $service->retireSpent(today: new DateTimeImmutable('2026-10-15')));
		$this->assertSame([], $this->saves);
	}//end testAFlowThatHasNotFiredIsLeftAlone()

	/**
	 * A planned follow-up document, with or without a recurrence.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param integer $count The occurrence count, or 0.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function document(string $recurrence, int $count = 0): array {
		return (new PlannedFollowUpDocument())->build(
			caseId: 'case-1',
			caseTypeId: 'type-controle',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Controle Kerkstraat 12',
			uid: 'behandelaar',
			recurrence: $recurrence,
			count: $count
		);
	}//end document()

	/**
	 * The service under test, over one stubbed flow.
	 *
	 * @param array<string, mixed> $document The flow document the stub carries.
	 * @param DateTime|null $lastRunAt When the stub last fired.
	 *
	 * @return CaseFlowActions The service.
	 */
	private function serviceFor(array $document, ?DateTime $lastRunAt): CaseFlowActions {
		$this->flow = new class($document, $lastRunAt) {

			/**
			 * When the flow last fired.
			 *
			 * @var DateTime|null
			 */
			public ?DateTime $lastRunAt;

			/**
			 * The flow's graph.
			 *
			 * @var array<int, mixed>
			 */
			private array $nodes;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $document The document.
			 * @param DateTime|null $lastRunAt The last firing.
			 */
			public function __construct(array $document, ?DateTime $lastRunAt) {
				$this->nodes = (array)$document['nodes'];
				$this->lastRunAt = $lastRunAt;
			}

			/**
			 * The flow uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'flow-1';
			}

			/**
			 * The flow's graph.
			 *
			 * @return array<int, mixed> The nodes.
			 */
			public function getNodes(): array {
				return $this->nodes;
			}

			/**
			 * Whether the flow is armed.
			 *
			 * @return boolean Always true: a switched-off flow is skipped before this.
			 */
			public function getEnabled(): bool {
				return true;
			}

			/**
			 * When the flow last fired.
			 *
			 * @return DateTime|null The stamp.
			 */
			public function getLastRunAt(): ?DateTime {
				return $this->lastRunAt;
			}
		};

		$flowService = new class($this->saves) {

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $saves The recorder, by reference.
			 */
			public function __construct(private array &$saves) {
			}

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $data The fields.
			 * @param string|null $uuid The flow.
			 *
			 * @return object The flow, unused by the sweep.
			 */
			public function save(array $data, ?string $uuid = null): object {
				$this->saves[] = ['data' => $data, 'uuid' => $uuid];

				return new \stdClass();
			}
		};

		$flow = $this->flow;
		$flowMapper = new class($flow) {

			/**
			 * Constructor.
			 *
			 * @param object $flow The single stubbed flow.
			 */
			public function __construct(private object $flow) {
			}

			/**
			 * The planned flows this app owns.
			 *
			 * @param string $app The app id.
			 * @param string $applicationSlug The planned-follow-up marker.
			 * @param integer $limit The page size.
			 *
			 * @return array<int, object> The flows.
			 */
			public function findAllFlows(string $app, string $applicationSlug, int $limit): array {
				return [$this->flow];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($flowService, $flowMapper): object {
				if (str_contains($name, 'FlowMapper') === true) {
					return $flowMapper;
				}

				return $flowService;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;

				return true;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$document = new PlannedFollowUpDocument();
		$logger = $this->createMock(LoggerInterface::class);

		return new CaseFlowActions(
			$container,
			$settings,
			$document,
			new PlannedSeriesLedger($container, $settings, $document, $appConfig, $logger),
			$logger,
		);
	}//end serviceFor()
}//end class
