<?php

/**
 * Dossiq Template Library Service
 *
 * Service for loading and activating zaaktype templates.
 * Templates are JSON files shipped with the app that define complete
 * case type configurations (statuses, properties, document types, etc.).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/template-library/spec.md
 * @spec openspec/specs/template-library/spec.md
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\TemplateLibraryServiceInterface;
use OCA\Dossiq\Service\TemplateLibrary\TemplateActivation;
use OCA\Dossiq\Service\TemplateLibrary\TemplateRows;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for loading and activating zaaktype templates.
 *
 * @spec openspec/specs/template-library/spec.md
 */
class TemplateLibraryService implements TemplateLibraryServiceInterface {

	/**
	 * Path to the templates directory.
	 */
	private const TEMPLATES_DIR = __DIR__ . '/../Settings/templates';

	/**
	 * The collections a template declares beside its case type, in the order
	 * they are saved: the answer's key, the template's key and the setting
	 * naming the schema.
	 */
	private const LINKED_COLLECTIONS = [
		'statuses' => ['statusTypes', 'status_type_schema'],
		'properties' => ['propertyDefinitions', 'property_definition_schema'],
		'documents' => ['documentTypes', 'document_type_schema'],
		'decisions' => ['decisionTypes', 'decision_type_schema'],
		'roles' => ['roleTypes', 'role_type_schema'],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service for register/schema references
	 * @param LoggerInterface $logger          Logger
	 * @param string          $templatesDir    Directory holding the template JSON files
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly string $templatesDir=self::TEMPLATES_DIR,
	) {
	}//end __construct()

	/**
	 * List all available zaaktype templates.
	 *
	 * Scans the templates directory for JSON files and returns their metadata.
	 *
	 * @return array<int, array<string, mixed>> List of template metadata
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listTemplates(): array {
		$templates = [];

		foreach ($this->templateFiles() as $file) {
			$content = file_get_contents($file);
			if ($content === false) {
				continue;
			}

			$data = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
				$this->logger->warning(
					'Invalid template file: ' . basename($file),
					['app' => AppIdentity::APP_ID]
				);
				continue;
			}

			$templates[] = [
				'id' => $data['id'] ?? pathinfo($file, PATHINFO_FILENAME),
				'title' => $data['title'] ?? '',
				'description' => $data['description'] ?? '',
				'category' => $data['category'] ?? 'general',
				'version' => $data['version'] ?? '1.0.0',
				'file' => basename($file),
				'fills' => $this->fillsOf(data: $data),
				'notes' => $this->notesOf(data: $data),
			];
		}//end foreach

		return $templates;
	}//end listTemplates()

	/**
	 * The template JSON files in the templates directory.
	 *
	 * @return array<int, string> File paths, or an empty list when the directory is missing or unreadable.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function templateFiles(): array {
		if (is_dir($this->templatesDir) === false) {
			return [];
		}

		$files = glob($this->templatesDir . '/*.json');
		if ($files === false) {
			return [];
		}

		return $files;
	}//end templateFiles()

	/**
	 * What a template fills in from the case, for the generate dialog.
	 *
	 * @param array<string, mixed> $data The template file's content.
	 *
	 * @return array<int, mixed> The template's fills, or an empty list.
	 *
	 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-a-template-names-what-it-fills-in
	 */
	private function fillsOf(array $data): array {
		if (isset($data['fills']) === false || is_array($data['fills']) === false) {
			return [];
		}

		return array_values($data['fills']);
	}//end fillsOf()

	/**
	 * The notes a template carries for whoever generates it, e.g. about the objection period.
	 *
	 * @param array<string, mixed> $data The template file's content.
	 *
	 * @return array<int, string> The notes, blanks and non-strings left out.
	 *
	 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-a-template-carries-notes-for-the-generate-dialog
	 */
	private function notesOf(array $data): array {
		if (isset($data['notes']) === false || is_array($data['notes']) === false) {
			return [];
		}

		$notes = [];
		foreach ($data['notes'] as $note) {
			if (is_string($note) === true && trim($note) !== '') {
				$notes[] = trim($note);
			}
		}

		return $notes;
	}//end notesOf()

