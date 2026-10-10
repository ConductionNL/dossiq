<?php

/**
 * BezwaarTermijnJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused every write of the archival run as
 * Anonymous: the beschikking stayed unarchived, no transition was logged, and
 * the trigger stayed active. These tests drive the REAL job and the REAL
 * BeschikkingService::archive() path (BeschikkingRepository,
 * StateMachineService, OpenRegisterArchivalAdapter) into a register that
 * refuses a write from nobody and records who wrote.
 *
 * Mocked, because archive() never calls them: the nine other collaborators
 * of BeschikkingService (Berichtenbox routing, template, signing, mandate,
 * audit packet, bezwaar scheduler, coordinator, timeline, remedy).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\BackgroundJob\BezwaarTermijnJob;
use OCA\Dossiq\Service\Beschikking\AuditPacketBuilder;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\Beschikking\BeschikkingDelivery;
use OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Dossiq\Service\Beschikking\SigningAdapterInterface;
use OCA\Dossiq\Service\Beschikking\TemplateEngineAdapterInterface;
use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\People\CoordinatorRequirement;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StateMachineService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The archival run is stored as the configured account, or nothing happens.
 *
 * @covers \OCA\Dossiq\BackgroundJob\BezwaarTermijnJob
 * @uses \OCA\Dossiq\Service\BeschikkingService
 * @uses \OCA\Dossiq\Service\Beschikking\BeschikkingRepository
 * @uses \OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter
 * @uses \OCA\Dossiq\Service\StateMachineService
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class BezwaarTermijnJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Seed one sent beschikking whose bezwaartermijn lapsed yesterday.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: 'beschikking',
			id: 'besch-1',
			row: ['currentStatus' => 'sent', 'reference' => 'B-2026-001']
		);
		$this->register->seed(
			schema: 'bezwaarTrigger',
			id: 'trig-1',
			row: [
				'decisionId' => 'besch-1',
				'objectionReceived' => false,
				'archiveTriggerActive' => true,
				'archiveDate' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
			]
		);
	}//end setUp()

	/**
	 * One run archives, logs the transition and closes the trigger as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(
			register: $this->register,
			schemas: ['beschikking', 'stateMachineLog', 'bezwaarTrigger']
		);
		$this->assertSame('archived', $this->register->row(schema: 'beschikking', id: 'besch-1')['currentStatus']);
		$this->assertFalse($this->register->row(schema: 'bezwaarTrigger', id: 'trig-1')['archiveTriggerActive']);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame('sent', $this->register->row(schema: 'beschikking', id: 'besch-1')['currentStatus']);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the real archive path.
	 *
	 * @return BezwaarTermijnJob The job.
	 */
	private function job(): BezwaarTermijnJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'bezwaar_trigger_schema' => 'bezwaarTrigger',
				'beschikking_schema' => 'beschikking',
				'state_machine_log_schema' => 'stateMachineLog',
				default => $default,
			}
		);

		$decisions = new BeschikkingService(
			stateMachine: new StateMachineService(settingsService: $settings, logger: new NullLogger()),
			delivery: $this->createMock(BeschikkingDelivery::class),
			templateAdapter: $this->createMock(TemplateEngineAdapterInterface::class),
			signingAdapter: $this->createMock(SigningAdapterInterface::class),
			archivalAdapter: new OpenRegisterArchivalAdapter(
				container: $this->createMock(ContainerInterface::class),
				logger: new NullLogger()
			),
			repository: new BeschikkingRepository(settingsService: $settings, logger: new NullLogger()),
			mandateVerifier: $this->createMock(MandaatVerifier::class),
			auditPacket: $this->createMock(AuditPacketBuilder::class),
			bezwaarScheduler: $this->createMock(BezwaarTermijnScheduler::class),
			coordinator: $this->createMock(CoordinatorRequirement::class),
			timeline: $this->createMock(CaseTimeline::class),
			remedy: $this->createMock(CaseRemedy::class),
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		return $this->buildWith(
			BezwaarTermijnJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'decisionService' => $decisions,
				'settingsService' => $settings,
				'appManager' => $apps,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
