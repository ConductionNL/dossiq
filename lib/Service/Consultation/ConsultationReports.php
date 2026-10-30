<?php

/**
 * Dossiq consultation reports.
 *
 * The two Adviseringsbeheer reports over the stored advice requests: the
 * waiting requests due within the warning offset, and per advisory body the
 * late share against the bottleneck threshold.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/consultation-management/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

/**
 * Reads the due-soon list and the bottleneck report.
 *
 * @spec openspec/specs/consultation-management/spec.md
 */
class ConsultationReports {

	/**
	 * Constructor.
	 *
	 * @param ConsultationRepository $repository The stored advice requests.
	 * @param ConsultationTerms      $terms      The Adviseringsbeheer settings, applied.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConsultationRepository $repository,
		private readonly ConsultationTerms $terms,
	) {
	}//end __construct()

	/**
	 * The waiting consultations whose deadline is within the warning offset.
	 *
	 * @param callable|null $mayRead Keeps a request the caller may see; asked of each before the list is built.
	 *
	 * @return array{results: array<int, array<string, mixed>>, warningOffsetDays: int} The list and the offset used.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advice-requests-due-within-the-warning-offset-are-listed
	 */
	public function getDueSoonConsultations(?callable $mayRead = null): array {
		$waiting = $this->repository->getWaitingConsultations();
		if ($mayRead !== null) {
			$waiting = array_values(array_filter($waiting, $mayRead));
		}

		return [
			'results' => $this->terms->dueSoon(consultations: $waiting),
			'warningOffsetDays' => $this->terms->warningOffsetDays(),
		];
	}//end getDueSoonConsultations()

	/**
	 * Per advisory body, the late share over the last 30 days against the bottleneck threshold.
	 *
	 * @return array{threshold: float, windowDays: int, bodies: array<int, array<string, mixed>>} The report.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advisory-bodies-past-the-bottleneck-threshold-are-flagged
	 */
	public function getBottlenecks(): array {
		return $this->terms->bottlenecks(consultations: $this->repository->getRecentConsultations(days: ConsultationTerms::BOTTLENECK_WINDOW_DAYS));
	}//end getBottlenecks()
}//end class
