<?php

/**
 * An adapter says whether it is real.
 *
 * WHAT THIS SPEC IS GUARDING, because none of it fails loudly on its own.
 * Two seams in dossiq ship a mock, and both used to say nothing about it.
 * `BerichtenboxService::getAdapter()` built `MockAdapter` inline behind the
 * comment "For MVP, always use mock adapter", and `BeschikkingAdapterRegistrar`
 * aliased the template engine onto its mock unconditionally. Neither failed.
 * Both SUCCEEDED: a send returned a message id, a render returned a document,
 * and nothing left the instance. A working-looking channel that delivers
 * nothing is worse than a broken one, because nobody goes looking.
 *
 * So the assertions here are about what the app SAYS, not about whether a
 * class resolves. Three things have to hold: an integrator can substitute a
 * real adapter without editing dossiq, a class that cannot serve the seam is
 * refused rather than silently swapped for a mock, and the Integrations page
 * carries a state that means "this works and it is not real".
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\ConfiguredAdapter;
use OCA\Dossiq\AppInfo\Registrar\SubstitutableAdapterRegistrar;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\TemplateEngineAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter;
use OCA\Dossiq\Service\IntegrationStatusService;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A real Berichtenbox adapter, for the substitution test.
 *
 * Its whole job is to be a class that is not the mock and does implement the
 * seam, so the resolver has something true to pick.
 */
class RealBerichtenboxAdapter implements BerichtenboxAdapterInterface {

	/**
	 * Pretend to send.
	 *
	 * @param string $bsn Citizen BSN.
	 * @param string $subject Message subject.
	 * @param string $body Plain text body.
	 * @param string $typeCode Bericht type code.
	 * @param string|null $attachment Optional attachment.
	 *
	 * @return array<string, string> The send result.
	 */
	public function sendMessage(
		string $bsn,
		string $subject,
		string $body,
		string $typeCode,
		?string $attachment = null,
	): array {
		return ['messageId' => 'real-1', 'status' => 'sent'];
	}//end sendMessage()

	/**
	 * Pretend to poll.
	 *
	 * @param string $messageId The external message id.
	 *
	 * @return array<string, mixed> The read status.
	 */
	public function getReadStatus(string $messageId): array {
		return ['read' => false, 'readAt' => null];
	}//end getReadStatus()
}//end class

/**
 * A class that answers to the config key but cannot serve the seam.
 */
class NotAnAdapter {
}//end class

