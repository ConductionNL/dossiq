<?php

/**
 * Repair step: point every planned field inspection at its folded template.
 *
 * Change inspection-checklists-onto-task, task 4.2b. The offline
 * field-inspection leaf now reads `inspectionChecklistTemplate` instead of
 * `inspectionChecklist`, and finds a planned item's template by the id in its
 * `checklistTemplateRef`. A `fieldInspection` planned before the fold still
 * names an `inspectieChecklist` or `inspectionChecklist` uuid, which the leaf
 * would no longer find. This step rewrites that reference to the uuid of the
 * template the fold made from it (matched on the template's `legacyRef`).
 *
 * A reference that already names a template, or that names a source the fold
 * left alone, is not touched: the first needs nothing, the second has no
 * template to point at and is reported. A second run therefore changes
 * nothing. Runs after FoldInspectionChecklistTemplates.
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
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
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
 * Rewrites `fieldInspection.checklistTemplateRef` from an older checklist to its template.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */
class RepointFieldInspectionTemplates implements IRepairStep {
	use SearchesObjects;

	/**
	 * The planned-item schema the offline leaf reads.
	 *
	 * @var string
	 */
	public const PLANNED_SCHEMA = 'fieldInspection';

	/**
	 * The property on a planned item that names its template.
	 *
	 * @var string
	 */
	public const REF_FIELD = 'checklistTemplateRef';

	/**
	 * The `legacyRef` prefixes the fold writes, one per older schema.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_PREFIXES = ['inspectieChecklist/', 'inspectionChecklist/'];

	/**
	 * Page size for each read.
	 *
	 * @var integer
	 */
	private const LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Bridge to OpenRegister and config.
	 * @param LoggerInterface          $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
	 */
	public function getName(): string {
		return 'Point Dossiq planned field inspections at their checklist template';
	}//end getName()

	/**
	 * Repoint every planned inspection that names an older checklist, and report the counts.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
	 */
	public function run(IOutput $output): void {
		$objects = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objects === null || $register === '') {
			$output->warning('OpenRegister or the register is not available. Skipping the field inspection repoint.');
			return;
		}

		$counts = $this->runAsSystemIfAvailable(
			objectService: $objects,
			operation: fn (): array => $this->repointAll(objects: $objects, register: $register, output: $output)
		);

		$output->info(
			sprintf(
				'Field inspection repoint: %d repointed, %d already on a template, %d left for a person to look at.',
				$counts['repointed'],
				$counts['already'],
				$counts['left']
			)
		);
	}//end run()

	/**
	 * Repoint every planned inspection that needs it.
	 *
	 * @param object  $objects  OpenRegister's object service.
	 * @param string  $register The register.
	 * @param IOutput $output   The output.
	 *
	 * @return array{repointed: int, already: int, left: int} The counts.
	 */
	private function repointAll(object $objects, string $register, IOutput $output): array {
		$counts = ['repointed' => 0, 'already' => 0, 'left' => 0];

		$templates = [];
		$byLegacy = [];
		foreach ($this->read(objects: $objects, register: $register, schema: InspectionTemplateMapper::SCHEMA) as $template) {
			$id = self::idOf(object: $template);
			if ($id === '') {
				continue;
			}

			$templates[$id] = true;
			$legacy = trim((string)($template['legacyRef'] ?? ''));
			if ($legacy !== '') {
				$byLegacy[$legacy] = $id;
			}
		}

		foreach ($this->read(objects: $objects, register: $register, schema: self::PLANNED_SCHEMA) as $planned) {
			$ref = trim((string)($planned[self::REF_FIELD] ?? ''));
			$id = self::idOf(object: $planned);
			if ($ref === '' || $id === '') {
				continue;
			}

			if (isset($templates[$ref]) === true) {
				$counts['already']++;
				continue;
			}

			$target = self::folded(byLegacy: $byLegacy, ref: $ref);
			if ($target === null) {
				$counts['left']++;
				$output->warning(sprintf('Field inspection %s names checklist %s, which has no folded template; not repointed.', $id, $ref));
				continue;
			}

			if ($this->patched(objects: $objects, register: $register, id: $id, target: $target, output: $output) === true) {
				$counts['repointed']++;
				continue;
			}

			$counts['left']++;
		}//end foreach

		return $counts;
	}//end repointAll()

	/**
	 * The template folded from an older checklist uuid, or null.
	 *
	 * @param array<string, string> $byLegacy Legacy reference => template uuid.
	 * @param string                $ref      The older checklist uuid.
	 *
	 * @return string|null The template uuid.
	 */
	private static function folded(array $byLegacy, string $ref): ?string {
		foreach (self::LEGACY_PREFIXES as $prefix) {
			if (isset($byLegacy[$prefix . $ref]) === true) {
				return $byLegacy[$prefix . $ref];
			}
		}

		return null;
	}//end folded()

	/**
	 * Write the new reference onto one planned inspection, or log and report why it was refused.
	 *
	 * @param object  $objects  OpenRegister's object service.
	 * @param string  $register The register.
	 * @param string  $id       The planned inspection.
	 * @param string  $target   The template uuid.
	 * @param IOutput $output   The output.
	 *
	 * @return bool Whether it was written.
	 */
	private function patched(object $objects, string $register, string $id, string $target, IOutput $output): bool {
		try {
			$this->patchObjectAsArray(
				objectService: $objects,
				register: $register,
				schema: self::PLANNED_SCHEMA,
				id: $id,
				changes: [self::REF_FIELD => $target]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Field inspection repoint refused', ['fieldInspection' => $id, 'exception' => $e->getMessage()]);
			$output->warning(sprintf('Field inspection %s could not be repointed: %s', $id, $e->getMessage()));
			return false;
		}

		return true;
	}//end patched()

	/**
	 * The id of an object, wherever OpenRegister carries it.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The id, or ''.
	 */
	private static function idOf(array $object): string {
		return (string)($object['@self']['id'] ?? $object['id'] ?? $object['uuid'] ?? '');
	}//end idOf()

	/**
	 * Every object of one schema, or none when the schema is not there.
	 *
	 * @param object $objects  OpenRegister's object service.
	 * @param string $register The register.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function read(object $objects, string $register, string $schema): array {
		try {
			return $this->searchObjectsAsArraysUnscoped(
				objectService: $objects,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::LIMIT]
			);
		} catch (Throwable $e) {
			$this->logger->info('Field inspection repoint: nothing read from ' . $schema, ['exception' => $e->getMessage()]);
			return [];
		}
	}//end read()
}//end class
