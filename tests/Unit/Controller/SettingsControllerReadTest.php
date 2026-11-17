<?php

/**
 * SettingsController unit tests: what index answers whom, the refusal sentences, and the service lookups.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SettingsController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The read side of the settings controller and the update branches the write test leaves out.
 *
 * @covers \OCA\Dossiq\Controller\SettingsController
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class SettingsControllerReadTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The container double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The app manager double.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * The settings service double.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface $settings;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groups;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Fresh doubles; l10n prefixes every sentence it translates.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return SettingsController
	 */
	private function controller(): SettingsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => 'NL: ' . vsprintf($text, $parameters)
		);

		return new SettingsController(
			request: $this->request,
			container: $this->container,
			appManager: $this->appManager,
			settingsService: $this->settings,
			groupManager: $this->groups,
			userSession: $this->session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * Sign in a user.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * An admin gets the full settings, with OpenRegister and filinq present and no hint.
	 *
	 * @return void
	 */
	public function testAnAdminGetsTheFullSettings(): void {
		$this->signIn('admin');
		$this->groups->expects($this->once())->method('isAdmin')->with('admin')->willReturn(true);
		$this->settings->expects($this->once())->method('getSettings')->willReturn(['register' => '5', 'secret' => 'x']);
		$this->settings->expects($this->never())->method('getPublicSettings');
		$this->appManager->method('isEnabledForUser')->with('filinq')->willReturn(true);
		$this->appManager->method('getInstalledApps')->willReturn(['files', 'openregister']);

		$response = $this->controller()->index();

		$this->assertSame(
			[
				'success' => true,
				'openRegisters' => true,
				'isAdmin' => true,
				'config' => ['register' => '5', 'secret' => 'x'],
				'signingAvailable' => true,
				'signingHint' => null,
			],
			$response->getData()
		);
	}//end testAnAdminGetsTheFullSettings()

	/**
	 * A user who is not an admin gets the public settings and the signing hint when filinq is missing.
	 *
	 * @return void
	 */
	public function testANonAdminGetsThePublicSettingsAndTheHint(): void {
		$this->signIn('sofie');
		$this->groups->method('isAdmin')->with('sofie')->willReturn(false);
		$this->settings->expects($this->never())->method('getSettings');
		$this->settings->expects($this->once())->method('getPublicSettings')->willReturn(['register' => '5']);
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->appManager->method('getInstalledApps')->willReturn(['files']);

		$data = $this->controller()->index()->getData();

		$this->assertFalse($data['isAdmin']);
		$this->assertFalse($data['openRegisters']);
		$this->assertSame(['register' => '5'], $data['config']);
		$this->assertFalse($data['signingAvailable']);
		$this->assertStringStartsWith('NL: Filinq is not installed or enabled', $data['signingHint']);
	}//end testANonAdminGetsThePublicSettingsAndTheHint()

	/**
	 * Without a session nobody is asked about admin rights and the public settings answer.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerGetsThePublicSettings(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->groups->expects($this->never())->method('isAdmin');
		$this->settings->expects($this->once())->method('getPublicSettings')->willReturn([]);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->appManager->method('getInstalledApps')->willReturn([]);

		$this->assertFalse($this->controller()->index()->getData()['isAdmin']);
	}//end testAnAnonymousCallerGetsThePublicSettings()

	/**
	 * A failure that is not a refusal is not answered here: it propagates.
	 *
	 * @return void
	 */
	public function testAFailureThatIsNotARefusalPropagates(): void {
		$this->request->method('getParams')->willReturn(['register' => '5']);
		$this->settings->method('updateSettings')->willThrowException(new RuntimeException('disk full'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('disk full');

		$this->controller()->update();
	}//end testAFailureThatIsNotARefusalPropagates()

	/**
	 * An out-of-range refusal names the detail; an unknown rule gets the generic sentence.
	 *
	 * @return void
	 */
	public function testRefusalSentencesForRangeAndUnknownRules(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->settings->method('updateSettings')->willThrowException(
			new RefusedException(rule: 'setting-out-of-range', sentence: 'retention 0', status: 422)
		);
		$range = $this->controller()->update();

		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->settings->method('updateSettings')->willThrowException(
			new RefusedException(rule: 'something-else', sentence: 'ignored')
		);
		$other = $this->controller()->update();

		$this->assertSame(422, $range->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'setting-out-of-range', 'message' => 'NL: This value is not allowed (retention 0).'],
			$range->getData()
		);
		$this->assertSame(409, $other->getStatus());
		$this->assertSame('NL: These settings could not be saved.', $other->getData()['message']);
	}//end testRefusalSentencesForRangeAndUnknownRules()

	/**
	 * A successful save is handed to the integration status service when it is registered.
	 *
	 * @return void
	 */
	public function testASaveIsRecordedForTheIntegrationStatus(): void {
		$this->request->method('getParams')->willReturn(['zgw_url' => 'https://zgw.test']);
		$this->settings->method('updateSettings')->with(['zgw_url' => 'https://zgw.test'])
			->willReturn(['zgw_url' => 'https://zgw.test']);
		$recorder = new class {
			/**
			 * What was recorded.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $saved The saved values.
			 *
			 * @return void
			 */
			public function recordFromSave(array $saved): void {
				$this->saved[] = $saved;
			}
		};
		$this->container->method('has')->with('OCA\\Dossiq\\Service\\IntegrationStatusService')->willReturn(true);
		$this->container->method('get')->with('OCA\\Dossiq\\Service\\IntegrationStatusService')->willReturn($recorder);

		$response = $this->controller()->update();

		$this->assertSame(['success' => true, 'config' => ['zgw_url' => 'https://zgw.test']], $response->getData());
		$this->assertSame([['zgw_url' => 'https://zgw.test']], $recorder->saved);
	}//end testASaveIsRecordedForTheIntegrationStatus()

	/**
	 * Loading forces a configuration import and returns its result.
	 *
	 * @return void
	 */
	public function testLoadForcesTheConfigurationImport(): void {
		$this->settings->expects($this->once())->method('loadConfiguration')->with(true)
			->willReturn(['imported' => 4]);

		$this->assertSame(['imported' => 4], $this->controller()->load()->getData());
	}//end testLoadForcesTheConfigurationImport()

	/**
	 * Without OpenRegister installed neither OpenRegister service can be had.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheServicesAreNotAvailable(): void {
		$this->appManager->method('getInstalledApps')->willReturn(['files']);
		$this->container->expects($this->never())->method('get');
		$controller = $this->controller();

		try {
			$controller->getObjectService();
			$this->fail('getObjectService() should refuse without OpenRegister');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister service is not available.', $e->getMessage());
		}

		$this->expectExceptionMessage('Configuration service is not available.');
		$controller->getConfigurationService();
	}//end testWithoutOpenRegisterTheServicesAreNotAvailable()
}//end class
