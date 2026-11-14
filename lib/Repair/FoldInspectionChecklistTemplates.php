<?php

/**
 * Repair step: fold the two older checklist template schemas into the one.
 *
 * Change inspection-checklists-onto-task, task 4.1 (design D1). Every `inspectieChecklist`
 * (stack A) and every `inspectionChecklist` with its `checklistItem` rows
 * (stack C) becomes one `inspectionChecklistTemplate` carrying `legacyRef`.
 * A source whose `legacyRef` is already on a template is skipped, so a second
 * run creates nothing. A stack C checklist naming an item row that cannot be
 * read is reported and left alone, never folded half.
 *
 * The sources are not deleted here: step 5 retires their schemas one release
 * later.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Inspection\InspectionTemplateMapper;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Folds stack A and stack C checklists into `inspectionChecklistTemplate`.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */
class FoldInspectionChecklistTemplates implements IRepairStep {
	use SearchesObjects;

	/**
	 * Page size for each read.
	 *
	 * @var integer
	 */
	private const LIMIT = 1000;

	/**
	 * Maps every source shape onto the template.
	 *
	 * @var InspectionTemplateMapper
	 */
	private InspectionTemplateMapper $mapper;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Bridge to OpenRegister and config.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
		$this->mapper = new InspectionTemplateMapper();
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function getName(): string {
		return 'Fold Dossiq inspection checklists into the one template schema';
	}//end getName()

	/**
	 * Fold every source not folded yet, and report the counts.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function run(IOutput $output): void {
		$objects = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objects === null || $register === '') {
			$output->warning('OpenRegister or the register is not available. Skipping the checklist template fold.');
			return;
		}

		$run = new ChecklistFoldRun(objects: $objects, register: $register);
		$run->output = $output;
		$this->runAsSystemIfAvailable(
			objectService: $objects,
			operation: fn () => $this->foldAll(run: $run)
		);

		$this->report(run: $run);
	}//end run()

	/**
	 * Say what the fold did and what it left.
	 *
	 * @param ChecklistFoldRun $run The fold.
	 *
	 * @return void
	 */
	private function report(ChecklistFoldRun $run): void {
		$run->output->info(
			sprintf(
				'Checklist template fold: %d folded, %d already folded, %d left for a person to look at.',
				$run->counts['folded'],
				$run->counts['already'],
				$run->counts['unreadable']
			)
		);
	}//end report()

	/**
	 * Fold every candidate that is not folded yet.
	 *
	 * @param ChecklistFoldRun $run The fold.
	 *
	 * @return void
	 */
	private function foldAll(ChecklistFoldRun $run): void {
		$run->folded = $this->foldedRefs(run: $run);
		foreach ($this->candidates(run: $run) as $candidate) {
			$this->foldOne(run: $run, candidate: $candidate);
		}
	}//end foldAll()

	/**
	 * Every source mapped onto a template, with what could not be read.
	 *
	 * @param ChecklistFoldRun $run The fold.
	 *
	 * @return array<int, array{template: array<string, mixed>, unresolved: array<int, string>}> The candidates.
	 */
	private function candidates(ChecklistFoldRun $run): array {
		$candidates = [];
		foreach ($this->read(run: $run, schema: 'inspectieChecklist') as $checklist) {
			$candidates[] = ['template' => $this->mapper->fromStackA(checklist: $checklist), 'unresolved' => []];
		}

		$rows = [];
		foreach ($this->read(run: $run, schema: 'checklistItem') as $row) {
			$rows[(string)($row['@self']['id'] ?? $row['id'] ?? '')] = $row;
		}

		foreach ($this->read(run: $run, schema: 'inspectionChecklist') as $checklist) {
			$candidates[] = $this->mapper->fromStackC(checklist: $checklist, rows: $rows);
		}

		return $candidates;
	}//end candidates()

	/**
	 * Fold one candidate, unless it is folded or cannot be read whole.
	 *
	 * @param ChecklistFoldRun                                                     $run       The fold.
	 * @param array{template: array<string, mixed>, unresolved: array<int, string>} $candidate The mapped source.
	 *
	 * @return void
	 */
	private function foldOne(ChecklistFoldRun $run, array $candidate): void {
		$template = $candidate['template'];
		$ref = (string)$template['legacyRef'];
		if (isset($run->folded[$ref]) === true) {
			$run->counts['already']++;
			return;
		}

		if ($candidate['unresolved'] !== []) {
			$this->leaveUnreadable(run: $run, candidate: $candidate);
			return;
		}

		if ($this->saved(run: $run, template: $template) === false) {
			return;
		}

		$run->folded[$ref] = true;
		$run->counts['folded']++;
	}//end foldOne()

	/**
	 * Count and report a candidate whose item rows cannot all be read.
	 *
	 * @param ChecklistFoldRun                                                     $run       The fold.
	 * @param array{template: array<string, mixed>, unresolved: array<int, string>} $candidate The mapped source.
	 *
	 * @return void
	 */
	private function leaveUnreadable(ChecklistFoldRun $run, array $candidate): void {
		$run->counts['unreadable']++;
		$run->output->warning(
			sprintf(
				'Checklist %s names item rows that cannot be read (%s); not folded.',
				(string)$candidate['template']['legacyRef'],
				implode(', ', $candidate['unresolved'])
			)
		);
	}//end leaveUnreadable()

	/**
	 * Save one template, or count, log and report why the store refused it.
	 *
	 * @param ChecklistFoldRun     $run      The fold.
	 * @param array<string, mixed> $template The template, with its legacy reference.
	 *
	 * @return bool Whether it was saved.
	 */
	private function saved(ChecklistFoldRun $run, array $template): bool {
		$ref = (string)$template['legacyRef'];
		try {
			$this->saveObjectAsArray(
				objectService: $run->objects,
				register: $run->register,
				schema: InspectionTemplateMapper::SCHEMA,
				object: $template
			);
		} catch (Throwable $e) {
			$run->counts['unreadable']++;
			$this->logger->warning('Checklist template fold refused', ['source' => $ref, 'exception' => $e->getMessage()]);
			$run->output->warning(sprintf('Checklist %s could not be folded: %s', $ref, $e->getMessage()));
			return false;
		}

		return true;
	}//end saved()

	/**
	 * The legacy references already on a template.
	 *
	 * @param ChecklistFoldRun $run The fold.
	 *
	 * @return array<string, bool> Reference => true.
	 */
	private function foldedRefs(ChecklistFoldRun $run): array {
		$refs = [];
		foreach ($this->read(run: $run, schema: InspectionTemplateMapper::SCHEMA) as $template) {
			$ref = trim((string)($template['legacyRef'] ?? ''));
			if ($ref !== '') {
				$refs[$ref] = true;
			}
		}

		return $refs;
	}//end foldedRefs()

	/**
	 * Every object of one schema, or none when the schema is not there.
	 *
	 * @param ChecklistFoldRun $run    The fold.
	 * @param string           $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function read(ChecklistFoldRun $run, string $schema): array {
		try {
			return $this->searchObjectsAsArraysUnscoped(
				objectService: $run->objects,
				register: $run->register,
				schema: $schema,
				filters: ['_limit' => self::LIMIT]
			);
		} catch (Throwable $e) {
			$this->logger->info('Checklist template fold: nothing read from ' . $schema, ['exception' => $e->getMessage()]);
			return [];
		}
	}//end read()
}//end class
