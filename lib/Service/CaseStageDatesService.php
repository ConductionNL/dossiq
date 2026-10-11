<?php

/**
 * Dossiq Case Stage Dates Service
 *
 * The day a case first reached each of its statuses, read from the case's own
 * statusRecord chain. The stage bar of the case page (DqZaak) draws it under
 * each step; the case type's blueprint cannot say it, because a blueprint
 * describes the TYPE and a reached date belongs to one CASE.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Reads when one case reached each status.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseStageDatesService {
	use SearchesObjects;

	/**
	 * Upper bound on the status moves read for one case.
	 *
	 * @var integer
	 */
	private const RECORD_CAP = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The OpenRegister bridge and config.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The first day each status was reached on one case.
	 *
	 * Empty when the case has no moves yet or the store cannot be read: a
	 * stage bar without dates is the honest answer to both.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, string> Y-m-d, keyed by statusType id.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function reachedDatesFor(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'status_record_schema');
		if ($caseId === '' || $objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$records = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['case' => $caseId, '_limit' => self::RECORD_CAP],
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[CaseStageDatesService] The status moves of a case could not be read',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->foldRecords(records: $records);
	}//end reachedDatesFor()

	/**
	 * Fold status moves into the first day each status was reached.
	 *
	 * A status the case came back to keeps its FIRST day: the bar says when
	 * the case got there, not when it last did.
	 *
	 * @param array<int, array<string, mixed>> $records The statusRecord rows.
	 *
	 * @return array<string, string> Y-m-d, keyed by statusType id.
	 */
	public function foldRecords(array $records): array {
		$first = [];
		foreach ($records as $record) {
			$status = (string)($record['statusType'] ?? '');
			$at = (string)($record['createdAt'] ?? ($record['@self']['createdAt'] ?? ($record['@self']['created'] ?? '')));
			$day = substr($at, 0, 10);
			if ($status === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
				continue;
			}

			if (isset($first[$status]) === false || strcmp($day, $first[$status]) < 0) {
				$first[$status] = $day;
			}
		}

		return $first;
	}//end foldRecords()
}//end class
