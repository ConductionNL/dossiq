<?php

/**
 * Dossiq reconcile case deadlines with terms repair step.
 *
 * One term engine (REQ-OTE-08). Before it, a case's `deadline` was an
 * OpenRegister calculation that ignored the statutory term: a paused, an
 * extended or a rolled term left the list showing a date the case page did
 * not. From now on the term writes the case, but every case saved before that
 * still carries the calculated date. This step writes each case's deadline
 * from its statutory term, once, through the same mirror the term engine uses.
 *
 * Idempotent: a case that already agrees is counted unchanged and not
 * written. Never fails the upgrade: an unreadable store is a warning, and a
 * case that could not be written is counted as failed and logged.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\TermKind;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes every case's deadline from its statutory term, once.
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */
class ReconcileCaseDeadlinesWithTerms implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The most term instances one run reads.
	 */
	private const PAGE = 10000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Settings and ObjectService access.
	 * @param CaseDeadlineMirror $mirror          The write the term engine makes.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDeadlineMirror $mirror,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
	 */
	public function getName(): string {
		return 'Write every Dossiq case deadline from its statutory term';
	}//end getName()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping the case deadline repair.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$caseIds = null;
		$counts = ['followed' => 0, 'unchanged' => 0, 'failed' => 0];

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use (&$caseIds, &$counts): void {
				$caseIds = $this->casesWithAStatutoryTerm();
				if ($caseIds === null) {
					return;
				}

				foreach ($caseIds as $caseId) {
					$this->reconcile(caseId: $caseId, counts: $counts);
				}
			}
		);

		if ($caseIds === null) {
			$output->warning('Term instances not readable (schemas unconfigured, or the read was refused). Skipping the case deadline repair.');
			return;
		}

		$this->logger->info('Dossiq case deadline repair complete', $counts);
		$output->info(
			sprintf(
				'Case deadlines from their statutory terms: %d written, %d already right, %d failed.',
				$counts['followed'],
				$counts['unchanged'],
				$counts['failed']
			)
		);
	}//end run()

	/**
	 * Reconcile one case.
	 *
	 * @param string             $caseId The case uuid.
	 * @param array<string, int> $counts Running counts (by reference).
	 *
	 * @return void
	 */
	private function reconcile(string $caseId, array &$counts): void {
		try {
			if ($this->mirror->follow(caseId: $caseId) === true) {
				$counts['followed']++;
				return;
			}

			$counts['unchanged']++;
		} catch (Throwable $e) {
			$counts['failed']++;
			$this->logger->warning(
				'Dossiq case deadline repair: a case could not be reconciled',
				['case' => $caseId, 'error' => $e->getMessage()]
			);
		}
	}//end reconcile()

	/**
	 * The distinct cases that carry a statutory term, or null when the store is unreachable.
	 *
	 * @return array<int, string>|null The case uuids.
	 */
	private function casesWithAStatutoryTerm(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			// A page as large as the step will meet, not the default page: a repair
			// that silently stopped after the first twenty cases would report
			// green over every case after them.
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE]
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq case deadline repair: listing the terms failed', ['error' => $e->getMessage()]);
			return null;
		}

		$caseIds = [];
		foreach ($rows as $row) {
			$caseId = trim((string)($row['case'] ?? ''));
			if ($caseId === '' || TermKind::ofInstance($row) !== TermKind::STATUTORY) {
				continue;
			}

			$caseIds[$caseId] = true;
		}

		return array_map(strval(...), array_keys($caseIds));
	}//end casesWithAStatutoryTerm()
}//end class
