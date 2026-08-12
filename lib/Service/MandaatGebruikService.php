<?php

/**
 * Procest MandaatGebruikService.
 *
 * Append-only audit log of mandate uses. Once logged a row is immutable
 * at the application level (the OpenRegister CRUD itself does not enforce
 * this, but updates/deletes are blocked through the controller surface
 * by returning 403 — see {@see MandaatController}).
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Immutable audit log for mandate uses.
 */
class MandaatGebruikService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log a mandate use.
	 *
	 * @param string $zaakId Case id.
	 * @param string $decisionId Decision id.
	 * @param string $mandaatId Mandate id.
	 * @param string $userId User id.
	 * @param array<string, mixed> $roleSnapshot Role snapshot at decision time.
	 * @param array<string, mixed> $conditionsApplied Voorwaarden snapshot.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
	 */
	public function logMandaatGebruik(
		string $zaakId,
		string $decisionId,
		string $mandaatId,
		string $userId,
		array $roleSnapshot = [],
		array $conditionsApplied = [],
	): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_gebruik_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$row = [
			'zaakId' => $zaakId,
			'decisionId' => $decisionId,
			'mandateId' => $mandaatId,
			'userId' => $userId,
			'tijdstip' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
			'rolOpMomentVanBesluit' => $roleSnapshot,
			'gebruikteVoorwaarden' => $conditionsApplied,
			'mandateVersionId' => $mandaatId,
		];

		try {
			$saved = $objectService->saveObject($register, $schema, $row);
			if (is_array($saved) === true) {
				return $saved;
			}

			return $row;
		} catch (\Throwable $e) {
			$this->logger->error('MandaatGebruik log failed', ['zaakId' => $zaakId, 'error' => $e->getMessage()]);
			return $row;
		}
	}//end logMandaatGebruik()

	/**
	 * Retrieve the decision audit trail for a case.
	 *
	 * @param string $zaakId Case id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
	 */
	public function getDecisionAuditTrail(string $zaakId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_gebruik_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['zaakId' => $zaakId]);
		} catch (\Throwable $e) {
			return [];
		}
	}//end getDecisionAuditTrail()

	/**
	 * Retrieve the decisions taken under a mandate in a date range.
	 *
	 * @param string $mandaatId Mandate id.
	 * @param DateTimeImmutable|null $from From (inclusive).
	 * @param DateTimeImmutable|null $until Until (inclusive).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md
	 */
	public function getDecisionByMandaat(string $mandaatId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('mandaat_gebruik_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['mandateId' => $mandaatId]
			);
		} catch (\Throwable $e) {
			return [];
		}

		if ($from === null && $until === null) {
			return $rows;
		}

		return $this->filterByDateRange(rows: $rows, from: $from, until: $until);
	}//end getDecisionByMandaat()

	/**
	 * Keep only the rows whose `tijdstip` day falls inside the supplied (inclusive) bounds.
	 *
	 * A null bound is not applied, so a row is dropped only by a bound that is actually set.
	 *
	 * @param array<int, array<string, mixed>> $rows The mandate-usage rows.
	 * @param DateTimeImmutable|null $from From (inclusive).
	 * @param DateTimeImmutable|null $until Until (inclusive).
	 *
	 * @return array<int, array<string, mixed>> The rows within the range.
	 */
	private function filterByDateRange(array $rows, ?DateTimeImmutable $from, ?DateTimeImmutable $until): array {
		$out = [];
		foreach ($rows as $row) {
			$when = substr((string)($row['tijdstip'] ?? ''), 0, 10);
			if ($from !== null && $when < $from->format('Y-m-d')) {
				continue;
			}

			if ($until !== null && $when > $until->format('Y-m-d')) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end filterByDateRange()
}//end class
