<?php

/**
 * An older stored run, as the fields of the task that replaces it.
 *
 * Pure. inspection-checklists-onto-task 4.2: every `inspectieRapport` (stack
 * A), `inspectionChecklistRun` (stack B) and `inspectionResult` (stack C)
 * becomes one inspection task, carried over once and keyed on
 * `metadata.legacyRef`. A submitted run arrives completed; a stack B run that
 * was never submitted arrives active. OpenRegister's import path takes no
 * completion moment, so the original one rides in `metadata.legacyCompletedAt`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

/**
 * Maps the three older run shapes onto task fields.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */
class InspectionRunCarryOver {

	/**
	 * The three source schemas, with the request key their answers sit under
	 * and the older template schema their checklist reference points into.
	 *
	 * @var array<string, array{answers: string, checklist: string, templatePrefix: string}>
	 */
	public const SOURCES = [
		'inspectieRapport' => ['answers' => 'items', 'checklist' => 'checklist', 'templatePrefix' => 'inspectieChecklist/'],
		'inspectionChecklistRun' => ['answers' => 'responses', 'checklist' => 'template', 'templatePrefix' => ''],
		'inspectionResult' => ['answers' => 'answers', 'checklist' => 'checklist', 'templatePrefix' => 'inspectionChecklist/'],
	];

	/**
	 * Stack B statuses that never reached a submit.
	 *
	 * @var array<int, string>
	 */
	private const OPEN = ['draft', 'in_execution'];

