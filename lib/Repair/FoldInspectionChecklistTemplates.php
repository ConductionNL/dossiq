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

use OCA\Dossiq\Service\Inspection\InspectionTemplateMapper;
use OCA\Dossiq\Service\SettingsService;
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
	 * @param SettingsService $settingsService Bridge to OpenRegister and config.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
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

		$counts = ['folded' => 0, 'already' => 0, 'unreadable' => 0];
		$this->runAsSystemIfAvailable(
			objectService: $objects,
			operation: function () use ($objects, $register, &$counts, $output): void {
				$folded = $this->foldedRefs(objects: $objects, register: $register);
				foreach ($this->candidates(objects: $objects, register: $register) as $candidate) {
					$this->foldOne(objects: $objects, register: $register, candidate: $candidate, folded: $folded, counts: $counts, output: $output);
				}
			}
		);

		$output->info(
			sprintf(
				'Checklist template fold: %d folded, %d already folded, %d left for a person to look at.',
				$counts['folded'],
				$counts['already'],
				$counts['unreadable']
			)
		);
	}//end run()

	/**
	 * Every source mapped onto a template, with what could not be read.
	 *
	 * @param object $objects  The ObjectService.
	 * @param string $register The register id.
	 *
	 * @return array<int, array{template: array<string, mixed>, unresolved: array<int, string>}> The candidates.
	 */
	private function candidates(object $objects, string $register): array {
		$candidates = [];
		foreach ($this->read(objects: $objects, register: $register, schema: 'inspectieChecklist') as $checklist) {
			$candidates[] = ['template' => $this->mapper->fromStackA(checklist: $checklist), 'unresolved' => []];
		}

		$rows = [];
		foreach ($this->read(objects: $objects, register: $register, schema: 'checklistItem') as $row) {
			$rows[(string)($row['@self']['id'] ?? $row['id'] ?? '')] = $row;
		}

		foreach ($this->read(objects: $objects, register: $register, schema: 'inspectionChecklist') as $checklist) {
			$candidates[] = $this->mapper->fromStackC(checklist: $checklist, rows: $rows);
		}

		return $candidates;
	}//end candidates()

	/**
	 * Fold one candidate, unless it is folded or cannot be read whole.
	 *
	 * @param object                                                               $objects   The ObjectService.
	 * @param string                                                               $register  The register id.
	 * @param array{template: array<string, mixed>, unresolved: array<int, string>} $candidate The mapped source.
	 * @param array<string, bool>                                                  $folded    Legacy references already on a template.
	 * @param array<string, int>                                                   $counts    The running counts.
	 * @param IOutput                                                              $output    The output.
	 *
	 * @return void
	 */
	private function foldOne(object $objects, string $register, array $candidate, array &$folded, array &$counts, IOutput $output): void {
		$template = $candidate['template'];
		$ref = (string)$template['legacyRef'];
		if (isset($folded[$ref]) === true) {
			$counts['already']++;
			return;
		}

		if ($candidate['unresolved'] !== []) {
			$counts['unreadable']++;
			$output->warning(sprintf('Checklist %s names item rows that cannot be read (%s); not folded.', $ref, implode(', ', $candidate['unresolved'])));
			return;
		}

		try {
			$this->saveObjectAsArray(objectService: $objects, register: $register, schema: InspectionTemplateMapper::SCHEMA, object: $template);
		} catch (Throwable $e) {
			$counts['unreadable']++;
			$this->logger->warning('Checklist template fold refused', ['source' => $ref, 'exception' => $e->getMessage()]);
			$output->warning(sprintf('Checklist %s could not be folded: %s', $ref, $e->getMessage()));
			return;
		}

		$folded[$ref] = true;
		$counts['folded']++;
	}//end foldOne()

	/**
	 * The legacy references already on a template.
	 *
	 * @param object $objects  The ObjectService.
	 * @param string $register The register id.
	 *
	 * @return array<string, bool> Reference => true.
	 */
	private function foldedRefs(object $objects, string $register): array {
		$refs = [];
		foreach ($this->read(objects: $objects, register: $register, schema: InspectionTemplateMapper::SCHEMA) as $template) {
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
	 * @param object $objects  The ObjectService.
	 * @param string $register The register id.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function read(object $objects, string $register, string $schema): array {
		try {
			return $this->searchObjectsAsArraysUnscoped(objectService: $objects, register: $register, schema: $schema, filters: ['_limit' => self::LIMIT]);
		} catch (Throwable $e) {
			$this->logger->info('Checklist template fold: nothing read from ' . $schema, ['exception' => $e->getMessage()]);
			return [];
		}
	}//end read()
}//end class
