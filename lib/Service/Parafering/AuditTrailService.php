<?php

/**
 * Parafering Audit Trail Service (read-only, historical)
 *
 * Exports the historical, append-only parafering audit trail for Archiefwet
 * handover. As of `migrate-parafering-to-or-audit` (ADR-022,
 * `consume-or-audit-trail-fleet-wide`), NEW parafering transitions are recorded
 * through OpenRegister's native hash-chained audit trail by
 * {@see \OCA\Procest\Listener\ParaferingAuditListener} — NOT through this
 * service. This service is retained ONLY to read and export the deprecated
 * `paraferingAuditEntry` rows that pre-date the migration, until the schema's
 * sunset (one major release later). It performs no writes.
 *
 * @category Service
 * @package  OCA\Procest\Service\Parafering
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/parafering-audit-via-or/spec.md
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Parafering;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * AuditTrailService — exports the historical parafering audit trail for
 * Archiefwet handover. Read-only: new transitions go through OR's audit trail.
 */
class AuditTrailService {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Procest settings bridge (provides ObjectService + config keys)
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Export the full historical audit trail for a voorstel as an
	 * Archiefwet-aligned envelope.
	 *
	 * Reads the deprecated `paraferingAuditEntry` rows that pre-date the OR
	 * audit-trail migration. New transitions are discoverable via OR's
	 * audit-trail-immutable API (`GET /api/audit-trails?objectUuid={voorstelId}`).
	 *
	 * @param string $proposalId The voorstel UUID/slug
	 * @param string $proposalOnderwerp Voorstel onderwerp (for the metadata block)
	 * @param string $exportedBy UID of the auditor performing the export
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When configuration is missing
	 *
	 * @spec openspec/specs/parafering-audit-via-or/spec.md
	 */
	public function export(string $proposalId, string $proposalOnderwerp, string $exportedBy): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('parafering_audit_entry_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('paraferingAuditEntry configuration is missing');
		}

		$results = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['voorstel' => $proposalId, '_limit' => 5000],
		);

		$entries = [];
		if (is_array($results) === true) {
			foreach ($results as $row) {
				$entries[] = $this->toArray(value: $row);
			}
		}

		usort(
			$entries,
			static function (array $a, array $b): int {
				return strcmp((string)($a['timestamp'] ?? ''), (string)($b['timestamp'] ?? ''));
			},
		);

		$completed = null;
		foreach ($entries as $entry) {
			if (($entry['action'] ?? '') === 'completed') {
				$completed = $entry;
				break;
			}
		}

		$retentionUntil = $this->computeRetentionUntil(completedEntry: $completed);

		$selectielijst = 'Algemene administratieve correspondentie — bewaartermijn 7 jaar';
		if ($completed !== null) {
			$selectielijst = 'Bestuurlijke besluitvorming — bewaartermijn 20 jaar';
		}

		return [
			'metadata' => [
				'schema' => 'MDTO 1.0',
				'exportedAt' => (new DateTimeImmutable('now'))
					->setTimezone(new DateTimeZone('UTC'))
					->format('Y-m-d\TH:i:s\Z'),
				'voorstel' => $proposalId,
				'voorstelOnderwerp' => $proposalOnderwerp,
				'retentionUntil' => $retentionUntil,
				'selectielijstCategory' => $selectielijst,
				'exportedBy' => $exportedBy,
				'entryCount' => count($entries),
			],
			'entries' => $entries,
		];
	}//end export()

	/**
	 * Compute the retentionUntil date.
	 *
	 * @param array<string, mixed>|null $completedEntry The completed audit entry (or null)
	 *
	 * @return string ISO 8601 date
	 */
	private function computeRetentionUntil(?array $completedEntry): string {
		try {
			if ($completedEntry !== null && isset($completedEntry['timestamp']) === true) {
				$base = new DateTimeImmutable((string)$completedEntry['timestamp']);
				return $base->modify('+20 years')->format('Y-m-d');
			}

			return (new DateTimeImmutable('now'))->modify('+7 years')->format('Y-m-d');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest: failed to compute parafering audit retention date',
				['exception' => $e->getMessage()],
			);

			return (new DateTimeImmutable('now'))->modify('+7 years')->format('Y-m-d');
		}
	}//end computeRetentionUntil()

	/**
	 * Best-effort conversion of any ObjectService return to a plain array.
	 *
	 * @param mixed $value The returned object/array
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				$serialized = $value->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}

			if (method_exists($value, 'toArray') === true) {
				$arr = $value->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$value;
		}

		return [];
	}//end toArray()
}//end class
