<?php

/**
 * StufRetryJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the stufMessage update as
 * Anonymous AFTER the envelope had already gone out: the zaaksysteem got the
 * message, the audit row never learned it, and the retry chain lost count.
 * These tests drive the REAL job, StufAdapterService, StufOutboundTransport,
 * StufMessageHandler, StufRegisterAccess, CircuitBreakerService and
 * StufMessageParser into a register that refuses a write from nobody. Only
 * the HTTP client, the job list, the needs-input dispatcher, the app config
 * and the two collaborators retrySend() never touches are doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\StufRetryJob;
use OCA\Dossiq\Service\Stuf\CircuitBreakerService;
use OCA\Dossiq\Service\Stuf\NeedsInputDispatcher;
use OCA\Dossiq\Service\Stuf\StufAdapterService;
use OCA\Dossiq\Service\Stuf\StufCaseMappingStore;
use OCA\Dossiq\Service\Stuf\StufHttpClient;
use OCA\Dossiq\Service\Stuf\StufMessageHandler;
use OCA\Dossiq\Service\Stuf\StufMessageParser;
use OCA\Dossiq\Service\Stuf\StufOutboundTransport;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\StufMessageBuilder;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The stufMessage update runs as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\StufRetryJob
 * @uses \OCA\Dossiq\Service\Stuf\StufAdapterService
 * @uses \OCA\Dossiq\Service\Stuf\StufOutboundTransport
 * @uses \OCA\Dossiq\Service\Stuf\StufMessageHandler
 * @uses \OCA\Dossiq\Service\Stuf\StufRegisterAccess
 * @uses \OCA\Dossiq\Service\Stuf\CircuitBreakerService
 * @uses \OCA\Dossiq\Service\Stuf\StufMessageParser
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class StufRetryJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;
	use MakesCaseDateNormaliser;

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * How many envelopes went out over HTTP.
	 *
	 * @var int
	 */
	private int $sends = 0;

	/**
	 * How many retries were queued.
	 *
	 * @var int
	 */
	private int $queued = 0;

	/**
	 * Seed one message waiting for a retry, and its endpoint.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sends = 0;
		$this->queued = 0;
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: StufRegisterAccess::SCHEMA_ENDPOINT,
			id: 'ep-1',
			row: ['name' => 'Zaaksysteem', 'url' => 'https://zs.example.org/stuf']
		);
		$this->register->seed(
			schema: StufRegisterAccess::SCHEMA_MESSAGE,
			id: 'msg-1',
			row: [
				'endpointId' => 'ep-1',
				'role' => 'creeerZaak',
				'envelopeXml' => '<soapenv:Envelope/>',
				'referenceNumber' => 'REF-1',
				'status' => 'wacht_op_retry',
				'retries' => [],
			]
		);
	}//end setUp()

	/**
	 * The two answers a retry can get, and the status each leaves.
	 *
	 * @return array<string, array{0: int, 1: string, 2: int}>
	 */
	public static function answers(): array {
		return [
			'confirmed' => [200, 'bevestigd', 0],
			'transient failure' => [503, 'wacht_op_retry', 1],
		];
	}//end answers()

	/**
	 * One run sends once and records the outcome as the service account.
	 *
	 * @param int    $httpStatus The zaaksysteem's answer.
	 * @param string $status     The status the message must end in.
	 * @param int    $queued     How many retries must be queued.
	 *
	 * @return void
	 *
	 * @dataProvider answers
	 */
	public function testTheRunWritesAsTheServiceAccount(int $httpStatus, string $status, int $queued): void {
		$this->runJobOnce(job: $this->job(httpStatus: $httpStatus), argument: ['stufMessageId' => 'msg-1', 'runAt' => 0]);

		$this->assertSame(1, $this->sends);
		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: [StufRegisterAccess::SCHEMA_MESSAGE]);
		$row = $this->register->row(schema: StufRegisterAccess::SCHEMA_MESSAGE, id: 'msg-1');
		$this->assertSame($status, $row['status']);
		$this->assertSame($httpStatus, $row['httpStatus']);
		$this->assertSame('REF-1', $row['referenceNumber'], 'The update lost a field of the message.');
		$this->assertSame($queued, $this->queued);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is sent, nothing is attempted and the admins are told.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job(httpStatus: 200), argument: ['stufMessageId' => 'msg-1', 'runAt' => 0]);

		$this->assertSame(0, $this->sends, 'An envelope went out that could not be recorded.');
		$this->assertSame(0, $this->queued);
		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame('wacht_op_retry', $this->register->row(schema: StufRegisterAccess::SCHEMA_MESSAGE, id: 'msg-1')['status']);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the real StUF chain, with HTTP doubled.
	 *
	 * @param int $httpStatus The status the zaaksysteem answers.
	 *
	 * @return StufRetryJob The job.
	 */
	private function job(int $httpStatus): StufRetryJob {
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturn($this->register);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('5');
		$config->method('getValueInt')->willReturn(0);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$access = new StufRegisterAccess(container: $container, appConfig: $config, logger: new NullLogger(), appManager: $apps);
		$handler = new StufMessageHandler(register: $access, dates: $this->caseDates());
		$parser = new StufMessageParser(logger: new NullLogger());
		$needsInput = $this->createMock(NeedsInputDispatcher::class);
		$breaker = new CircuitBreakerService(appConfig: $config, needsInputDispatcher: $needsInput, logger: new NullLogger());

		$http = $this->createMock(StufHttpClient::class);
		$http->method('send')->willReturnCallback(
			function () use ($httpStatus): array {
				$this->sends++;
				return ['httpStatus' => $httpStatus, 'durationMs' => 12, 'responseXml' => '', 'fout' => null];
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function (): void {
				$this->queued++;
			}
		);

		$transport = new StufOutboundTransport(
			httpClient: $http,
			messageHandler: $handler,
			parser: $parser,
			circuitBreaker: $breaker,
			needsInput: $needsInput,
			jobList: $jobs,
			logger: new NullLogger(),
		);

		$adapter = new StufAdapterService(
			builder: $this->createMock(StufMessageBuilder::class),
			transport: $transport,
			messageHandler: $handler,
			parser: $parser,
			circuitBreaker: $breaker,
			register: $access,
			mappings: $this->createMock(StufCaseMappingStore::class),
			needsInput: $needsInput,
			logger: new NullLogger(),
		);

		return $this->buildWith(
			StufRetryJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'adapter' => $adapter,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
