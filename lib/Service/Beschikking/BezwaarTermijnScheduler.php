<?php

/**
 * Dossiq BezwaarTermijnScheduler.
 *
 * Owns the bezwaartermijn that starts the moment a beschikking is made
 * public: the six-week objection period of Awb 6:7, the reminder one week
 * before it lapses, and the archive trigger the day after. Computing those
 * dates and persisting the BezwaarTrigger scheduling record are one
 * responsibility — a termijn that is calculated but never scheduled would
 * silently drop a statutory deadline.
 *
 * Split out of BeschikkingService so that service keeps only the lifecycle
 * orchestration. Persisting the trigger is best-effort: a failure is logged
 * but does not roll back the verzending, because the beschikking has already
 * been delivered to the citizen by then.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use Psr\Log\LoggerInterface;

/**
 * Computes and schedules the Awb 6:7 bezwaartermijn of a beschikking.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class BezwaarTermijnScheduler {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings/config service.
	 * @param LoggerInterface $logger The logger.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; the
	 *        bezwaartermijn rolls on the administered calendar through it.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
	) {
	}//end __construct()

	/**
	 * Compute the bezwaartermijn end date and its reminder date.
	 *
	 * Six weeks from bekendmaking (Awb 6:7), reminder one week before. Six weeks
	 * is the term; where it LANDS is Algemene termijnenwet art. 1, so both dates
	 * go through the calendar the organisation administers. The reminder rolls
	 * too: one that falls on Tweede Kerstdag reaches nobody.
	 *
	 * @param string $bekendmaking The bekendmaking date (Y-m-d).
	 * @param array<string, mixed> $definitie The term definition, when one is known;
	 *        `rollToWorkingDay` false returns the raw dates.
	 *
	 * @return array{endDate: string, herinnering: string} Both as `Y-m-d`.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function computeTermijn(string $bekendmaking, array $definitie = []): array {
		$endDate = (new DateTimeImmutable($bekendmaking))->add(new DateInterval('P6W'));
		$herinnering = $endDate->sub(new DateInterval('P1W'));

		return [
			'endDate' => ($this->timerService?->rollTermEndFor(date: $endDate, definitie: $definitie) ?? $endDate)->format('Y-m-d'),
			'herinnering' => ($this->timerService?->rollTermEndFor(date: $herinnering, definitie: $definitie) ?? $herinnering)->format('Y-m-d'),
		];
	}//end computeTermijn()

	/**
	 * Create the BezwaarTrigger scheduling record on verzending.
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $bekendmaking The bekendmaking date.
	 * @param string $endDate The bezwaartermijn end date.
	 * @param string $herinnering The reminder date.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function createBezwaarTrigger(
		string $decisionId,
		string $bekendmaking,
		string $endDate,
		string $herinnering,
	): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'bezwaar_trigger_schema');
		if ($register === '' || $schema === '') {
			return;
		}

		$archiveDate = (new DateTimeImmutable($endDate))->add(new DateInterval('P1D'))->format('Y-m-d');

		try {
			$objectService->saveObject(
				register: $register,
				schema: $schema,
				object: [
					'decisionId' => $decisionId,
					'announcementDate' => $bekendmaking,
					'objectionTermEndDate' => $endDate,
					'reminderDate' => $herinnering,
					'objectionReceived' => false,
					'archiveTriggerActive' => true,
					'archiveDate' => $archiveDate,
				],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BeschikkingService: createBezwaarTrigger failed',
				['exception' => $e->getMessage(), 'decisionId' => $decisionId],
			);
		}
	}//end createBezwaarTrigger()
}//end class