	/**
	 * Load a template by its ID.
	 *
	 * @param string $templateId The template identifier
	 *
	 * @return array<string, mixed>|null The full template data or null if not found
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadTemplate(string $templateId): ?array {
		foreach ($this->templateFiles() as $file) {
			$content = file_get_contents($file);
			if ($content === false) {
				continue;
			}

			$data = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
				continue;
			}

			$fileId = $data['id'] ?? pathinfo($file, PATHINFO_FILENAME);
			if ($fileId === $templateId) {
				return $data;
			}
		}

		return null;
	}//end loadTemplate()

	/**
	 * Activate a template by creating OpenRegister objects for the case type and related entities.
	 *
	 * This creates:
	 * - A caseType object
	 * - statusType objects linked to the caseType
	 * - propertyDefinition objects linked to the caseType
	 * - documentType objects linked to the caseType
	 * - decisionType objects linked to the caseType
	 * - roleType objects linked to the caseType
	 * - a workflowTemplate, when the template declares one, with its transitions
	 *   resolved from status names to the ids just created
	 *
	 * @param string $templateId The template identifier
	 *
	 * @return array<string, mixed> Result with created object IDs
	 *
	 * @throws \RuntimeException If template not found or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function activateTemplate(string $templateId): array {
		$template = $this->caseTypeTemplate(templateId: $templateId);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if (empty($register) === true) {
			throw new RuntimeException('Dossiq register not configured');
		}

		// Create the case type.
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		// No `?? []`: the refusal above already proved this is an array, so
		// the coalesce was dead and phpstan said so.
		$caseTypeData = $template['caseType'];
		$caseType = $objectService->saveObject(
			object: $caseTypeData,
			register: $register,
			schema: $caseTypeSchema,
		);
		$caseTypeId = $caseType->getUuid();

		$created = $this->createTemplateEntities(
			activation: new TemplateActivation(objectService: $objectService, register: $register, caseTypeId: $caseTypeId),
			template: $template,
		);

		$result = array_merge(['templateId' => $templateId, 'caseType' => $caseTypeId], $created);

		$this->logger->info(
			'Template activated: ' . $templateId . ' -> caseType ' . $caseTypeId,
			['app' => AppIdentity::APP_ID]
		);

		return $result;
	}//end activateTemplate()

	/**
	 * The template to activate, refused when it is missing or describes no case type.
	 *
	 * The library holds two kinds: zaaktype bundles, which describe a case
	 * type, and DOCUMENT templates, which carry a `body` a letter is
	 * rendered from and no case type at all. Activating the latter would
	 * save an EMPTY caseType object and report a uuid for it, so the
	 * refusal is here rather than in whatever calls this.
	 *
	 * @param string $templateId The template id.
	 *
	 * @return array<string, mixed> The template, with an array `caseType`.
	 *
	 * @throws RuntimeException When the template is missing or not a case type.
	 */
	private function caseTypeTemplate(string $templateId): array {
		$template = $this->loadTemplate(templateId: $templateId);
		if ($template === null) {
			throw new RuntimeException('Template not found: ' . $templateId);
		}

		if (is_array(($template['caseType'] ?? null)) === false) {
			throw new RuntimeException(
				'Template is not a case type and cannot be activated: ' . $templateId
			);
		}

		return $template;
	}//end caseTypeTemplate()

	/**
	 * Create every entity a template declares alongside its case type, each linked to the
	 * freshly-created caseType id.
	 *
	 * The name of each status is kept against the uuid it was given, because a
	 * transition is written by an author in status NAMES and read by the engine
	 * as status IDS, and nothing in between was translating.
	 *
	 * @param TemplateActivation   $activation Where to save, and the case type.
	 * @param array<string, mixed> $template   The loaded template definition.
	 *
	 * @return array<string, mixed> The created object ids, keyed by collection, plus the workflow uuid.
	 */
	private function createTemplateEntities(TemplateActivation $activation, array $template): array {
		$created = [];
		foreach (self::LINKED_COLLECTIONS as $collection => [$templateKey, $schemaKey]) {
			$created[$collection] = $this->saveLinked(
				activation: $activation,
				rows: new TemplateRows(schemaKey: $schemaKey, rows: ($template[$templateKey] ?? []))
			);
		}

		$statusIds = $this->statusIdsByName(rows: ($template['statusTypes'] ?? []), uuids: $created['statuses']);
		$created['workflow'] = $this->createWorkflowTemplate(activation: $activation->withStatusIds(statusIds: $statusIds), template: $template);

		return $created;
	}//end createTemplateEntities()

	/**
	 * Save the rows of one collection, each linked to the activation's case type.
	 *
	 * @param TemplateActivation $activation Where to save, and the case type.
	 * @param TemplateRows       $rows       The rows and their schema setting.
	 *
	 * @return array<int, string> The saved uuids, in order.
	 */
	private function saveLinked(TemplateActivation $activation, TemplateRows $rows): array {
		$schema = $this->settingsService->getConfigValue($rows->schemaKey);
		$uuids = [];
		foreach ($rows->rows as $row) {
			$row['caseType'] = $activation->caseTypeId;
			$uuids[] = $activation->objectService->saveObject(
				object: $row,
				register: $activation->register,
				schema: $schema,
			)->getUuid();
		}

		return $uuids;
	}//end saveLinked()

