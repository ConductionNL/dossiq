<?php

/**
 * PlannedFollowUpSweepJob writes as the background service account.
 *
 * The sweep does not write through ObjectService but through OpenRegister's
 * FlowService::save(). That save resolves the flow through find(), which
 * refuses a caller without an active organisation, so from cron, with no
 * user, a spent follow-up was never switched off and fired again a year
 * later. This drives the REAL job, CaseFlowActions, PlannedSeriesLedger and
 * PlannedFollowUpDocument into a FlowService double that refuses a save from
 * nobody, the way the real find() does, and records who saved.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The sweep switches spent follow-ups off as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob
 * @uses \OCA\Dossiq\Service\Flow\CaseFlowActions
 * @uses \OCA\Dossiq\Service\Flow\PlannedSeriesLedger
 * @uses \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 * @uses \OCA\Dossiq\Service\Flow\PlannedSeriesCalendar
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class PlannedFollowUpSweepJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * Every save that landed: [uid, flow uuid, data].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $flowWrites = [];

	/**
	 * Every save refused, as OpenRegister words it.
	 *
	 * @var array<int, string>
	 */
	public array $flowRefusals = [];

	/**
	 * Every app config write the ledger made.
	 *
	 * @var array<int, string>
	 */
	private array $configWrites = [];

	/**
	 * Reset the shared state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flowWrites = [];
		$this->flowRefusals = [];
		$this->configWrites = [];
	}//end setUp()

	/**
	 * One run switches the fired single follow-up off as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->flowRefusals, 'OpenRegister refused a save: '.implode(' | ', $this->flowRefusals));
		$this->assertCount(1, $this->flowWrites, 'The spent follow-up was not switched off.');
		$this->assertSame(['dossiq-achtergrond'], array_values(array_unique(array_column($this->flowWrites, 0))));
		$this->assertSame('flow-1', $this->flowWrites[0][1]);
		$this->assertFalse($this->flowWrites[0][2]['enabled']);
		$this->assertSame('follow-up created', $this->flowWrites[0][2]['notes']);
		$this->assertNull($this->acting, 'The job left the service account signed in.');
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is saved, attempted or counted.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->flowWrites);
		$this->assertSame([], $this->flowRefusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame([], $this->configWrites, 'A firing was counted although nothing could be switched off.');
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * Save a flow, refusing a caller with no organisation as FlowService::find() does.
	 *
	 * @param array<string, mixed> $data The fields.
	 * @param string|null          $uuid The flow.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When nobody is signed in.
	 */
	public function saveFlow(array $data, ?string $uuid): void {
		$uid = $this->actingUid();
		if ($uid === null) {
			$this->flowRefusals[] = 'No such flow';
			throw new DoesNotExistException('No such flow');
		}

		$this->flowWrites[] = [$uid, (string)$uuid, $data];
	}//end saveFlow()

	/**
	 * The real job down to OpenRegister's flow seam.
	 *
	 * @return object The job.
	 */
	private function job(): object {
		$test = $this;

		// A single follow-up that fired once and is still enabled.
		$flow = new class {
			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'flow-1';
			}

			/**
			 * When it last fired.
			 *
			 * @return DateTime
			 */
			public function getLastRunAt(): DateTime {
				return new DateTime('2026-10-06 06:00:00');
			}

			/**
			 * Whether it is on.
			 *
			 * @return bool
			 */
			public function getEnabled(): bool {
				return true;
			}

			/**
			 * Its nodes: no series, so one firing spends it.
			 *
			 * @return array<int, mixed>
			 */
			public function getNodes(): array {
				return [];
			}
		};

		// Mirrors OCA\OpenRegister\Service\Flow\FlowService::save(array $data, ?string $uuid = null).
		$flowService = new class($test, $flow) {
			/**
			 * Constructor.
			 *
			 * @param PlannedFollowUpSweepJobServiceAccountTest $test The test holding the state.
			 * @param object                                    $flow The flow a save returns.
			 */
			public function __construct(private PlannedFollowUpSweepJobServiceAccountTest $test, private object $flow) {
			}

			/**
			 * Save.
			 *
			 * @param array<string, mixed> $data The fields.
			 * @param string|null          $uuid The flow.
			 *
			 * @return object The flow.
			 */
			public function save(array $data, ?string $uuid = null): object {
				$this->test->saveFlow(data: $data, uuid: $uuid);
				return $this->flow;
			}
		};

		// Mirrors OCA\OpenRegister\Db\FlowMapper::findAllFlows().
		$flowMapper = new class($flow) {
			/**
			 * Constructor.
			 *
			 * @param object $flow The one planned flow.
			 */
			public function __construct(private object $flow) {
			}

			/**
			 * The planned flows.
			 *
			 * @param string|null $app             The app.
			 * @param string|null $applicationSlug The slug.
			 * @param string|null $organisation    The organisation.
			 * @param bool|null   $enabled         Whether enabled.
			 * @param int         $limit           The page.
			 * @param int         $offset          The offset.
			 *
			 * @return array<int, object>
			 */
			public function findAllFlows(
				?string $app = null,
				?string $applicationSlug = null,
				?string $organisation = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			): array {
				return [$this->flow];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $name): object => match ($name) {
				'OCA\\OpenRegister\\Service\\Flow\\FlowService' => $flowService,
				'OCA\\OpenRegister\\Db\\FlowMapper' => $flowMapper,
				default => throw new RuntimeException('Not in this container: '.$name),
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key): bool {
				$this->configWrites[] = $key;
				return true;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$document = new PlannedFollowUpDocument();

		$actions = new CaseFlowActions(
			container: $container,
			settingsService: $settings,
			document: $document,
			ledger: new PlannedSeriesLedger(
				container: $container,
				settingsService: $settings,
				document: $document,
				appConfig: $appConfig,
				logger: new NullLogger(),
			),
			logger: new NullLogger(),
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		return $this->buildWith(
			PlannedFollowUpSweepJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'flowActions' => $actions,
				'appManager' => $apps,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
