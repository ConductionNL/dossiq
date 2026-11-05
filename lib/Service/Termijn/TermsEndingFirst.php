<?php

/**
 * Dossiq TermsEndingFirst.
 *
 * The running statutory terms that end first, each named by its case, with
 * the share of the term that has passed and the days left (board
 * DqTermijnen, "Verloopt het eerst").
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermKind;
use Throwable;

/**
 * Lists the statutory terms that end first.
 *
 * Progress and days left come from {@see CaseTermsService::standingOf()}, so
 * this list and the case page count a term the same way, and neither is stored.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TermKind is a vocabulary of pure predicates.
 */
class TermsEndingFirst {
	use SearchesObjects;

	public const DEFAULT_LIMIT = 5;
	public const MAX_LIMIT = 25;

	/**
	 * The statuses of a term that is still running.
	 *
	 * @var list<string>
	 */
	private const RUNNING = ['lopend', 'verlengd', 'paused'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService The app settings, for the object service and the schema ids.
	 * @param CaseTermsService $terms           Counts progress and days left as the case page does.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTermsService $terms,
	) {
	}//end __construct()

	/**
	 * The running statutory terms, soonest end first.
	 *
	 * @param int                    $limit How many to answer, clamped to 1..MAX_LIMIT.
	 * @param DateTimeImmutable|null $now   The moment to judge against.
	 *
	 * @return array{available: bool, terms: list<array<string, mixed>>}
	 *
	 * @throws Throwable When the terms cannot be read; an empty list would read "nothing is running".
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/termijn-reporting/spec.md#requirement-the-term-dashboard-lists-the-terms-that-end-first
	 */
	public function list(int $limit = self::DEFAULT_LIMIT, ?DateTimeImmutable $now = null): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['available' => false, 'terms' => []];
		}

		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$running = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'status' => ['in' => self::RUNNING],
				'_order' => ['endDateCurrent' => 'ASC'],
				'_limit' => (self::MAX_LIMIT * 4),
			]
		);

		$rows = [];
		foreach ($running as $instance) {
			if ($this->isRunningStatutory(instance: $instance) === true) {
				$rows[] = $this->terms->standingOf(instance: $instance, now: $now) + ['case' => (string)($instance['case'] ?? '')];
			}
		}

		usort($rows, static fn (array $a, array $b): int => strcmp($a['endDate'], $b['endDate']));
		$rows = array_slice($rows, 0, $limit);

		return [
			'available' => true,
			'terms' => array_map(fn (array $row): array => $this->named(objectService: $objectService, register: $register, row: $row), $rows),
		];
	}//end list()

	/**
	 * Whether a row is a statutory term that is still running and has an end.
	 *
	 * @param array<string, mixed> $instance The term instance.
	 *
	 * @return bool
	 */
	private function isRunningStatutory(array $instance): bool {
		$end = (string)($instance['endDateCurrent'] ?? ($instance['endDateCalculated'] ?? ''));

		return $end !== ''
			&& TermKind::ofInstance($instance) === TermKind::STATUTORY
			&& in_array((string)($instance['status'] ?? ''), self::RUNNING, true) === true;
	}//end isRunningStatutory()

	/**
	 * One row named by its case.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register id.
	 * @param array<string, mixed> $row           The term's standing and its case id.
	 *
	 * @return array{caseId: string, caseLabel: string, endDate: string, progressPercent: int, daysLeft: int}
	 */
	private function named(object $objectService, string $register, array $row): array {
		$case = [];
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($row['case'] !== '' && $schema !== '') {
			$case = ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $row['case']) ?? []);
		}

		return [
			'caseId' => $row['case'],
			'caseLabel' => trim((string)($case['identifier'] ?? '').' '.(string)($case['title'] ?? '')),
			'endDate' => $row['endDate'],
			'progressPercent' => $row['progressPercent'],
			'daysLeft' => $row['daysLeft'],
		];
	}//end named()
}//end class