/**
 * The seam resolves what the admin named, and says so when it cannot.
 *
 * @covers \OCA\Dossiq\AppInfo\Registrar\ConfiguredAdapter
 *
 * @covers \OCA\Dossiq\AppInfo\Registrar\SubstitutableAdapterRegistrar
 *
 * @uses \OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter
 * @uses \OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class AdapterHonestyTest extends TestCase {

	/**
	 * What the log was told.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * Build a container that answers with the given adapter class name.
	 *
	 * @param string $named The value of the app-config key.
	 *
	 * @return ContainerInterface The wired container.
	 */
	private function container(string $named, bool $filinq = false): ContainerInterface {
		$this->logged = [];

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => ($filinq === true && $id === 'filinq')
		);
		$appManager->method('isEnabledForUser')->willReturn($filinq);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($named);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = ['warning', $message];
			}
		);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = ['error', $message];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($appConfig, $appManager, $l10n, $logger): object {
				return match ($id) {
					IAppConfig::class => $appConfig,
					IAppManager::class => $appManager,
					IL10N::class => $l10n,
					LoggerInterface::class => $logger,
					MockAdapter::class => new MockAdapter(logger: $logger),
					MockTemplateEngineAdapter::class => new MockTemplateEngineAdapter(),
					default => new $id(),
				};
			}
		);

		return $container;
	}//end container()

	/**
	 * Resolve the Berichtenbox seam through the container under test.
	 *
	 * @param string $named The configured adapter class name.
	 *
	 * @return object The adapter the seam bound.
	 */
	private function resolveBerichtenbox(string $named): object {
		return ConfiguredAdapter::resolve(
			container: $this->container(named: $named),
			configKey: SubstitutableAdapterRegistrar::BERICHTENBOX_CONFIG_KEY,
			interface: BerichtenboxAdapterInterface::class,
			mockClass: MockAdapter::class,
			fallbackReason: 'no adapter configured, messages are simulated',
		);
	}//end resolveBerichtenbox()

	/**
	 * Run the registrar and hand back the two factories it registered.
	 *
	 * 🔑 THROUGH THE REGISTRAR, NOT PAST IT. A registrar that binds the wrong
	 * interface, or reads a config key nothing writes, is a silent no-op: the
	 * app boots, the seam resolves to the mock forever, and no test that calls
	 * ConfiguredAdapter directly would notice. That is the same class of
	 * failure this whole change is about, so the wiring is exercised rather
	 * than assumed.
	 *
	 * @return array<string, callable> The factories, keyed by interface.
	 */
	private function registeredFactories(): array {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new SubstitutableAdapterRegistrar())->register(context: $context);

		return $factories;
	}//end registeredFactories()

	/**
	 * Both seams are bound, to their own interface and nothing else.
	 *
	 * @return void
	 */
	public function testTheRegistrarBindsBothSeams(): void {
		$factories = $this->registeredFactories();

		$this->assertArrayHasKey(BerichtenboxAdapterInterface::class, $factories);
		$this->assertArrayHasKey(TemplateEngineAdapterInterface::class, $factories);

		$berichtenbox = $factories[BerichtenboxAdapterInterface::class]($this->container(named: ''));
		$this->assertInstanceOf(MockAdapter::class, $berichtenbox);

		$template = $factories[TemplateEngineAdapterInterface::class]($this->container(named: ''));
		$this->assertInstanceOf(MockTemplateEngineAdapter::class, $template);
	}//end testTheRegistrarBindsBothSeams()

	/**
	 * The template warning asks for the thing the reader is actually missing.
	 *
	 * One message covering both states would tell each reader half of what
	 * they have to do: an instance without filinq needs to install it, an
	 * instance with filinq needs to name its adapter.
	 *
	 * @return void
	 */
	public function testTheTemplateWarningNamesWhatIsActuallyMissing(): void {
		$factory = $this->registeredFactories()[TemplateEngineAdapterInterface::class];

		$factory($this->container(named: '', filinq: false));
		$this->assertStringContainsString('not installed', $this->logged[0][1]);

		$factory($this->container(named: '', filinq: true));
		$this->assertStringContainsString('no template adapter is configured', $this->logged[0][1]);
	}//end testTheTemplateWarningNamesWhatIsActuallyMissing()

	/**
	 * The substitution point that did not exist.
	 *
	 * Before this change the only way to send a real Berichtenbox message was
	 * to edit `BerichtenboxService::getAdapter()`, which an integrator running
	 * dossiq from the app store cannot do.
	 *
	 * @return void
	 */
	public function testAnIntegratorCanSubstituteARealAdapterFromConfigAlone(): void {
		$adapter = $this->resolveBerichtenbox(named: RealBerichtenboxAdapter::class);

		$this->assertInstanceOf(RealBerichtenboxAdapter::class, $adapter);
		$this->assertSame([], $this->logged, 'a real adapter warns about nothing');
	}//end testAnIntegratorCanSubstituteARealAdapterFromConfigAlone()

	/**
	 * With nothing configured the mock runs, and it runs LOUDLY.
	 *
	 * The mock itself is not the defect. The silence was.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSeamFallsBackToTheMockAndSaysSo(): void {
		$adapter = $this->resolveBerichtenbox(named: '');

		$this->assertInstanceOf(MockAdapter::class, $adapter);
		$this->assertCount(1, $this->logged);
		$this->assertSame('warning', $this->logged[0][0]);
		$this->assertStringContainsString('simulated', $this->logged[0][1]);
	}//end testAnUnconfiguredSeamFallsBackToTheMockAndSaysSo()

	/**
	 * A named class that cannot serve the seam is an ERROR, not a fallback.
	 *
	 * The admin asked for a real adapter. Downgrading that to the same warning
	 * an empty setting gets would hide a typo behind a channel that looks like
	 * it is merely unconfigured.
	 *
	 * @return void
	 */
	public function testAClassThatCannotServeTheSeamIsLoggedAsAnError(): void {
		$adapter = $this->resolveBerichtenbox(named: NotAnAdapter::class);

		$this->assertInstanceOf(MockAdapter::class, $adapter);
		$this->assertSame('error', $this->logged[0][0]);
	}//end testAClassThatCannotServeTheSeamIsLoggedAsAnError()

	/**
	 * A config value naming a class nobody ships behaves the same way.
	 *
	 * @return void
	 */
	public function testAnAbsentClassIsLoggedAsAnError(): void {
		$adapter = $this->resolveBerichtenbox(named: 'OCA\\Nobody\\Ships\\This');

		$this->assertInstanceOf(MockAdapter::class, $adapter);
		$this->assertSame('error', $this->logged[0][0]);
	}//end testAnAbsentClassIsLoggedAsAnError()

	/**
	 * The template seam is bound through the same config key it advertises.
	 *
	 * A registrar that reads a different key from the one the settings form
	 * writes is a silent no-op, which is the failure this whole change is
	 * about.
	 *
	 * @return void
	 */
	public function testBothSeamsAdvertiseTheConfigKeyTheyRead(): void {
		$this->assertSame('berichtenbox_adapter', SubstitutableAdapterRegistrar::BERICHTENBOX_CONFIG_KEY);
		$this->assertSame('beschikking_template_adapter', SubstitutableAdapterRegistrar::TEMPLATE_CONFIG_KEY);

		// And the Integrations page looks the seams up under keys that resolve
		// to those same settings, so saving one moves the row it belongs to.
		$this->assertSame(
			[SubstitutableAdapterRegistrar::BERICHTENBOX_CONFIG_KEY],
			IntegrationStatusService::SAVE_REQUIRED_KEYS['berichtenbox']
		);
		$this->assertSame(
			[SubstitutableAdapterRegistrar::TEMPLATE_CONFIG_KEY],
			IntegrationStatusService::SAVE_REQUIRED_KEYS['templates']
		);
	}//end testBothSeamsAdvertiseTheConfigKeyTheyRead()

	/**
	 * An unconfigured adapter seam reads as Simulated, never as Not configured.
	 *
	 * "Not configured" is what every other row says when it knows nothing about
	 * itself. These two rows know something: a mock is running. Understating
	 * that is the same lie the page was built to remove.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredAdapterSeamReadsAsSimulated(): void {
		foreach (['berichtenbox', 'templates'] as $key) {
			$state = IntegrationStatusService::SAVE_UNFILLED_STATE[$key];

			$this->assertSame('simulated', $state[0], $key . ' must not read as Not configured');
			$this->assertStringContainsStringIgnoringCase('mock', $state[1]);
			$this->assertContains($key, IntegrationStatusService::KEYS);
		}

		$this->assertContains('simulated', IntegrationStatusService::STATUSES);
	}//end testAnUnconfiguredAdapterSeamReadsAsSimulated()
}//end class
