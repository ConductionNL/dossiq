<?php

/**
 * The probes and saves that write an integration card.
 *
 * A connection test the admin runs and a settings form the admin saves are the
 * only two things that know whether a connection works. If they do not write
 * their outcome, the Integrations page shows the seed forever and is worse
 * than no page at all — it looks like an answer. Every test here asserts that
 * the write happens with the outcome the caller actually had, not that the
 * caller returned the right JSON.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\EmailTemplateController;
use OCA\Dossiq\Controller\SettingsController;
use OCA\Dossiq\Controller\StufController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\EmailTemplateService;
use OCA\Dossiq\Service\IntegrationStatusService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Stuf\CircuitBreakerService;
use OCA\Dossiq\Service\Stuf\StufEnvelopeInspector;
use OCA\Dossiq\Service\Stuf\StufMessageHandler;
use OCA\Dossiq\Service\Stuf\StufMessageParser;
use OCA\Dossiq\Service\Stuf\StufAdapterService;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\Stuf\StufServices;
use OCA\Dossiq\Service\Stuf\StufSoapRequestDispatcher;
use OCA\Dossiq\Service\Stuf\StufVaultService;
use OCA\Dossiq\Service\StufFieldMappingService;
use OCA\Dossiq\Service\StufMessageBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use OCP\App\IAppManager;

/**
 * The probe and save side of pluggable-integration-registry.
 *
 * @covers \OCA\Dossiq\Controller\EmailTemplateController
 * @covers \OCA\Dossiq\Controller\SettingsController
 * @covers \OCA\Dossiq\Controller\StufController
 */
class IntegrationProbesRecordTest extends TestCase {

	/**
	 * The recorder every controller here is handed.
	 *
	 * @var IntegrationStatusService
	 */
	private IntegrationStatusService $recorder;

	/**
	 * The template service, which is where the mailbox card is written from.
	 *
	 * @var EmailTemplateService
	 */
	private EmailTemplateService $templateService;

	/**
	 * Set up the shared recorder mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->recorder = $this->createMock(IntegrationStatusService::class);
		$this->templateService = $this->createMock(EmailTemplateService::class);
	}//end setUp()

	/**
	 * An unconfigured mailbox records Not configured, not Error.
	 *
	 * "No host saved" is not a broken connection, and calling it one would put
	 * a red card on the page of a fresh install.
	 *
	 * @return void
	 */
	public function testUnconfiguredMailboxRecordsUnconfigured(): void {
		$this->templateService->expects($this->once())
			->method('recordMailboxStatus')
			->with('unconfigured', $this->anything());

		$controller = $this->emailController(host: '');
		$response = $controller->testImap();

		$this->assertFalse($response->getData()['ok']);
	}//end testUnconfiguredMailboxRecordsUnconfigured()

	/**
	 * A mailbox host that does not answer records Error with the failure text.
	 *
	 * @return void
	 */
	public function testFailedMailboxTestRecordsError(): void {
		$this->templateService->expects($this->once())
			->method('recordMailboxStatus')
			->with('error', $this->isType('string'));

		// Port 0 on an unroutable host: fsockopen fails without a network.
		$controller = $this->emailController(host: '192.0.2.1', port: '9');
		$response = $controller->testImap();

		$this->assertFalse($response->getData()['ok']);
	}//end testFailedMailboxTestRecordsError()

	/**
	 * The signed-out caller is rejected before anything is recorded.
	 *
	 * @return void
	 */
	public function testSignedOutCallerRecordsNothing(): void {
		$this->templateService->expects($this->never())->method('recordMailboxStatus');

		$controller = $this->emailController(host: 'mail.example.org', signedIn: false);
		$controller->testImap();
	}//end testSignedOutCallerRecordsNothing()

	/**
	 * An endpoint list with an open circuit records Error and names it.
	 *
	 * @return void
	 */
	public function testAnOpenCircuitRecordsErrorNamingTheEndpoint(): void {
		$this->recorder->expects($this->once())
			->method('record')
			->with(
				'stuf',
				'error',
				$this->stringContains('Gemeente Zuid')
			);

		$controller = $this->stufController(
			endpoints: [['id' => 'e1', 'name' => 'Gemeente Zuid']],
			breakerState: 'open'
		);
		$controller->endpoints();
	}//end testAnOpenCircuitRecordsErrorNamingTheEndpoint()

	/**
	 * A healthy endpoint list records Configured.
	 *
	 * @return void
	 */
	public function testHealthyEndpointsRecordConfigured(): void {
		$this->recorder->expects($this->once())
			->method('record')
			->with('stuf', 'configured', $this->stringContains('Gemeente Zuid'));

		$controller = $this->stufController(
			endpoints: [['id' => 'e1', 'name' => 'Gemeente Zuid']],
			breakerState: 'closed'
		);
		$controller->endpoints();
	}//end testHealthyEndpointsRecordConfigured()

	/**
	 * No endpoints at all is Not configured, not healthy.
	 *
	 * An empty list would otherwise read as "every endpoint is fine", which is
	 * exactly the false green the page exists to remove.
	 *
	 * @return void
	 */
	public function testNoEndpointsRecordsUnconfigured(): void {
		$this->recorder->expects($this->once())
			->method('record')
			->with('stuf', 'unconfigured', $this->anything());

		$controller = $this->stufController(endpoints: [], breakerState: 'closed');
		$controller->endpoints();
	}//end testNoEndpointsRecordsUnconfigured()

