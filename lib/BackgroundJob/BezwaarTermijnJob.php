<?php

/**
 * Procest Bezwaartermijn Job.
 *
 * Daily timed job (Awb 6:7). Finds every active bezwaarTrigger whose
 * archiefDatum has been reached and for which no bezwaarschrift was received,
 * then archives the corresponding beschikking via the BeschikkingService and
 * deactivates the trigger so it is not processed twice (idempotent).
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T12
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use DateTimeImmutable;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\BeschikkingService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily job that triggers archival for lapsed bezwaartermijnen.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T12
 */
class BezwaarTermijnJob extends TimedJob {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param BeschikkingService $decisionService The beschikking service.
	 * @param SettingsService $settingsService The settings service.
	 * @param IAppManager $appManager The app manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly BeschikkingService $decisionService,
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the bezwaartermijn check.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('bezwaar_trigger_schema');
		if (in_array('', [$register, $schema], true) === true) {
			return;
		}

		try {
			$triggers = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['archiefTriggerActief' => true],
			);
		} catch (\Throwable $e) {
			$this->logger->error('BezwaarTermijnJob: query failed', ['exception' => $e->getMessage()]);
			return;
		}

		$today = (new DateTimeImmutable())->format('Y-m-d');
		$archived = 0;

		foreach ((array)$triggers as $trigger) {
			$wasArchived = $this->processTrigger(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				trigger: $trigger,
				today: $today,
			);
			if ($wasArchived === true) {
				$archived++;
			}
		}//end foreach

		if ($archived > 0) {
			$this->logger->info(
				'BezwaarTermijnJob: archived ' . $archived . ' beschikking(en)',
				['app' => Application::APP_ID],
			);
		}
	}//end run()

	/**
	 * Process a single bezwaarTrigger: archive the beschikking when its
	 * bezwaartermijn has lapsed without a bezwaar, otherwise deactivate.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register id.
	 * @param string $schema The bezwaarTrigger schema id.
	 * @param mixed $trigger The raw trigger entity or array.
	 * @param string $today Today's date as `Y-m-d`.
	 *
	 * @return bool True when a beschikking was archived.
	 */
	private function processTrigger(
		object $objectService,
		string $register,
		string $schema,
		mixed $trigger,
		string $today,
	): bool {
		$arr = $this->toArray(value: $trigger);
		$archiveDate = (string)($arr['archiveDate'] ?? '');
		$objection = ($arr['objectionReceived'] ?? false) === true;
		$decisionId = (string)($arr['decisionId'] ?? '');

		if ($decisionId === '' || $archiveDate === '' || $archiveDate > $today) {
			return false;
		}

		if ($objection === true) {
			$this->deactivateTrigger(objectService: $objectService, register: $register, schema: $schema, trigger: $arr);
			return false;
		}

		try {
			$this->decisionService->archive($decisionId);
			$this->deactivateTrigger(objectService: $objectService, register: $register, schema: $schema, trigger: $arr);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'BezwaarTermijnJob: archival failed',
				['exception' => $e->getMessage(), 'decisionId' => $decisionId],
			);
		}//end try

		return false;
	}//end processTrigger()

	/**
	 * Deactivate a trigger so it is not processed again (idempotency).
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register id.
	 * @param string $schema The bezwaarTrigger schema id.
	 * @param array<string, mixed> $trigger The trigger payload.
	 *
	 * @return void
	 */
	private function deactivateTrigger(object $objectService, string $register, string $schema, array $trigger): void {
		$trigger['archiefTriggerActief'] = false;

		try {
			$objectService->saveObject(object: $trigger, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->error('BezwaarTermijnJob: deactivate failed', ['exception' => $e->getMessage()]);
		}
	}//end deactivateTrigger()

	/**
	 * Normalise an ObjectService value to an array.
	 *
	 * @param mixed $value The entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end toArray()
}//end class
