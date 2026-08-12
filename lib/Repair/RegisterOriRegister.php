<?php

/**
 * Procest Register ORI Register Repair Step
 *
 * Repair step that idempotently provisions the ORI (Open Raadsinformatie)
 * register and all its entity schemas via the OpenRegister ConfigurationService.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that provisions the ORI (Open Raadsinformatie) register via ConfigurationService.
 *
 * The step is idempotent: if the ORI register with slug `ori` already exists it is
 * updated; no duplicate register is created.
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-2
 */
class RegisterOriRegister implements IRepairStep {
	/**
	 * Constructor for RegisterOriRegister.
	 *
	 * @param SettingsService $settingsService The settings service (for availability check)
	 * @param ContainerInterface $container The DI container (for lazy ConfigurationService)
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-2
	 */
	public function getName(): string {
		return 'Register ORI (Open Raadsinformatie) register and schemas via ConfigurationService';
	}//end getName()

	/**
	 * Run the repair step to provision the ORI register.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-2
	 */
	public function run(IOutput $output): void {
		$output->info('Provisioning ORI (Open Raadsinformatie) register...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning(
				'OpenRegister is not installed or enabled. Skipping ORI register provisioning.'
			);
			$this->logger->warning('Procest: OpenRegister not available, skipping ORI register provisioning');
			return;
		}

		$configPath = __DIR__ . '/../Settings/ori_register.json';
		if (file_exists(filename: $configPath) === false) {
			$output->warning('ORI register file not found at ' . $configPath);
			$this->logger->error('Procest: ORI register file not found at ' . $configPath);
			return;
		}

		try {
			$configurationService = $this->container->get(
				'OCA\OpenRegister\Service\ConfigurationService'
			);
		} catch (\Exception $e) {
			$output->warning('Could not access ConfigurationService: ' . $e->getMessage());
			$this->logger->error(
				'Procest: Could not access ConfigurationService for ORI',
				['exception' => $e->getMessage()]
			);
			return;
		}

		try {
			$configContent = file_get_contents(filename: $configPath);
			$configData = json_decode(json: $configContent, associative: true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				$output->warning('ORI register file contains invalid JSON');
				return;
			}

			$configVersion = ($configData['info']['version'] ?? '1.0.0');

			$importResult = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configData,
				version: 'ori-' . $configVersion,
				force: false,
			);

			$output->info('ORI register provisioned successfully (version: ' . $configVersion . ')');
			$this->logger->info(
				'Procest: ORI register provisioned',
				['version' => $configVersion, 'result' => $importResult]
			);
		} catch (\Throwable $e) {
			$output->warning('Could not provision ORI register: ' . $e->getMessage());
			$this->logger->error(
				'Procest: ORI register provisioning failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end run()
}//end class