	/**
	 * A settings save hands its payload to the recorder.
	 *
	 * @return void
	 */
	public function testASettingsSaveReachesTheIntegrationCards(): void {
		$payload = ['identification_method' => 'both'];

		$this->recorder->expects($this->once())
			->method('recordFromSave')
			->with($payload)
			->willReturn(['kcc' => 'configured']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($this->recorder);

		$this->assertSame(
			['success' => true, 'config' => []],
			$this->settingsController($container, $payload)->update()->getData()
		);
	}//end testASettingsSaveReachesTheIntegrationCards()

	/**
	 * The name the controller resolves is the class that exists.
	 *
	 * The recorder is looked up by STRING because the controller sits at
	 * PHPMD's coupling ceiling, and a string FQCN is exactly the kind of
	 * reference that rots into a silent no-op after a rename: the container
	 * would simply not have it, `has()` would answer false, and every save
	 * would quietly stop updating the page.
	 *
	 * @return void
	 */
	public function testTheRecorderNameResolvesToARealClass(): void {
		$reflection = new \ReflectionClass(SettingsController::class);
		$name = $reflection->getConstant('INTEGRATION_STATUS_SERVICE');

		$this->assertIsString($name);
		$this->assertSame(IntegrationStatusService::class, $name);
		$this->assertTrue(class_exists($name));
	}//end testTheRecorderNameResolvesToARealClass()

	/**
	 * A save still succeeds when the recorder cannot be built.
	 *
	 * @return void
	 */
	public function testASaveSucceedsWithoutTheRecorder(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);
		$container->expects($this->never())->method('get');

		$response = $this->settingsController($container, ['identification_method' => 'both'])->update();

		$this->assertTrue($response->getData()['success']);
	}//end testASaveSucceedsWithoutTheRecorder()

	/**
	 * Build a SettingsController over a given container and request payload.
	 *
	 * @param ContainerInterface $container The container the recorder is read from.
	 * @param array<string, mixed> $payload The save payload.
	 *
	 * @return SettingsController
	 */
	private function settingsController(ContainerInterface $container, array $payload): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($payload);

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('updateSettings')->willReturn([]);

		return new SettingsController(
			request: $request,
			container: $container,
			appManager: $this->createMock(IAppManager::class),
			settingsService: $settingsService,
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
			l10n: $this->createMock(IL10N::class)
		);
	}//end settingsController()

	/**
	 * Build an EmailTemplateController whose IMAP config is what we say.
	 *
	 * @param string $host The saved IMAP host.
	 * @param string $port The saved IMAP port.
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return EmailTemplateController
	 */
	private function emailController(
		string $host,
		string $port = '993',
		bool $signedIn = true,
	): EmailTemplateController {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(
			$signedIn === true ? $this->createMock(IUser::class) : null
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'email_imap_host' => $host,
				'email_imap_port' => $port,
				default => $default,
			}
		);

		return new EmailTemplateController(
			request: $this->createMock(IRequest::class),
			templateService: $this->templateService,
			settingsService: $this->createMock(SettingsService::class),
			appConfig: $appConfig,
			userSession: $userSession,
			groupManager: $this->createMock(IGroupManager::class),
			caseAccessGuard: $this->createMock(CaseAccessGuard::class),
		);
	}//end emailController()

	/**
	 * Build a StufController over a stubbed register and circuit breaker.
	 *
	 * @param array<int, array<string, mixed>> $endpoints The endpoint rows.
	 * @param string $breakerState The circuit-breaker state for each endpoint.
	 *
	 * @return StufController
	 */
	private function stufController(array $endpoints, string $breakerState): StufController {
		$register = $this->createMock(StufRegisterAccess::class);
		$register->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters = [], int $limit = 0) use ($endpoints): array {
				return $schema === StufRegisterAccess::SCHEMA_ENDPOINT ? $endpoints : [];
			}
		);

		$breaker = $this->createMock(CircuitBreakerService::class);
		$breaker->method('snapshot')->willReturn(
			['state' => $breakerState, 'failureCount' => 0, 'openedAt' => null]
		);

		$services = new StufServices(
			mappingService: $this->createMock(StufFieldMappingService::class),
			messageBuilder: $this->createMock(StufMessageBuilder::class),
			adapter: $this->createMock(StufAdapterService::class),
			register: $register,
			messageHandler: $this->createMock(StufMessageHandler::class),
			parser: $this->createMock(StufMessageParser::class),
			vault: $this->createMock(StufVaultService::class),
			circuitBreaker: $breaker,
		);

		return new StufController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			stuf: $services,
			dispatcher: $this->createMock(StufSoapRequestDispatcher::class),
			inspector: $this->createMock(StufEnvelopeInspector::class),
			l10n: $this->createMock(IL10N::class),
			logger: $this->createMock(LoggerInterface::class),
			integrationStatus: $this->recorder,
		);
	}//end stufController()
}//end class
