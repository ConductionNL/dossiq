<?php

/**
 * Procest BezwaarTermijnScheduler.
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
 * @package  OCA\Procest\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use DateInterval;
use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
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
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the bezwaartermijn end date and its reminder date.
	 *
	 * Six weeks from bekendmaking (Awb 6:7), reminder one week before.
	 *
	 * @param string $bekendmaking The bekendmaking date (Y-m-d).
	 *
	 * @return array{eindDatum: string, herinnering: string} Both as `Y-m-d`.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function computeTermijn(string $bekendmaking): array {
		$eindDatum = (new DateTimeImmutable($bekendmaking))->add(new DateInterval('P6W'));
		$herinnering = $eindDatum->sub(new DateInterval('P1W'));

		return [
			'eindDatum' => $eindDatum->format('Y-m-d'),
			'herinnering' => $herinnering->format('Y-m-d'),
		];
	}//end computeTermijn()

	/**
	 * Create the BezwaarTrigger scheduling record on verzending.
	 *
	 * @param string $beschikkingId The beschikking UUID.
	 * @param string $bekendmaking The bekendmaking date.
	 * @param string $eindDatum The bezwaartermijn end date.
	 * @param string $herinnering The reminder date.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function createBezwaarTrigger(
		string $beschikkingId,
		string $bekendmaking,
		string $eindDatum,
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

		$archiefDatum = (new DateTimeImmutable($eindDatum))->add(new DateInterval('P1D'))->format('Y-m-d');

		try {
			$objectService->saveObject(
				register: $register,
				schema: $schema,
				object: [
					'beschikkingId' => $beschikkingId,
					'bekendmakingDatum' => $bekendmaking,
					'bezwaarTermijnEindDatum' => $eindDatum,
					'herinneringDatum' => $herinnering,
					'bezwaarOntvangen' => false,
					'archiefTriggerActief' => true,
					'archiefDatum' => $archiefDatum,
				],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BeschikkingService: createBezwaarTrigger failed',
				['exception' => $e->getMessage(), 'beschikkingId' => $beschikkingId],
			);
		}
	}//end createBezwaarTrigger()
}//end class
