<?php

/**
 * Dossiq repair step: existing Woo cases show their publication state
 *
 * Design D-2 of woo-publish-decision-from-the-case. The case carries
 * `wooPublicationStatus` and `wooPublicationUrl` so the header actions
 * `woo-publish` and `woo-withdraw` can gate on it. Three writers keep them
 * current from now on; a Woo case whose decision was assembled or published
 * before those writers existed shows nothing, and so offers no action. This
 * step copies the state each Woo decision already holds onto its case, once.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies each Woo decision's publication state onto its case. Idempotent, non-fatal.
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */
class BackfillWooPublicationState implements IRepairStep {

	use SearchesObjects;

	/**
	 * The app config key that records a complete run (ADR-106).
	 */
	public const CONFIG_KEY = 'woo_publication_state_backfill';

	/**
	 * The version a complete run writes under CONFIG_KEY.
	 */
	public const VERSION = '1';

	/**
	 * Rows read per page.
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on pages, so a store that ignores `_offset` cannot loop forever.
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param IAppConfig         $appConfig       Holds the persisted completion key.
	 * @param LoggerInterface    $logger          Logger.
	 * @param IURLGenerator|null $urlGenerator    Makes the publication link absolute, as publish() does.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ?IURLGenerator $urlGenerator = null,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function getName(): string {
		return 'Show the Woo publication state on existing Woo cases';
	}//end getName()

	/**
	 * Copy the state once. Never throws; a run with a failed write stays unmarked and runs again.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '') === self::VERSION) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$decisionSchema = (string)$this->settingsService->getConfigValue('decision_schema');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $decisionSchema === '' || $caseSchema === '') {
			$output->info('OpenRegister or the case and decision schemas are unavailable, skipping the Woo publication state backfill.');
			return;
		}

		$tally = (array)$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->backfill(
				objectService: $objectService,
				register: $register,
				decisionSchema: $decisionSchema,
				caseSchema: $caseSchema
			)
		);

		$output->info(
			'Woo publication state backfill: ' . $tally['written'] . ' cases updated, '
			. $tally['unchanged'] . ' already right, ' . $tally['missing'] . ' without their case, '
			. $tally['failed'] . ' failed.'
		);

		if ($tally['failed'] === 0 && $tally['readable'] === true) {
			$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, self::VERSION);
		}
	}//end run()

	/**
	 * The case fields one Woo decision implies, or [] when the decision is no Woo decision.
	 *
	 * @param array<string, mixed> $decision The stored decision.
	 *
	 * @return array<string, string> `wooPublicationStatus` and, when published, `wooPublicationUrl`.
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function stateFor(array $decision): array {
		if (empty($decision['wooSummary']) === true) {
			return [];
		}

		$publication = ($decision['wooPublication'] ?? []);
		if (is_array($publication) === false) {
			$publication = [];
		}

		$status = (string)($publication['status'] ?? '');
		if ($status === 'withdrawn') {
			return ['wooPublicationStatus' => 'withdrawn'];
		}

		$url = (string)($publication['publicationUrl'] ?? '');
		if ($status === 'published' && $url !== '') {
			return ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => $this->absolute(path: $url)];
		}

		return ['wooPublicationStatus' => 'ready'];
	}//end stateFor()

	/**
	 * Walk every decision and write the cases whose state differs.
	 *
	 * @param object $objectService  OpenRegister's object service.
	 * @param string $register       The register.
	 * @param string $decisionSchema The decision schema.
	 * @param string $caseSchema     The case schema.
	 *
	 * @return array{written: int, unchanged: int, missing: int, failed: int, readable: bool} The tally.
	 */
	private function backfill(object $objectService, string $register, string $decisionSchema, string $caseSchema): array {
		$tally = ['written' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0, 'readable' => true];

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$rows = $this->searchObjectsAsArraysUnscoped(
					objectService: $objectService,
					register: $register,
					schema: $decisionSchema,
					filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: the Woo publication state backfill could not read the decisions',
					['app' => Application::APP_ID, 'error' => $e->getMessage()]
				);
				$tally['readable'] = false;
				break;
			}

			foreach ($rows as $decision) {
				$state = $this->stateFor(decision: $decision);
				$caseId = (string)($decision['case'] ?? '');
				if ($state === [] || $caseId === '') {
					continue;
				}

				$tally[$this->writeOne(objectService: $objectService, register: $register, caseSchema: $caseSchema, caseId: $caseId, state: $state)]++;
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}//end for

		return $tally;
	}//end backfill()

	/**
	 * Write one case when its state differs, and say which tally it belongs to.
	 *
	 * @param object                $objectService OpenRegister's object service.
	 * @param string                $register      The register.
	 * @param string                $caseSchema    The case schema.
	 * @param string                $caseId        The case UUID.
	 * @param array<string, string> $state         The fields to show.
	 *
	 * @return string One of written|unchanged|missing|failed.
	 */
	private function writeOne(object $objectService, string $register, string $caseSchema, string $caseId, array $state): string {
		try {
			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
			if ($case === null) {
				// A decision whose case is gone has nothing to show it on.
				return 'missing';
			}

			if ($this->alreadyShows(case: $case, state: $state) === true) {
				return 'unchanged';
			}

			$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId, changes: $state);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the Woo publication state backfill could not write a case',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'written';
	}//end writeOne()

	/**
	 * Whether the case already carries every field of the state.
	 *
	 * @param array<string, mixed>  $case  The stored case.
	 * @param array<string, string> $state The fields to show.
	 *
	 * @return bool
	 */
	private function alreadyShows(array $case, array $state): bool {
		foreach ($state as $key => $value) {
			if ((string)($case[$key] ?? '') !== $value) {
				return false;
			}
		}

		return true;
	}//end alreadyShows()

	/**
	 * An absolute link, or the path when no URL generator is wired.
	 *
	 * @param string $path The stored path or url.
	 *
	 * @return string The link.
	 */
	private function absolute(string $path): string {
		if ($this->urlGenerator === null || str_starts_with($path, 'http') === true) {
			return $path;
		}

		return $this->urlGenerator->getAbsoluteURL($path);
	}//end absolute()
}//end class
