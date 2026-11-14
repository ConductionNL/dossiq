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
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Woo\WooResultLink;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies each Woo decision's publication state onto its case. Idempotent, non-fatal.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
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
	 * @param SettingsServiceInterface    $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param IAppConfig         $appConfig       Holds the persisted completion key.
	 * @param LoggerInterface    $logger          Logger.
	 * @param IURLGenerator|null $urlGenerator    Makes the publication link absolute, as publish() does.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
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
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
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
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '') === self::VERSION) {
			return;
		}

		$target = $this->backfillTarget();
		if ($target === null) {
			$output->info('OpenRegister or the case and decision schemas are unavailable, skipping the Woo publication state backfill.');
			return;
		}

		$tally = $this->backfillAsSystem(target: $target);

		$this->reportAndMark(output: $output, tally: $tally);
	}//end run()

	/**
	 * The object service, register, decision schema and case schema, or null when one is unavailable.
	 *
	 * @return array{0: object, 1: string, 2: string, 3: string}|null The backfill target.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	private function backfillTarget(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$decisionSchema = (string)$this->settingsService->getConfigValue('decision_schema');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $decisionSchema === '' || $caseSchema === '') {
			return null;
		}

		return [$objectService, $register, $decisionSchema, $caseSchema];
	}//end backfillTarget()

	/**
	 * Run the backfill as the system.
	 *
	 * @param array{0: object, 1: string, 2: string, 3: string} $target The object service, register, decision and case schema.
	 *
	 * @return array{written: int, unchanged: int, missing: int, failed: int, readable: bool} The tally.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	private function backfillAsSystem(array $target): array {
		[$objectService, $register, $decisionSchema, $caseSchema] = $target;

		return (array)$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->backfill(
				objectService: $objectService,
				register: $register,
				decisionSchema: $decisionSchema,
				caseSchema: $caseSchema
			)
		);
	}//end backfillAsSystem()

	/**
	 * Report the tally, and mark the backfill done when every decision was read and no write failed.
	 *
	 * @param IOutput                                                                         $output Progress reporting.
	 * @param array{written: int, unchanged: int, missing: int, failed: int, readable: bool} $tally  The tally.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
	 */
	private function reportAndMark(IOutput $output, array $tally): void {
		$output->info(
			'Woo publication state backfill: ' . $tally['written'] . ' cases updated, '
			. $tally['unchanged'] . ' already right, ' . $tally['missing'] . ' without their case, '
			. $tally['failed'] . ' failed.'
		);

		if ($tally['failed'] === 0 && $tally['readable'] === true) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, self::VERSION);
		}
	}//end reportAndMark()

	/**
	 * The case fields one Woo decision implies, or [] when the decision is no Woo decision.
	 *
	 * @param array<string, mixed> $decision The stored decision.
	 *
	 * @return array<string, string> `wooPublicationStatus` and, when published, `wooPublicationUrl`.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-publication-status-surfaced-on-the-woo-assessment-view
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
		$read = fn (int $page): ?array => $this->readDecisionPage(
			objectService: $objectService, register: $register,
			decisionSchema: $decisionSchema, page: $page
		);
		$write = fn (string $caseId, array $state): string => $this->writeOne(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			caseId: $caseId,
			state: $state
		);

		return $this->walkDecisions(read: $read, write: $write);
	}//end backfill()

	/**
	 * Page through the decisions until a short page, an unreadable page or the page cap.
	 *
	 * @param callable(int): (array|null)                         $read  Reads one page of decisions, null when it cannot.
	 * @param callable(string, array<string, string>): string $write Writes one case, answering its tally.
	 *
	 * @return array{written: int, unchanged: int, missing: int, failed: int, readable: bool} The tally.
	 */
	private function walkDecisions(callable $read, callable $write): array {
		$tally = ['written' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0, 'readable' => true];

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $read($page);
			if ($rows === null) {
				$tally['readable'] = false;
				break;
			}

			$tally = $this->tallyDecisions(tally: $tally, rows: $rows, write: $write);
			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $tally;
	}//end walkDecisions()

	/**
	 * Read one page of decisions, or null when the read fails.
	 *
	 * @param object $objectService  OpenRegister's object service.
	 * @param string $register       The register.
	 * @param string $decisionSchema The decision schema.
	 * @param int    $page           The zero-based page.
	 *
	 * @return array<int, array<string, mixed>>|null The decisions, or null when unreadable.
	 */
	private function readDecisionPage(object $objectService, string $register, string $decisionSchema, int $page): ?array {
		try {
			return $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $decisionSchema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);
		} catch (Throwable $e) {
			$context = ['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()];
			$this->logger->warning('Dossiq: the Woo publication state backfill could not read the decisions', $context);
			return null;
		}
	}//end readDecisionPage()

	/**
	 * Write the case of every Woo decision on one page and count the outcomes.
	 *
	 * @param array{written: int, unchanged: int, missing: int, failed: int, readable: bool} $tally The running tally.
	 * @param array<int, array<string, mixed>>                                                $rows  The decisions.
	 * @param callable(string, array<string, string>): string                             $write Writes one case, answering its tally.
	 *
	 * @return array{written: int, unchanged: int, missing: int, failed: int, readable: bool} The new tally.
	 */
	private function tallyDecisions(array $tally, array $rows, callable $write): array {
		foreach ($rows as $decision) {
			$state = $this->stateFor(decision: $decision);
			$caseId = (string)($decision['case'] ?? '');
			if ($state === [] || $caseId === '') {
				continue;
			}

			$tally[$write($caseId, $state)]++;
		}

		return $tally;
	}//end tallyDecisions()

	/**
	 * Write one case when its state differs, and say which tally it belongs to.
	 *
	 * @param object                $objectService OpenRegister's object service.
	 * @param string                $register      The register.
	 * @param string                $caseSchema    The case schema.
	 * @param string                $caseId        The case UUID.
	 * @param array<string, string> $state         The fields to show, before the result link.
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

			// The requester's link travels with the state, as it does on publish and withdraw.
			$changes = array_merge($state, (new WooResultLink())->changesFor(state: $state));
			if ($this->alreadyShows(case: $case, state: $changes) === true) {
				return 'unchanged';
			}

			$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId, changes: $changes);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the Woo publication state backfill could not write a case',
				['app' => AppIdentity::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'written';
	}//end writeOne()

	/**
	 * Whether the case already carries every field of the state.
	 *
	 * @param array<string, mixed> $case  The stored case.
	 * @param array<string, mixed> $state The fields to show.
	 *
	 * @return bool
	 */
	private function alreadyShows(array $case, array $state): bool {
		foreach ($state as $key => $value) {
			if (json_encode($case[$key] ?? null) !== json_encode($value)) {
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
