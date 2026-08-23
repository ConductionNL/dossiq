<?php

/**
 * Dossiq Seed VTH Workflow Templates Repair Step
 *
 * Repair step that seeds six canonical VTH (Vergunningen, Toezicht &
 * Handhaving) workflow templates as published `workflowTemplate` v1 objects
 * via `WorkflowDefinitionService::createDraft()` + `publish()`. Idempotent
 * on re-run.
 *
 * The repair step routes every mutation of a `workflowTemplate` through
 * `WorkflowDefinitionService` to respect the immutability invariant of
 * published rows established by `workflow-definition-model`. It NEVER
 * writes `workflowTemplate` rows directly through `ObjectService`.
 *
 * Soft-deps on `base-register-seed-data`: when a caseType slug cannot be
 * resolved, the template is logged + skipped (warning only), and the rest
 * of the catalog continues.
 *
 * This class is orchestration only. The OpenRegister reads live in
 * {@see \OCA\Dossiq\Repair\Vth\VthSeedLookup} and the steps/transitions
 * translation in {@see \OCA\Dossiq\Repair\Vth\VthWorkflowGraphResolver}.
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
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Vth\VthSeedLookup;
use OCA\Dossiq\Repair\Vth\VthWorkflowGraphResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds six canonical VTH workflow templates.
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class SeedVthWorkflowTemplates implements IRepairStep {

	/**
	 * Catalog directory relative to lib/.
	 */
	private const CATALOG_DIR = __DIR__ . '/../Settings/seed/vth-workflow-templates';

	/**
	 * Memoised template slug → caseType UUID map, built once per run.
	 *
	 * A `spawnCase` action names its target by TEMPLATE slug, while the engine's
	 * `createSubCase` needs a caseType UUID — which is instance-specific and so
	 * cannot live in the catalog JSON. This map is the bridge.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $spawnTargets = null;

	/**
	 * Constructor for SeedVthWorkflowTemplates.
	 *
	 * @param SettingsService $settingsService Settings service for OR access
	 * @param WorkflowDefinitionService $definitionService Workflow lifecycle service
	 * @param VthSeedLookup $lookup OpenRegister lookups for the seed
	 * @param VthWorkflowGraphResolver $graphResolver Steps/transitions resolver
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WorkflowDefinitionService $definitionService,
		private readonly VthSeedLookup $lookup,
		private readonly VthWorkflowGraphResolver $graphResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function getName(): string {
		return 'Seed VTH (Vergunningen, Toezicht, Handhaving) workflow templates for Dossiq';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding VTH workflow templates...');

		$files = $this->catalogFiles(output: $output);
		if ($files === []) {
			return;
		}

		$summary = [
			'seeded' => 0,
			'skipped' => 0,
			'crossLink' => 0,
			'failed' => 0,
		];

		$this->lookup->runElevated(
			operation: function () use ($files, &$summary, $output): void {
				foreach ($files as $file) {
					$result = $this->processCatalogFileSafely(file: $file, output: $output);
					$summary[$result] = ($summary[$result] ?? 0) + 1;
				}
			}
		);

		$output->info(
			'VTH workflow templates seed complete: '
			. $summary['seeded'] . ' seeded, '
			. $summary['skipped'] . ' skipped (already present or unresolved), '
			. $summary['crossLink'] . ' cross-link entries logged, '
			. $summary['failed'] . ' failed.'
		);
	}//end run()

	/**
	 * Resolve the catalog files to seed, reporting every precondition that makes
	 * the seed a no-op.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return array<int, string> Absolute catalog file paths, or an empty list.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	private function catalogFiles(IOutput $output): array {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning(
				'OpenRegister is not available. Skipping VTH workflow templates seed.'
			);
			return [];
		}

		if (is_dir(self::CATALOG_DIR) === false) {
			$output->warning(
				'VTH workflow templates catalog directory not found at '
				. self::CATALOG_DIR
			);
			return [];
		}

		$files = glob(self::CATALOG_DIR . '/*.json');
		if ($files === false || $files === []) {
			$output->warning('No VTH workflow template catalog files found.');
			return [];
		}

		return $files;
	}//end catalogFiles()

	/**
	 * Build (once per run) the template slug → caseType UUID map for spawnCase.
	 *
	 * Every catalog entry is read, including cross-link ones: a cross-link entry
	 * seeds no workflow of its own but its caseType is still a legitimate spawn
	 * target. A slug whose caseType does not resolve is simply absent from the
	 * map, and the resolver drops the action rather than storing a dead one.
	 *
	 * @return array<string, string> Template slug → caseType UUID
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	private function spawnTargets(): array {
		if ($this->spawnTargets !== null) {
			return $this->spawnTargets;
		}

		$files = glob(self::CATALOG_DIR . '/*.json');
		if ($files === false) {
			$files = [];
		}

		$map = [];
		foreach ($files as $file) {
			$data = $this->loadCatalogEntry(file: $file);
			if ($data === null) {
				continue;
			}

			$caseTypeSlug = (string)($data['caseTypeSlug'] ?? '');
			if ($caseTypeSlug === '') {
				continue;
			}

			$caseTypeId = $this->lookup->resolveCaseTypeId(slug: $caseTypeSlug);
			if ($caseTypeId === '') {
				continue;
			}

			$map[(string)$data['slug']] = $caseTypeId;
		}

		$this->spawnTargets = $map;

		return $map;
	}//end spawnTargets()

	/**
	 * Process one catalog file, converting any throw into a `failed` tally.
	 *
	 * One unusable catalog file must never abort the rest of the catalog.
	 *
	 * @param string $file Absolute path to the JSON catalog file.
	 * @param IOutput $output The output interface.
	 *
	 * @return string One of seeded|skipped|crossLink|failed
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	private function processCatalogFileSafely(string $file, IOutput $output): string {
		try {
			return $this->processCatalogFile(file: $file, output: $output);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to process VTH workflow template catalog file',
				[
					'app' => Application::APP_ID,
					'file' => basename($file),
					'exception' => $e->getMessage(),
				]
			);
			$output->warning(
				'Skipping catalog file ' . basename($file)
				. ' due to processing error (see log).'
			);
			return 'failed';
		}//end try
	}//end processCatalogFileSafely()

	/**
	 * Process a single catalog file.
	 *
	 * @param string $file Absolute path to the JSON catalog file
	 * @param IOutput $output The output interface
	 *
	 * @return string One of seeded|skipped|crossLink|failed
	 */
	private function processCatalogFile(string $file, IOutput $output): string {
		$data = $this->loadCatalogEntry(file: $file);
		if ($data === null) {
			return 'failed';
		}

		$slug = (string)($data['slug'] ?? '');
		$title = (string)($data['title'] ?? '');

		// Cross-link entries (e.g. bezwaar) do not create a new
		// workflowTemplate; they only document VTH-specific guards that
		// a downstream change should attach to the canonical workflow.
		if ((bool)($data['crossLink'] ?? false) === true) {
			$this->reportCrossLink(data: $data, slug: $slug, output: $output);
			return 'crossLink';
		}

		// Resolve caseType slug → UUID and the statusType map (soft-fail).
		$context = $this->resolveTemplateContext(
			data: $data,
			slug: $slug,
			title: $title,
			output: $output,
		);
		if ($context === null) {
			return 'skipped';
		}

		// Resolve steps and transitions. On any unresolved status, skip
		// the entire template (no partial seed).
		$graph = $this->graphResolver->resolve(
			data: $data,
			slug: $slug,
			statusMap: (array)$context['statusMap'],
			spawnTargets: $this->spawnTargets(),
		);
		if ($graph === null) {
			return 'skipped';
		}

		return $this->createAndPublishTemplate(
			data: $data,
			slug: $slug,
			title: $title,
			caseTypeId: (string)$context['caseTypeId'],
			graph: $graph,
			output: $output,
		);
	}//end processCatalogFile()

	/**
	 * Report a cross-link catalog entry — documented, never seeded.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry.
	 * @param string $slug The template slug.
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 */
	private function reportCrossLink(array $data, string $slug, IOutput $output): void {
		$this->logger->info(
			'Dossiq: VTH workflow template — cross-link entry, no new workflow created',
			[
				'app' => Application::APP_ID,
				'slug' => $slug,
				'targetWorkflowIdentifier' => (string)($data['targetWorkflowIdentifier'] ?? ''),
			]
		);
		$output->info(
			'VTH catalog: cross-link entry "' . $slug . '" — no new workflow created.'
		);
	}//end reportCrossLink()

	/**
	 * Read and validate one catalog file, returning its decoded entry.
	 *
	 * Returns null on any condition that makes the file unusable (unreadable,
	 * invalid JSON, or missing slug/title) — the caller reports those as failed.
	 *
	 * @param string $file Absolute path to the JSON catalog file
	 *
	 * @return array<string, mixed>|null The decoded catalog entry, or null when unusable
	 */
	private function loadCatalogEntry(string $file): ?array {
		$raw = file_get_contents($file);
		if ($raw === false) {
			$this->logger->error(
				'Dossiq: VTH workflow template — unable to read catalog file',
				['app' => Application::APP_ID, 'file' => basename($file)]
			);
			return null;
		}

		$data = json_decode($raw, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			$this->logger->error(
				'Dossiq: VTH workflow template — invalid JSON in catalog file',
				['app' => Application::APP_ID, 'file' => basename($file)]
			);
			return null;
		}

		$slug = (string)($data['slug'] ?? '');
		$title = (string)($data['title'] ?? '');
		if ($slug === '' || $title === '') {
			$this->logger->warning(
				'Dossiq: VTH workflow template — missing slug or title',
				['app' => Application::APP_ID, 'file' => basename($file)]
			);
			return null;
		}

		return $data;
	}//end loadCatalogEntry()

	/**
	 * Resolve the caseType UUID and statusType map a template needs, applying
	 * the idempotency check.
	 *
	 * Returns null for every soft-fail precondition — the caller reports those
	 * as skipped.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry
	 * @param string $slug The template slug
	 * @param string $title The template title
	 * @param IOutput $output The output interface
	 *
	 * @return array<string, mixed>|null {caseTypeId, statusMap}, or null when the template must be skipped
	 */
	private function resolveTemplateContext(array $data, string $slug, string $title, IOutput $output): ?array {
		$caseTypeId = $this->resolveCaseType(data: $data, slug: $slug, output: $output);
		if ($caseTypeId === '') {
			return null;
		}

		// Idempotency: skip if a workflow template with the same title +
		// caseType is already present.
		if ($this->lookup->isAlreadySeeded(caseTypeId: $caseTypeId, title: $title) === true) {
			$this->logger->info(
				'Dossiq: VTH workflow template already present, skipping',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'caseType' => $caseTypeId,
				]
			);
			return null;
		}

		// Build the name → UUID map for statusTypes belonging to this caseType.
		$statusMap = $this->lookup->buildStatusMap(caseTypeId: $caseTypeId);
		if ($statusMap === []) {
			$this->logger->warning(
				'Dossiq: VTH workflow template — no statusTypes found for caseType',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'caseType' => $caseTypeId,
				]
			);
			return null;
		}

		return [
			'caseTypeId' => $caseTypeId,
			'statusMap' => $statusMap,
		];
	}//end resolveTemplateContext()

	/**
	 * Resolve the catalog entry's caseType slug to its UUID.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry.
	 * @param string $slug The template slug.
	 * @param IOutput $output The output interface.
	 *
	 * @return string The caseType UUID, or the empty string when unresolved.
	 */
	private function resolveCaseType(array $data, string $slug, IOutput $output): string {
		$caseTypeSlug = (string)($data['caseTypeSlug'] ?? '');
		if ($caseTypeSlug === '') {
			$this->logger->warning(
				'Dossiq: VTH workflow template — missing caseTypeSlug',
				['app' => Application::APP_ID, 'slug' => $slug]
			);
			return '';
		}

		$caseTypeId = $this->lookup->resolveCaseTypeId(slug: $caseTypeSlug);
		if ($caseTypeId === '') {
			// Expected precondition on every boot until base-register-seed-data
			// has run (or while the anonymous repair context cannot read the
			// caseType) — debug, not warning, so it does not spam the log.
			$this->logger->debug(
				'Dossiq: VTH workflow template — caseType not found, skipping',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'caseTypeSlug' => $caseTypeSlug,
				]
			);
			$output->info(
				'VTH catalog: caseType "' . $caseTypeSlug . '" not found for template "'
				. $slug . '" — skipping (run base-register-seed-data first).'
			);
		}

		return $caseTypeId;
	}//end resolveCaseType()

	/**
	 * Create the draft via the lifecycle service and publish it.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry
	 * @param string $slug The template slug
	 * @param string $title The template title
	 * @param string $caseTypeId The resolved caseType UUID
	 * @param array<string, mixed> $graph The resolved {steps, transitions}
	 * @param IOutput $output The output interface
	 *
	 * @return string One of seeded|failed
	 */
	private function createAndPublishTemplate(
		array $data,
		string $slug,
		string $title,
		string $caseTypeId,
		array $graph,
		IOutput $output,
	): string {
		// Create draft via the lifecycle service.
		$draft = $this->definitionService->createDraft(
			payload: [
				'title' => $title,
				'description' => (string)($data['description'] ?? ''),
				'caseType' => $caseTypeId,
				'version' => (int)($data['version'] ?? 1),
				'steps' => $graph['steps'],
				'transitions' => $graph['transitions'],
			]
		);

		if ($draft === null || isset($draft['id']) === false) {
			$this->logger->error(
				'Dossiq: VTH workflow template — createDraft returned null',
				['app' => Application::APP_ID, 'slug' => $slug]
			);
			return 'failed';
		}

		// Publish — flips to lifecycleStatus=published, isActive=true and
		// pins caseType.workflowDefinition only when no previous definition
		// was pinned (handled inside publish()).
		$published = $this->definitionService->publish(id: (string)$draft['id']);
		if ($published === null) {
			$this->logger->error(
				'Dossiq: VTH workflow template — publish returned null',
				['app' => Application::APP_ID, 'slug' => $slug, 'draftId' => (string)$draft['id']]
			);
			return 'failed';
		}

		$output->info('VTH catalog: seeded "' . $title . '" v' . (int)($data['version'] ?? 1) . '.');
		return 'seeded';
	}//end createAndPublishTemplate()
}//end class