	/**
	 * Each status name against the uuid its row was saved under; a later row with the same name wins.
	 *
	 * @param mixed              $rows  The status rows as the template declares them.
	 * @param array<int, string> $uuids The uuids they were saved under, in order.
	 *
	 * @return array<string, string> Status name to uuid.
	 */
	private function statusIdsByName(mixed $rows, array $uuids): array {
		$statusIds = [];
		$index = 0;
		foreach ($rows as $row) {
			$statusIds[trim((string)($row['name'] ?? ''))] = $uuids[$index];
			$index++;
		}

		return $statusIds;
	}//end statusIdsByName()

	/**
	 * Create the workflow a template declares, with its transitions resolved.
	 *
	 * 🔑 A DECLARATION NOTHING READS IS WORSE THAN NO DECLARATION. Every
	 * template in this library shipped its statuses and no transitions at all,
	 * so a case type activated here had an ordered list of statuses and no
	 * moves between them, and the three things a transition may declare since
	 * `what-a-transition-declares` (what must be settled, what it explains,
	 * who may not take it) had no way to reach an activated case type. A four
	 * eyes rule written in a template and never saved is not a weaker rule, it
	 * is no rule, and nothing on screen says so.
	 *
	 * The translation is the point: an author writes `fromStatusName`, the
	 * engine reads `fromStatus` as a uuid, and the map comes from the statuses
	 * this same activation just created. A transition naming a status the
	 * template does not declare is DROPPED rather than saved half-resolved,
	 * because a transition with an empty `fromStatus` is offered from every
	 * status in the case type.
	 *
	 * @param TemplateActivation   $activation Where to save, the case type and the statuses it created.
	 * @param array<string, mixed> $template   The loaded template definition.
	 *
	 * @return string The workflow uuid, or the empty string when the template declares none.
	 *
	 * @spec openspec/specs/avg-processing-surface/spec.md
	 */
	private function createWorkflowTemplate(TemplateActivation $activation, array $template): string {
		$workflow = ($template['workflowTemplate'] ?? null);
		if (is_array($workflow) === false) {
			return '';
		}

		$transitions = $this->resolvedTransitions(activation: $activation, workflow: $workflow);
		if ($transitions === []) {
			return '';
		}

		$workflow['caseType'] = $activation->caseTypeId;
		$workflow['transitions'] = $transitions;

		return $activation->objectService->saveObject(
			object: $workflow,
			register: $activation->register,
			schema: $this->settingsService->getConfigValue('workflow_template_schema'),
		)->getUuid();
	}//end createWorkflowTemplate()

	/**
	 * The workflow's transitions with their status names resolved to uuids; the rest dropped.
	 *
	 * @param TemplateActivation   $activation The statuses this activation created.
	 * @param array<string, mixed> $workflow   The workflow the template declares.
	 *
	 * @return array<int, array<string, mixed>> The resolved transitions.
	 */
	private function resolvedTransitions(TemplateActivation $activation, array $workflow): array {
		$transitions = [];
		foreach ((array)($workflow['transitions'] ?? []) as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			$resolved = $this->resolvedTransition(activation: $activation, transition: $transition);
			if ($resolved !== null) {
				$transitions[] = $resolved;
			}
		}

		return $transitions;
	}//end resolvedTransitions()

	/**
	 * One transition with `fromStatus` and `toStatus` as uuids, or null when it names an undeclared status.
	 *
	 * @param TemplateActivation   $activation The statuses this activation created.
	 * @param array<string, mixed> $transition The transition as the template writes it.
	 *
	 * @return array<string, mixed>|null The resolved transition.
	 */
	private function resolvedTransition(TemplateActivation $activation, array $transition): ?array {
		$from = $activation->statusId(name: ($transition['fromStatusName'] ?? ''));
		$to = $activation->statusId(name: ($transition['toStatusName'] ?? ''));
		if ($from === '' || $to === '') {
			$this->logger->warning(
				'Template transition names a status the template does not declare, dropped: '
				. (string)($transition['label'] ?? ''),
				['app' => AppIdentity::APP_ID]
			);
			return null;
		}

		unset($transition['fromStatusName'], $transition['toStatusName']);
		$transition['fromStatus'] = $from;
		$transition['toStatus'] = $to;

		return $transition;
	}//end resolvedTransition()
}//end class
