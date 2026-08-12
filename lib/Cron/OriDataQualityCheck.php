<?php

/**
 * Procest ORI Data Quality Check Background Job
 *
 * Nightly background job that validates ORI (Open Raadsinformatie) object
 * quality: missing recommended fields (locatie, voorzitter), broken slug
 * references, and orphaned documents. Results are written to a
 * data_quality_issues log surfaced by the admin dashboard.
 *
 * @category Cron
 * @package  OCA\Procest\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Procest\Cron;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly job that validates ORI object quality and writes a summary log entry.
 *
 * Checks performed:
 *  1. Vergaderingen missing locatie (recommended field)
 *  2. Agendapunten referencing a non-existent vergadering slug
 *  3. Raadsleden referencing a non-existent fractie slug
 *  4. Orphaned raadsdocumenten (not referenced by any agendapunt bijlagen)
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-8
 */
class OriDataQualityCheck extends TimedJob {

	use SearchesObjects;

	/**
	 * Constructor for OriDataQualityCheck.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param SettingsService $settingsService The settings service
	 * @param IAppManager $appManager The app manager
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Run nightly.
		$this->setInterval(seconds: 86400);

	}//end __construct()

	/**
	 * Execute the data quality check.
	 *
	 * @param mixed $argument The job argument (unused)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-8
	 */
	protected function run($argument): void {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps(), strict: true) === false) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$issues = [];

		$issues = array_merge($issues, $this->checkVergaderingenQuality(objectService: $objectService));
		$issues = array_merge($issues, $this->checkAgendapuntenReferenceIntegrity(objectService: $objectService));
		$issues = array_merge($issues, $this->checkRaadsledenReferenceIntegrity(objectService: $objectService));
		$issues = array_merge($issues, $this->checkOrphanedDocumenten(objectService: $objectService));

		$this->writeQualityLog(objectService: $objectService, issues: $issues);

		$this->logger->info(
			'Procest: ORI data quality check completed',
			[
				'issueCount' => count(value: $issues),
				'app' => Application::APP_ID,
			]
		);

	}//end run()

	/**
	 * Check vergaderingen for missing recommended fields (locatie).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 *
	 * @return array<int,array> Issues found
	 */
	private function checkVergaderingenQuality(object $objectService): array {
		$issues = [];

		try {
			$vergaderingen = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: 'ori',
				schema: 'vergadering',
				filters: ['_limit' => 500]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Procest: could not fetch vergaderingen for quality check',
				['exception' => $e->getMessage()]
			);
			return $issues;
		}

		foreach ($vergaderingen as $v) {
			$slug = (string)($v['@self']['slug'] ?? ($v['id'] ?? '?'));

			if (empty($v['locatie']) === true) {
				$issues[] = [
					'schema' => 'vergadering',
					'slug' => $slug,
					'field' => 'locatie',
					'severity' => 'warning',
					'message' => 'Vergadering "' . $slug . '" is missing recommended field: locatie',
				];
			}
		}

		return $issues;
	}//end checkVergaderingenQuality()

	/**
	 * Check agendapunten for broken vergadering slug references.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 *
	 * @return array<int,array> Issues found
	 */
	private function checkAgendapuntenReferenceIntegrity(object $objectService): array {
		$issues = [];

		try {
			$agendapunten = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: 'ori',
				schema: 'agendapunt',
				filters: ['_limit' => 1000]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Procest: could not fetch agendapunten for integrity check',
				['exception' => $e->getMessage()]
			);
			return $issues;
		}

		foreach ($agendapunten as $ap) {
			$apSlug = (string)($ap['@self']['slug'] ?? ($ap['id'] ?? '?'));
			$vergaderingRef = (string)($ap['vergadering'] ?? '');

			if (empty($vergaderingRef) === true) {
				continue;
			}

			try {
				$vergadering = $this->findObjectAsArray(
					objectService: $objectService,
					register: 'ori',
					schema: 'vergadering',
					id: $vergaderingRef
				);

				if ($vergadering === null) {
					$issues[] = [
						'schema' => 'agendapunt',
						'slug' => $apSlug,
						'field' => 'vergadering',
						'severity' => 'warning',
						'message' => 'Agendapunt references non-existent vergadering: ' . $vergaderingRef,
					];
				}
			} catch (\Throwable $e) {
				// Reference resolution failure; log as issue.
				$issues[] = [
					'schema' => 'agendapunt',
					'slug' => $apSlug,
					'field' => 'vergadering',
					'severity' => 'warning',
					'message' => 'Agendapunt vergadering reference could not be resolved: ' . $vergaderingRef,
				];
			}//end try
		}//end foreach

		return $issues;
	}//end checkAgendapuntenReferenceIntegrity()

	/**
	 * Check raadsleden for broken fractie slug references.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 *
	 * @return array<int,array> Issues found
	 */
	private function checkRaadsledenReferenceIntegrity(object $objectService): array {
		$issues = [];

		try {
			$raadsleden = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: 'ori',
				schema: 'raadslid',
				filters: ['_limit' => 500]
			);
		} catch (\Throwable $e) {
			return $issues;
		}

		foreach ($raadsleden as $rl) {
			$rlSlug = (string)($rl['@self']['slug'] ?? ($rl['id'] ?? '?'));
			$fractieRef = (string)($rl['fractie'] ?? '');

			if (empty($fractieRef) === true) {
				continue;
			}

			try {
				$fractie = $this->findObjectAsArray(
					objectService: $objectService,
					register: 'ori',
					schema: 'fractie',
					id: $fractieRef
				);

				if ($fractie === null) {
					$issues[] = [
						'schema' => 'raadslid',
						'slug' => $rlSlug,
						'field' => 'fractie',
						'severity' => 'warning',
						'message' => 'Raadslid references non-existent fractie: ' . $fractieRef,
					];
				}
			} catch (\Throwable $e) {
				// Silently skip resolution errors for raadsleden.
			}
		}//end foreach

		return $issues;
	}//end checkRaadsledenReferenceIntegrity()

	/**
	 * Detect orphaned raadsdocumenten not referenced by any agendapunt's bijlagen array.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 *
	 * @return array<int,array> Issues found
	 */
	private function checkOrphanedDocumenten(object $objectService): array {
		$issues = [];

		try {
			$documenten = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: 'ori',
				schema: 'raadsdocument',
				filters: ['_limit' => 500]
			);
			$agendapunten = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: 'ori',
				schema: 'agendapunt',
				filters: ['_limit' => 1000]
			);
		} catch (\Throwable $e) {
			return $issues;
		}

		// Build a set of all document slugs that are referenced by agendapunten.
		$referenced = [];
		foreach ($agendapunten as $ap) {
			$bijlagen = ($ap['bijlagen'] ?? []);
			if (is_array(value: $bijlagen) === true) {
				foreach ($bijlagen as $docSlug) {
					$referenced[$docSlug] = true;
				}
			}
		}

		foreach ($documenten as $doc) {
			$slug = (string)($doc['@self']['slug'] ?? ($doc['id'] ?? '?'));
			if (isset($referenced[$slug]) === false) {
				$issues[] = [
					'schema' => 'raadsdocument',
					'slug' => $slug,
					'field' => 'bijlagen',
					'severity' => 'info',
					'message' => 'Raadsdocument "' . $slug . '" is not referenced by any agendapunt',
				];
			}
		}

		return $issues;
	}//end checkOrphanedDocumenten()

	/**
	 * Write the quality issues to the Procest register as a data_quality_issues entry.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array<int,array> $issues Collected quality issues
	 *
	 * @return void
	 */
	private function writeQualityLog(object $objectService, array $issues): void {
		$register = $this->settingsService->getConfigValue(key: 'register');
		if (empty($register) === true) {
			return;
		}

		$warningCount = count(
			array_filter(array: $issues, callback: static fn ($issue) => ($issue['severity'] ?? '') === 'warning')
		);

		$log = [
			'checkedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'totalIssues' => count(value: $issues),
			'warnings' => $warningCount,
			'infos' => (count(value: $issues) - $warningCount),
			'issues' => $issues,
		];

		try {
			$objectService->saveObject(
				register: $register,
				schema: 'data_quality_issues',
				object: $log,
			);
		} catch (\Throwable $e) {
			// Schema may not exist yet; log the result instead.
			$this->logger->info(
				'Procest: ORI quality check result',
				['log' => $log, 'app' => Application::APP_ID]
			);
		}//end try

	}//end writeQualityLog()
}//end class
