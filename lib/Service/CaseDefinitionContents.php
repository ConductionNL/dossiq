<?php

/**
 * Dossiq CaseDefinitionContents.
 *
 * What a case definition package carries, read from the register: each
 * component's rows (schema, statuses with their transitions, permissions,
 * documents, metadata, workflows) and every object ref the package points
 * at. Built by CaseDefinitionExportService for an export.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Reads the contents of one case type's package.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class CaseDefinitionContents {
	/**
	 * The settings keys naming the schema each component reads.
	 *
	 * One map rather than a key repeated per method: a schema renamed in the
	 * settings has one place to change, and a component naming a key nothing
	 * answers to reads as a component with no rows, which is the defect this
	 * whole change is about.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_KEYS = [
		'propertyDefinitions' => 'property_definition_schema',
		'statusTypes' => 'status_type_schema',
		'roleTypes' => 'role_type_schema',
		'documentTypes' => 'document_type_schema',
		'resultTypes' => 'result_type_schema',
		'workflowTemplates' => 'workflow_template_schema',
	];

	/**
	 * Read through the app's one case type reader.
	 *
	 * @param CaseTypeStore $caseTypes The app's one reader of a case type and its rows.
	 */
	public function __construct(
		private readonly CaseTypeStore $caseTypes,
	) {
	}//end __construct()

	/**
	 * Every object ref the exported components point at.
	 *
	 * The refs are the ids of the rows themselves plus the case type's own
	 * outward references. An importer reads this list to decide whether it can
	 * resolve the package before it writes any of it.
	 *
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return array<int, string> The refs, unique and in a stable order.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function dependenciesOf(CaseDefinitionExport $export): array {
		$refs = array_merge(
			[$export->caseTypeId],
			$this->rowRefs(caseTypeId: $export->caseTypeId),
			$this->outwardRefs(caseType: $export->caseType)
		);

		$refs = array_values(array_unique(array_filter($refs, static fn (string $ref): bool => $ref !== '')));
		sort($refs);

		return $refs;
	}//end dependenciesOf()

	/**
	 * The ids of the case type's own rows, schema by schema.
	 *
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<int, string>
	 */
	private function rowRefs(string $caseTypeId): array {
		$refs = [];
		foreach (self::SCHEMA_KEYS as $schemaKey) {
			foreach ($this->rowsOf(schemaKey: $schemaKey, caseTypeId: $caseTypeId) as $row) {
				$refs[] = $this->caseTypes->rowId(row: $row);
			}
		}

		return $refs;
	}//end rowRefs()

	/**
	 * The case type's outward references: decision types, related, sub and
	 * parent case types, and its default document type.
	 *
	 * @param array<string, mixed> $caseType The case type object.
	 *
	 * @return array<int, string>
	 */
	private function outwardRefs(array $caseType): array {
		$refs = [];
		foreach (['decisionTypes', 'relatedCaseTypes', 'subCaseTypes', 'parentCaseType', 'defaultInformatieobjecttype'] as $key) {
			foreach ($this->entriesOf(value: ($caseType[$key] ?? null)) as $entry) {
				if (is_string($entry) === true && trim($entry) !== '') {
					$refs[] = trim($entry);
				}
			}
		}

		return $refs;
	}//end outwardRefs()

	/**
	 * A reference field as a list: a list stays one, anything else is one entry.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return array<int|string, mixed>
	 */
	private function entriesOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [$value];
	}//end entriesOf()

	/**
	 * The rows of one schema that belong to this case type.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(string $schemaKey, string $caseTypeId): array {
		return $this->caseTypes->rowsOfType(schemaKey: $schemaKey, caseTypeId: $caseTypeId);
	}//end rowsOf()

	/**
	 * Export a single component, from the register.
	 *
	 * 🔴 THIS USED TO RETURN A FIXED EMPTY SHAPE, for every case type on every
	 * instance, under a comment saying "Placeholder: in a full implementation,
	 * this would query OpenRegister". The endpoints answered, the ZIP was real,
	 * the manifest was real and every component inside it was empty. Two things
	 * hid it: `openspec/specs/case-types/spec.md` REQ-CT-17 and REQ-CT-18
	 * already said SHALL, and the archived change ticked "Create
	 * CaseDefinitionExportService with exportCaseDefinition() method", which is
	 * true of the method and false of the feature.
	 *
	 * @param CaseDefinitionExport $export    The export.
	 * @param string               $component The component name.
	 *
	 * @return array<string, mixed>|null The component data, or null for a name
	 *                                   this service does not know.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function exportComponent(CaseDefinitionExport $export, string $component): ?array {
		return match ($component) {
			'schema' => $this->schemaComponent(export: $export),
			'statuses' => $this->statusesComponent(caseTypeId: $export->caseTypeId),
			'permissions' => $this->permissionsComponent(export: $export),
			'documents' => $this->documentsComponent(export: $export),
			'metadata' => $this->metadataComponent(export: $export),
			'workflows' => $this->workflowsOf(caseTypeId: $export->caseTypeId),
			default => null,
		};
	}//end exportComponent()

	/**
	 * The schema component: the case type and its property definitions.
	 *
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return array<string, mixed>
	 */
	private function schemaComponent(CaseDefinitionExport $export): array {
		return [
			'caseTypeId' => $export->caseTypeId,
			'caseType' => $export->caseType,
			'propertyDefinitions' => $this->rowsOf(
				schemaKey: self::SCHEMA_KEYS['propertyDefinitions'],
				caseTypeId: $export->caseTypeId
			),
		];
	}//end schemaComponent()

	/**
	 * The statuses component.
	 *
	 * The transitions are the workflow templates' own, because that is where
	 * they live: `statusType` declares no transition, and a `transitions: []`
	 * beside a populated `statusTypes` reads as a case type whose statuses
	 * connect to nothing.
	 *
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<string, mixed>
	 */
	private function statusesComponent(string $caseTypeId): array {
		return [
			'statusTypes' => $this->rowsOf(
				schemaKey: self::SCHEMA_KEYS['statusTypes'],
				caseTypeId: $caseTypeId
			),
			'transitions' => $this->transitionsOf(caseTypeId: $caseTypeId),
		];
	}//end statusesComponent()

	/**
	 * The permissions component: role types and the rights matrix.
	 *
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return array<string, mixed>
	 */
	private function permissionsComponent(CaseDefinitionExport $export): array {
		return [
			'roleTypes' => $this->rowsOf(
				schemaKey: self::SCHEMA_KEYS['roleTypes'],
				caseTypeId: $export->caseTypeId
			),
			'rightsMatrix' => ($export->caseType['rightsMatrix'] ?? []),
		];
	}//end permissionsComponent()

	/**
	 * The documents component: document types and the default one.
	 *
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return array<string, mixed>
	 */
	private function documentsComponent(CaseDefinitionExport $export): array {
		return [
			'documentTypes' => $this->rowsOf(
				schemaKey: self::SCHEMA_KEYS['documentTypes'],
				caseTypeId: $export->caseTypeId
			),
			'defaultInformatieobjecttype' => ($export->caseType['defaultInformatieobjecttype'] ?? null),
		];
	}//end documentsComponent()

	/**
	 * The metadata component: result types and decision types.
	 *
	 * Decision types are a REFERENCE LIST on the case type rather than rows
	 * carrying a `caseType` back-reference, so they are read where they are
	 * written. Exporting them as rows would answer the empty set on every
	 * instance.
	 *
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return array<string, mixed>
	 */
	private function metadataComponent(CaseDefinitionExport $export): array {
		return [
			'resultTypes' => $this->rowsOf(
				schemaKey: self::SCHEMA_KEYS['resultTypes'],
				caseTypeId: $export->caseTypeId
			),
			'decisionTypes' => ($export->caseType['decisionTypes'] ?? []),
		];
	}//end metadataComponent()

	/**
	 * The transitions the case type's workflow templates declare.
	 *
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<int, array<string, mixed>> The transitions.
	 */
	private function transitionsOf(string $caseTypeId): array {
		$transitions = [];
		foreach ($this->rowsOf(schemaKey: self::SCHEMA_KEYS['workflowTemplates'], caseTypeId: $caseTypeId) as $template) {
			$declared = ($template['transitions'] ?? []);
			if (is_array($declared) === false) {
				continue;
			}

			foreach ($declared as $transition) {
				if (is_array($transition) === true) {
					$transition['workflowTemplate'] = $this->caseTypes->rowId(row: $template);
					$transitions[] = $transition;
				}
			}
		}

		return $transitions;
	}//end transitionsOf()

	/**
	 * The workflow templates bound to the case type, keyed by archive entry name.
	 *
	 * The key becomes a filename, so it is derived from the title and falls
	 * back to the id: two templates with the same title would otherwise write
	 * one entry twice and the second would silently replace the first.
	 *
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<string, array<string, mixed>> The templates by entry name.
	 */
	private function workflowsOf(string $caseTypeId): array {
		$workflows = [];
		foreach ($this->rowsOf(schemaKey: self::SCHEMA_KEYS['workflowTemplates'], caseTypeId: $caseTypeId) as $template) {
			$id = $this->caseTypes->rowId(row: $template);
			$title = trim((string)($template['title'] ?? ''));
			$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $title);
			$name = trim((string)$name, '-');
			if ($name === '') {
				$name = $id;
			}

			if ($name === '') {
				continue;
			}

			if (array_key_exists($name, $workflows) === true) {
				$name .= '-' . $id;
			}

			$workflows[$name] = $template;
		}

		return $workflows;
	}//end workflowsOf()
}//end class
