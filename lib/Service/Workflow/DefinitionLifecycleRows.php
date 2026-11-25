<?php

/**
 * The rows a workflow definition's lifecycle steps write.
 *
 * What a draft, a clone, a publish and a deprecation write to a workflow
 * definition, built without a store: the lifecycle flags each step sets and,
 * for a draft or a clone, its content and its route.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

/**
 * Builds the rows of a workflow definition's lifecycle steps.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DefinitionLifecycleRows {

	/**
	 * Constructor.
	 *
	 * @param WorkflowLifecycleGuard $guard Reads the route a row is on.
	 * @param WorkflowJsonProperty   $json  Encodes the steps and transitions as the schema stores them.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WorkflowLifecycleGuard $guard,
		private readonly WorkflowJsonProperty $json,
	) {
	}//end __construct()

	/**
	 * The draft row to store.
	 *
	 * @param array<string, mixed> $payload The draft payload, naming its case type
	 * @param int                  $version The version
	 *
	 * @return array<string, mixed> The row
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function draft(array $payload, int $version): array {
		$stepsValue = $this->json->encode(value: ($payload['steps'] ?? []));
		$transitionsValue = $this->json->encode(value: ($payload['transitions'] ?? []));

		return [
			'title' => (string)$payload['title'],
			'description' => (string)($payload['description'] ?? ''),
			'caseType' => (string)($payload['caseType'] ?? ''),
			'variant' => $this->guard->variantOf(row: $payload),
			'version' => $version,
		] + $this->draftState(encoded: ['steps' => $stepsValue, 'transitions' => $transitionsValue], payload: $payload);
	}//end draft()

	/**
	 * The draft a clone of a definition starts as, on its source's route.
	 *
	 * @param array<string, mixed> $source  The definition cloned.
	 * @param int                  $version The version the clone gets.
	 *
	 * @return array<string, mixed> The row
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function cloned(array $source, int $version): array {
		return [
			'title' => rtrim((string)($source['title'] ?? 'Workflow')) . ' (kopie)',
			'description' => (string)($source['description'] ?? ''),
			'caseType' => (string)($source['caseType'] ?? ''),
			// A clone stays on its source's route. Cloning the spoedeisende
			// route to get a draft of the ordinary one is not a thing anyone
			// means, and it is what dropping this line would produce.
			'variant' => $this->guard->variantOf(row: $source),
			'version' => $version,
			'isActive' => false,
			'isDraft' => true,
			'lifecycleStatus' => WorkflowLifecycleGuard::STATUS_DRAFT,
			'steps' => (string)($source['steps'] ?? ''),
			'transitions' => (string)($source['transitions'] ?? ''),
			'nodePositions' => (string)($source['nodePositions'] ?? ''),
		];
	}//end cloned()

	/**
	 * The payload that flips a draft to published+active, with the authorization-enriched transitions when any resolved.
	 *
	 * @param array<int, array<string, mixed>>|null $authoredTransitions Enriched transitions, or null when none.
	 *
	 * @return array<string, mixed> The publish payload.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function published(?array $authoredTransitions): array {
		$payload = [
			'lifecycleStatus' => WorkflowLifecycleGuard::STATUS_PUBLISHED,
			'isActive' => true,
			'isDraft' => false,
		];

		if ($authoredTransitions !== null) {
			$payload['transitions'] = json_encode($authoredTransitions);
		}

		return $payload;
	}//end published()

	/**
	 * The payload that retires a definition: deprecated and inactive.
	 *
	 * @return array<string, mixed> The deprecation payload.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function deprecated(): array {
		return [
			'lifecycleStatus' => WorkflowLifecycleGuard::STATUS_DEPRECATED,
			'isActive' => false,
		];
	}//end deprecated()

	/**
	 * The draft's lifecycle flags and its content.
	 *
	 * @param array{steps: string|false, transitions: string|false} $encoded The encoded steps and transitions
	 * @param array<string, mixed>                                 $payload The draft payload
	 *
	 * @return array<string, mixed> The fields
	 */
	private function draftState(array $encoded, array $payload): array {
		return [
			'isActive' => false,
			'isDraft' => true,
			'lifecycleStatus' => WorkflowLifecycleGuard::STATUS_DRAFT,
			'steps' => $encoded['steps'],
			'transitions' => $encoded['transitions'],
			'nodePositions' => (string)($payload['nodePositions'] ?? ''),
		];
	}//end draftState()
}//end class
