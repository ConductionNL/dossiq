<?php

/**
 * PlannedSeriesLedger::stopSeries() tests.
 *
 * Pins who may stop a series: only a series found among THIS case's planned
 * flows is switched off, with the reason written to its notes; a uuid of
 * another case's series, an unknown uuid, an absent flow engine and a failed
 * save are each refused with their own code.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PlannedSeriesLedger::stopSeries().
 */
class PlannedSeriesStopTest extends TestCase {
	/**
	 * The saves the flow engine received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * A stored planned flow of a case.
	 *
	 * @param string $uuid   The flow uuid.
	 * @param string $caseId The case the series belongs to.
	 *
	 * @return object The flow.
	 */
	private static function flowOf(string $uuid, string $caseId): object {
		$nodes = (new PlannedFollowUpDocument())->build(
			caseId: $caseId,
			caseTypeId: 'type-controle',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Controle',
			uid: 'behandelaar',
			recurrence: 'yearly',
			count: 3
		)['nodes'];

		return new class ($uuid, (array)$nodes) {
			/**
			 * Constructor.
			 *
			 * @param string            $uuid  The uuid.
			 * @param array<int, mixed> $nodes The graph.
			 */
			public function __construct(private string $uuid, private array $nodes) {
			}//end __construct()

			/** @return string */
			public function getUuid(): string {
				return $this->uuid;
			}//end getUuid()

			/** @return array<int, mixed> */
			public function getNodes(): array {
				return $this->nodes;
			}//end getNodes()
		};
	}//end flowOf()

	/**
	 * The ledger over the given flows, with or without a flow engine that saves.
	 *
	 * @param bool $withEngine Whether the FlowService resolves.
	 * @param bool $saveFails  Whether its save throws.
	 *
	 * @return PlannedSeriesLedger
	 */
	private function ledger(bool $withEngine = true, bool $saveFails = false): PlannedSeriesLedger {
		$test = $this;
		$flows = [self::flowOf('flow-mine', 'case-1'), self::flowOf('flow-theirs', 'case-2')];
		$mapper = new class ($flows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, object> $flows The flows.
			 */
			public function __construct(private array $flows) {
			}//end __construct()

			/**
			 * @param string|null $app             The app.
			 * @param string|null $applicationSlug The marker.
			 * @param int         $limit           The page.
			 *
			 * @return array<int, object> The flows.
			 */
			public function findAllFlows(?string $app = null, ?string $applicationSlug = null, int $limit = 100): array {
				return $this->flows;
			}//end findAllFlows()
		};
		$engine = new class ($test, $saveFails) {
			/**
			 * Constructor.
			 *
			 * @param PlannedSeriesStopTest $test      Where the saves go.
			 * @param bool                  $saveFails Whether save() throws.
			 */
			public function __construct(private PlannedSeriesStopTest $test, private bool $saveFails) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $data The fields.
			 * @param string|null          $uuid The flow.
			 *
			 * @return object The flow.
			 */
			public function save(array $data, ?string $uuid = null): object {
				if ($this->saveFails === true) {
					throw new RuntimeException('engine down');
				}

				$this->test->saves[] = ['data' => $data, 'uuid' => $uuid];
				return new \stdClass();
			}//end save()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($mapper, $engine, $withEngine): object {
				if (str_contains($name, 'FlowMapper') === true) {
					return $mapper;
				}

				if ($withEngine === false) {
					throw new RuntimeException('no engine');
				}

				return $engine;
			}
		);

		return new PlannedSeriesLedger(
			$container,
			$this->createMock(SettingsService::class),
			new PlannedFollowUpDocument(),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end ledger()

	/**
	 * The case's own series is switched off, with who stopped it and when in its notes.
	 *
	 * @return void
	 */
	public function testTheCasesOwnSeriesIsStopped(): void {
		self::assertSame(['id' => 'flow-mine', 'stopped' => true], $this->ledger()->stopSeries('case-1', 'flow-mine', 'jan'));
		self::assertCount(1, $this->saves);
		self::assertSame('flow-mine', $this->saves[0]['uuid']);
		self::assertFalse($this->saves[0]['data']['enabled']);
		self::assertMatchesRegularExpression('/^Series stopped by jan on \d{4}-\d{2}-\d{2}\.$/', $this->saves[0]['data']['notes']);
	}//end testTheCasesOwnSeriesIsStopped()

	/**
	 * Another case's series, an unknown series, no engine and a failed save are refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testEveryOtherStopIsRefused(): void {
		$refusals = [];
		foreach (
			[
				fn () => $this->ledger()->stopSeries('case-1', 'flow-theirs', 'jan'),
				fn () => $this->ledger()->stopSeries('case-1', 'flow-unknown', 'jan'),
				fn () => $this->ledger(withEngine: false)->stopSeries('case-1', 'flow-mine', 'jan'),
				fn () => $this->ledger(saveFails: true)->stopSeries('case-1', 'flow-mine', 'jan'),
			] as $attempt
		) {
			try {
				$attempt();
				$refusals[] = 'allowed';
			} catch (RuntimeException $e) {
				$refusals[] = $e->getMessage();
			}
		}

		self::assertSame(['case_not_found', 'case_not_found', 'flows_unavailable', 'stop_failed'], $refusals);
		self::assertSame([], $this->saves);
	}//end testEveryOtherStopIsRefused()
}//end class
