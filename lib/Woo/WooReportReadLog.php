<?php

/**
 * Dossiq Woo review reports: every read of the throughput report is recorded.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one entry on OpenRegister's audit trail of each Woo case a throughput read covered.
 *
 * The throughput report is a per-person figure, so a read of it is a read of
 * personal data and is recorded where dossiq records its other acts on a
 * case: OpenRegister's hash-chained audit trail, anchored to the case. The
 * entry holds the reader, the time and the scope (one case, or a period).
 *
 * It fails closed. When an entry cannot be written, the read is refused:
 * an unrecorded read of these numbers is exactly what the report may not do.
 * A read that covered no case answered no one's numbers, so there is nothing
 * to anchor and nothing to refuse.
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
 */
class WooReportReadLog {

	/**
	 * The audit action of a throughput read.
	 */
	public const ACTION_THROUGHPUT = 'dossiq.woo.report.throughput-read';

	/**
	 * OpenRegister's audit trail mapper.
	 */
	private const AUDIT_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings and OpenRegister access.
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
	 * Record a throughput read on every case it covered.
	 *
	 * @param string $readerId The user who read.
	 * @param array<string, string> $scope What was read: `case`, or `from` and `to`.
	 * @param list<string> $caseIds The Woo cases whose assessments the answer counted.
	 *
	 * @return int The number of entries written.
	 *
	 * @throws RefusedException When an entry cannot be written.
	 *
	 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-throughput-per-reviewer-per-day-read-by-the-named-group-only-req-wrr-002
	 */
	public function recordThroughputRead(string $readerId, array $scope, array $caseIds): int {
		$caseIds = array_values(array_unique(array_filter($caseIds, static fn (string $id): bool => $id !== '')));
		if ($caseIds === []) {
			return 0;
		}

		$objectService = $this->settingsService->getObjectService();
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::AUDIT_MAPPER);
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $mapper === null || $register === '' || $schema === '') {
			throw $this->notRecorded(previous: null);
		}

		$context = [
			'reader' => $readerId,
			'at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'scope' => $scope,
		];
		$written = 0;
		foreach ($caseIds as $caseId) {
			try {
				$case = $objectService->find($caseId, register: $register, schema: $schema);
				$mapper->createAuditTrailEntry(
					object: $case,
					action: self::ACTION_THROUGHPUT,
					context: $context,
					actorId: $readerId,
					actorName: $readerId,
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: a Woo throughput read could not be recorded, so it is refused',
					['app' => Application::APP_ID, 'case' => $caseId, 'exception' => $e->getMessage()]
				);
				throw $this->notRecorded(previous: $e);
			}

			$written++;
		}//end foreach

		return $written;
	}//end recordThroughputRead()

	/**
	 * The refusal when a read cannot be recorded.
	 *
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException The refusal.
	 */
	private function notRecorded(?Throwable $previous): RefusedException {
		return RefusedException::indeterminate(
			rule: 'woo-throughput-not-recorded',
			sentence: 'This read cannot be recorded, so the report is not shown.',
			previous: $previous,
		);
	}//end notRecorded()
}//end class
