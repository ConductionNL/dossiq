<?php

/**
 * Dossiq repair step: give cases that have no `lastActivityAt` one.
 *
 * `lastActivityAt` is written when someone works on a case, and on a new case
 * when it is made. A case from before the field existed has none, so the
 * stalled-case lists, which ask for cases idle for more than a week by this
 * field, never show it. The step dates such a case from the newest activity
 * date the case itself carries (a status move, a first response, a
 * publication, a pause, a withdrawal, a priority override, a major
 * declaration), else from its creation. A date in the future is not activity
 * and is passed over. A case that has the field is left alone. The step runs
 * until a run writes every case it meant to, then never again.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use DateTimeInterface;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Activity\CaseActivityStamp;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backfill `lastActivityAt` on the cases that lack it.
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */
class BackfillCaseLastActivity implements IRepairStep {

	use SearchesObjects;

	/**
	 * The app config key that records a complete run.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'case_last_activity_backfill';

	/**
	 * The value of the key once the step has run completely.
	 *
	 * @var string
	 */
	public const DONE = '1';

	/**
	 * The case's own fields that record someone working on it.
	 *
	 * @var array<int, string>
	 */
	public const ACTIVITY_FIELDS = [
		'currentStatusEnteredAt',
		'firstResponseAt',
		'publishedAt',
		'waitingSince',
		'waitingOnApplicantSince',
		'withdrawnAt',
		'priorityOverrideAt',
		'majorDeclaredAt',
	];

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on the paging, so a store that never answers a short page
	 * cannot keep an upgrade running for ever.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Names the register and the case schema, and hands out the object service.
	 * @param IAppConfig         $appConfig       Holds the completion key.
	 * @param CaseDateNormaliser $dates           Reads and writes every date, and is the clock that passes over dates in the future.
	 * @param LoggerInterface    $logger          Records a case that refused.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Date the last activity of existing cases (lastActivityAt)';
	}//end getName()

	/**
	 * Run the backfill once and say what it did.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '') === self::DONE) {
			return;
		}

		$run = $this->configuredRun();
		if ($run === null) {
			$output->info('lastActivityAt backfill: OpenRegister or the case schema is not available, skipped.');
			return;
		}

		$tally = $this->backfillAsSystem(run: $run, output: $output);
		if ($tally === null) {
			return;
		}

		$output->info(
			'lastActivityAt backfill: ' . (int)($tally['dated'] ?? 0) . ' dated, '
			. (int)($tally['failed'] ?? 0) . ' failed, '
			. (int)($tally['skipped'] ?? 0) . ' already dated.'
		);

		if ((int)($tally['failed'] ?? 0) === 0) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, self::DONE);
		}
	}//end run()

	/**
	 * The pass over the configured register and case schema, or null when OpenRegister or either is missing.
	 *
	 * @return CaseDatingRun|null The pass.
	 */
	private function configuredRun(): ?CaseDatingRun {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		$run = new CaseDatingRun(objectService: $objectService, register: $register);
		$run->schema = $schema;

		return $run;
	}//end configuredRun()

	/**
	 * Run the backfill as the system where OpenRegister allows it; null, logged and reported, when the cases cannot be read.
	 *
	 * @param CaseDatingRun $run    The pass.
	 * @param IOutput       $output The upgrade output.
	 *
	 * @return array<string, int>|null The tally, or null.
	 */
	private function backfillAsSystem(CaseDatingRun $run, IOutput $output): ?array {
		try {
			return (array)$this->runAsSystemIfAvailable(
				objectService: $run->objectService,
				operation: fn (): array => $this->backfill(run: $run)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the lastActivityAt backfill could not read the cases',
				['app' => AppIdentity::APP_ID, 'error' => $e->getMessage()]
			);
			$output->info('lastActivityAt backfill: the cases could not be read, skipped.');
			return null;
		}
	}//end backfillAsSystem()

	/**
	 * The date a case without `lastActivityAt` should get, or '' when it has one.
	 *
	 * @param array<string, mixed> $case The stored case.
	 * @param DateTimeInterface    $now  The current moment.
	 *
	 * @return string The date (ISO 8601, UTC), or ''.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md
	 */
	public function dateFor(array $case, DateTimeInterface $now): string {
		if (trim((string)($case[CaseActivityStamp::FIELD] ?? '')) !== '') {
			return '';
		}

		$newest = null;
		foreach (self::ACTIVITY_FIELDS as $field) {
			$moment = $this->dates->tryParse(value: ($case[$field] ?? null));
			if ($moment !== null && $moment <= $now && ($newest === null || $moment > $newest)) {
				$newest = $moment;
			}
		}

		$newest ??= $this->dates->tryParse(value: (($case['@self'] ?? [])['created'] ?? null));
		if ($newest === null) {
			return '';
		}

		return $this->dates->formatMoment(moment: $newest);
	}//end dateFor()

	/**
	 * Read every case first, then date the ones without a date.
	 *
	 * Writing while paging by offset can move a row between pages, so one case
	 * would be seen twice and another not at all.
	 *
	 * @param CaseDatingRun $run The pass.
	 *
	 * @return array{dated: int, failed: int, skipped: int} What happened.
	 */
	private function backfill(CaseDatingRun $run): array {
		$run->now = $this->dates->now();
		$this->collectUndated(run: $run);
		foreach (array_keys($run->todo) as $uuid) {
			$this->writeDate(run: $run, uuid: (string)$uuid);
		}

		return $run->tally;
	}//end backfill()

	/**
	 * Page through every case, queueing the date of each one without a date.
	 *
	 * @param CaseDatingRun $run The pass.
	 *
	 * @return void
	 */
	private function collectUndated(CaseDatingRun $run): void {
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->schema,
				filters: ['_limit' => self::PAGE, '_offset' => ($page * self::PAGE)]
			);

			foreach ($rows as $row) {
				$this->queueCase(run: $run, row: $row);
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}
	}//end collectUndated()

	/**
	 * Queue one case's date, or count it skipped when it has one or no id.
	 *
	 * @param CaseDatingRun        $run The pass.
	 * @param array<string, mixed> $row The stored case.
	 *
	 * @return void
	 */
	private function queueCase(CaseDatingRun $run, array $row): void {
		$date = $this->dateFor(case: $row, now: $run->now);
		$uuid = (string)($row['id'] ?? ($row['uuid'] ?? (($row['@self'] ?? [])['id'] ?? '')));
		if ($date === '' || $uuid === '') {
			$run->tally['skipped']++;
			return;
		}

		$run->todo[$uuid] = $date;
	}//end queueCase()

	/**
	 * Write one queued date, logging and counting a failure.
	 *
	 * @param CaseDatingRun $run  The pass.
	 * @param string        $uuid The case.
	 *
	 * @return void
	 */
	private function writeDate(CaseDatingRun $run, string $uuid): void {
		try {
			$this->patchObjectAsArray(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->schema,
				id: $uuid,
				changes: [CaseActivityStamp::FIELD => $run->todo[$uuid]]
			);
			$run->tally['dated']++;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the lastActivityAt backfill failed for one case',
				['app' => AppIdentity::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			$run->tally['failed']++;
		}
	}//end writeDate()
}//end class