	/**
	 * The answers and the outcome rule.
	 *
	 * @var InspectionAnswers
	 */
	private InspectionAnswers $answers;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->answers = new InspectionAnswers();
	}//end __construct()

	/**
	 * The `legacyRef` of a source object.
	 *
	 * @param string               $schema The source schema slug.
	 * @param array<string, mixed> $object The source object.
	 *
	 * @return string `<slug>/<uuid>`.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function legacyRef(string $schema, array $object): string {
		$self = ($object['@self'] ?? []);
		$id = '';
		if (is_array($self) === true) {
			$id = (string)($self['id'] ?? '');
		}

		if ($id === '') {
			$id = (string)($object['id'] ?? $object['uuid'] ?? '');
		}

		return $schema . '/' . $id;
	}//end legacyRef()

	/**
	 * The legacy reference of the template the run was filled against, as the
	 * fold recorded it, or the template uuid itself for stack B.
	 *
	 * @param string               $schema The source schema slug.
	 * @param array<string, mixed> $object The source object.
	 *
	 * @return string The reference to look the template up by.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function templateRef(string $schema, array $object): string {
		$source = self::SOURCES[$schema];

		return $source['templatePrefix'] . (string)($object[$source['checklist']] ?? '');
	}//end templateRef()

	/**
	 * The fields of the task that replaces a source run.
	 *
	 * @param string                    $schema   The source schema slug.
	 * @param array<string, mixed>      $object   The source object.
	 * @param array<string, mixed>|null $template The template it was filled against, when found.
	 *
	 * @return array<string, mixed> The task fields for OpenRegister's import path.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function taskData(string $schema, array $object, ?array $template): array {
		$source = self::SOURCES[$schema];
		$answers = $this->answers->fromPayload(payload: [$source['answers'] => ($object[$source['answers']] ?? [])]);
		$snapshot = $this->snapshot(object: $object, template: $template);

		$data = [
			'title' => 'Inspectie: ' . (string)($snapshot['name'] ?? ''),
			'kind' => InspectionRunService::KIND,
			'appId' => 'dossiq',
			'objectUuid' => (string)($object['case'] ?? ''),
			'state' => 'completed',
			'outcome' => $this->outcome(object: $object, snapshot: $snapshot, answers: $answers),
			'responses' => $answers,
			'evidence' => $this->evidence(object: $object, answers: $answers),
			'templateId' => $this->templateId(schema: $schema, object: $object, template: $template),
			'metadata' => $this->metadata(schema: $schema, object: $object),
		];

		if ($snapshot !== []) {
			$data['templateSnapshot'] = $snapshot;
			$data['templateVersion'] = (int)($object['templateVersion'] ?? $snapshot['version'] ?? 1);
		}

		if (in_array((string)($object['status'] ?? ''), self::OPEN, true) === true) {
			$data['state'] = 'active';
			unset($data['outcome']);
		}

		$started = (string)($object['startedAt'] ?? $object['inspectionDate'] ?? '');
		if ($started !== '') {
			$data['startAt'] = $started;
		}

		$remarks = trim((string)($object['remarks'] ?? ''));
		if ($remarks !== '') {
			$data['comment'] = $remarks;
		}

		return array_merge($data, $this->performer(object: $object));
	}//end taskData()

	/**
	 * Who did the run: an internal inspector, or an external party.
	 *
	 * @param array<string, mixed> $object The source object.
	 *
	 * @return array<string, string> `assignee`, and `performerType` for an external one.
	 */
	private function performer(array $object): array {
		$inspector = trim((string)($object['inspector'] ?? $object['completedBy'] ?? ''));
		if ($inspector !== '') {
			return ['assignee' => $inspector];
		}

		$external = trim((string)($object['assignedInspectorRef'] ?? ''));
		if ($external !== '') {
			return ['assignee' => $external, 'performerType' => 'external'];
		}

		return [];
	}//end performer()

	/**
	 * The stored outcome, or the rule's verdict when none was stored.
	 *
	 * @param array<string, mixed>             $object   The source object.
	 * @param array<string, mixed>             $snapshot The template.
	 * @param array<int, array<string, mixed>> $answers  The answers.
	 *
	 * @return string The outcome.
	 */
	private function outcome(array $object, array $snapshot, array $answers): string {
		$stored = (string)($object['overallResult'] ?? $object['result'] ?? '');
		if (in_array($stored, [InspectionAnswers::CONFORM, InspectionAnswers::NON_CONFORM, InspectionAnswers::PARTLY_CONFORM], true) === true) {
			return $stored;
		}

		return $this->answers->outcome(snapshot: $snapshot, answers: $answers);
	}//end outcome()

	/**
	 * The frozen template: the run's own snapshot, else the template found.
	 *
	 * @param array<string, mixed>      $object   The source object.
	 * @param array<string, mixed>|null $template The template found.
	 *
	 * @return array<string, mixed> The snapshot, or none.
	 */
	private function snapshot(array $object, ?array $template): array {
		if (is_array($object['templateSnapshot'] ?? null) === true && $object['templateSnapshot'] !== []) {
			return $object['templateSnapshot'];
		}

		return ($template ?? []);
	}//end snapshot()

	/**
	 * The template uuid: the found template's, else the reference the run held.
	 *
	 * @param string                    $schema   The source schema slug.
	 * @param array<string, mixed>      $object   The source object.
	 * @param array<string, mixed>|null $template The template found.
	 *
	 * @return string The uuid.
	 */
	private function templateId(string $schema, array $object, ?array $template): string {
		$found = '';
		if ($template !== null) {
			$found = (string)($template['@self']['id'] ?? $template['id'] ?? '');
		}

		if ($found !== '') {
			return $found;
		}

		return (string)($object[self::SOURCES[$schema]['checklist']] ?? '');
	}//end templateId()

	/**
	 * Every photo: the run's own list and the answers'.
	 *
	 * @param array<string, mixed>             $object  The source object.
	 * @param array<int, array<string, mixed>> $answers The answers.
	 *
	 * @return array<int, string> The file ids, once each.
	 */
	private function evidence(array $object, array $answers): array {
		$ids = $this->answers->evidence(answers: $answers);
		foreach (($object['photos'] ?? []) as $photo) {
			if (is_scalar($photo) === true && (string)$photo !== '' && in_array((string)$photo, $ids, true) === false) {
				$ids[] = (string)$photo;
			}
		}

		return $ids;
	}//end evidence()

	/**
	 * Metadata: where it came from, when it finished, where it was done.
	 *
	 * @param string               $schema The source schema slug.
	 * @param array<string, mixed> $object The source object.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private function metadata(string $schema, array $object): array {
		$metadata = ['legacyRef' => $this->legacyRef(schema: $schema, object: $object)];
		$completed = (string)($object['completedAt'] ?? $object['submittedAt'] ?? $object['inspectionDate'] ?? '');
		if ($completed !== '') {
			$metadata['legacyCompletedAt'] = $completed;
		}

		$location = ($object['location'] ?? null);
		if (is_array($location) === true && $location !== []) {
			$metadata['location'] = $location;
		} elseif (is_string($location) === true && trim($location) !== '') {
			$metadata['location'] = ['address' => trim($location), 'source' => 'manual'];
		}

		if (trim((string)($object['inspection'] ?? '')) !== '') {
			$metadata['inspection'] = (string)$object['inspection'];
		}

		return $metadata;
	}//end metadata()
}//end class
