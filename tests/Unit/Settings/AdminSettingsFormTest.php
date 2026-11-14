<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\CaseDefinition\PackageAdminTabs;
use OCA\Dossiq\Service\Queue\QueueUrgencySettings;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

/**
 * The admin form seeds every tab's initial state from what is stored, with the
 * tab's own defaults for what is not, and registers as a delegated setting.
 *
 * @covers \OCA\Dossiq\Settings\AdminSettings
 * @uses   \OCA\Dossiq\Prerequisites
 * @uses   \OCA\Dossiq\Settings\DependencyApps
 * @uses   \OCA\Dossiq\Support\FleetAppId
 * @uses   \OCA\Dossiq\Service\Queue\QueueUrgencySettings
 * @uses   \OCA\Dossiq\Service\Queue\UrgencyProfile
 * @uses   \OCA\Dossiq\Service\Settings\MenuStructure
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class AdminSettingsFormTest extends TestCase {

	/**
	 * The initial state the form provided, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $provided = [];

	/**
	 * Render the form over these stored config values.
	 *
	 * @param array<string, string> $stored The stored values, by key.
	 *
	 * @return \OCP\AppFramework\Http\TemplateResponse
	 */
	private function render(array $stored): \OCP\AppFramework\Http\TemplateResponse {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $data): void {
				$this->provided[$key] = $data;
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('dossiq')->willReturn('1.4.2');

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);

		return $this->form(appManager: $appManager, initialState: $initialState, settingsService: $settingsService)->getForm();
	}//end render()

	/**
	 * The form over these collaborators.
	 *
	 * @param IAppManager     $appManager      The app manager.
	 * @param IInitialState   $initialState    The initial state.
	 * @param SettingsService $settingsService The settings.
	 *
	 * @return AdminSettings
	 */
	private function form(IAppManager $appManager, IInitialState $initialState, SettingsService $settingsService): AdminSettings {
		$serverVersion = (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
		\Closure::bind(
			function (): void {
				$this->version = [34, 0, 0];
			},
			$serverVersion,
			ServerVersion::class
		)();

		return new AdminSettings(
			appManager: $appManager,
			initialState: $initialState,
			settingsService: $settingsService,
			queueUrgency: new QueueUrgencySettings(settings: $settingsService),
			serverVersion: $serverVersion,
			packageTabs: $this->packageTabs(),
		);
	}//end form()

	/**
	 * The installed package tabs the form reads: one package with a tab.
	 *
	 * @return PackageAdminTabs
	 */
	private function packageTabs(): PackageAdminTabs {
		$tabs = $this->createMock(PackageAdminTabs::class);
		$tabs->method('installed')->willReturn(
			[['id' => 'vth', 'version' => '1.4.0', 'title' => 'VTH', 'lists' => ['caseTypes' => [['id' => 'ct-1', 'title' => 'Sloopmelding', 'status' => '']]]]]
		);

		return $tabs;
	}//end packageTabs()

	/**
	 * The form renders the admin template and provides every tab's state.
	 *
	 * @return void
	 */
	public function testTheFormRendersTheAdminTemplateWithEveryState(): void {
		$response = $this->render(stored: []);

		self::assertSame('settings/admin', $response->getTemplateName());
		self::assertSame('dossiq', $response->getApp());
		self::assertSame('1.4.2', $this->provided['version']);
		foreach (['prerequisites', 'ownerApps', 'dependencyApps', 'publicationSettings', 'consultationSettings', 'mandaatSettings', 'queueUrgencySettings', 'menu_structure', 'packageAdminTabs'] as $key) {
			self::assertArrayHasKey($key, $this->provided, $key);
		}

		self::assertSame(['vth'], array_column($this->provided['packageAdminTabs'], 'id'));
		self::assertSame('VTH', $this->provided['packageAdminTabs'][0]['title']);

		self::assertSame(
			['Openregister', 'Integriq', 'Filinq', 'Pipelinq', 'Portaliq', 'Humaniq'],
			array_column($this->provided['dependencyApps'], 'name')
		);
	}//end testTheFormRendersTheAdminTemplateWithEveryState()

	/**
	 * A fresh instance gets the tabs' own defaults.
	 *
	 * @return void
	 */
	public function testAFreshInstanceGetsTheDefaults(): void {
		$this->render(stored: []);

		self::assertSame(['template' => '', 'dateRule' => ''], $this->provided['publicationSettings']);
		self::assertSame(
			['defaultDeadlineDays' => 28, 'warningOffsetDays' => 5, 'externalResponseUrl' => '', 'bottleneckThreshold' => 0.2, 'writable' => true],
			$this->provided['consultationSettings']
		);
		self::assertSame(
			['decideskConnection' => 'decidesk-default', 'defaultExtensionDays' => 14, 'autoFinalizeApproved' => false, 'writable' => true],
			$this->provided['mandaatSettings']
		);
	}//end testAFreshInstanceGetsTheDefaults()

	/**
	 * Stored values are read and typed for the tabs.
	 *
	 * @return void
	 */
	public function testStoredValuesAreReadAndTyped(): void {
		$this->render(
			stored: [
				'publication_template' => 'tpl-1',
				'publication_date_rule' => 'decision+14',
				'consultation_default_deadline_days' => '42',
				'consultation_warning_offset_days' => '3',
				'consultation_external_response_url' => 'https://advies.test/antwoord',
				'consultation_bottleneck_threshold' => '0.35',
				'mandaat_decidesk_connection' => 'decidesk-raad',
				'mandaat_default_extension_days' => '21',
				'mandaat_auto_finalize_approved' => '1',
			]
		);

		self::assertSame(['template' => 'tpl-1', 'dateRule' => 'decision+14'], $this->provided['publicationSettings']);
		self::assertSame(
			['defaultDeadlineDays' => 42, 'warningOffsetDays' => 3, 'externalResponseUrl' => 'https://advies.test/antwoord', 'bottleneckThreshold' => 0.35, 'writable' => true],
			$this->provided['consultationSettings']
		);
		self::assertSame(
			['decideskConnection' => 'decidesk-raad', 'defaultExtensionDays' => 21, 'autoFinalizeApproved' => true, 'writable' => true],
			$this->provided['mandaatSettings']
		);
	}//end testStoredValuesAreReadAndTyped()

	/**
	 * Auto-finalising is on only for the stored value 1.
	 *
	 * @return void
	 */
	public function testAutoFinaliseIsOnOnlyForOne(): void {
		$this->render(stored: ['mandaat_auto_finalize_approved' => 'true']);

		self::assertFalse($this->provided['mandaatSettings']['autoFinalizeApproved']);
	}//end testAutoFinaliseIsOnOnlyForOne()

	/**
	 * The form registers in the dossiq section, first, with no delegatable keys.
	 *
	 * @return void
	 */
	public function testTheDelegatedSettingRegistration(): void {
		$form = $this->form(
			appManager: $this->createMock(IAppManager::class),
			initialState: $this->createMock(IInitialState::class),
			settingsService: $this->createMock(SettingsService::class),
		);

		self::assertSame('dossiq', $form->getSection());
		self::assertSame(10, $form->getPriority());
		self::assertNull($form->getName());
		self::assertSame([], $form->getAuthorizedAppConfig());
	}//end testTheDelegatedSettingRegistration()
}//end class
