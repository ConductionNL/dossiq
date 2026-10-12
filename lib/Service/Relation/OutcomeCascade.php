<?php

/**
 * Dossiq outcome cascade.
 *
 * Runs a named cascade from app config `link_cascades` on one target object.
 * A cascade is what an outcome on a linked case does to what it points at: an
 * objection freezes the calculation it contests and puts its payments on hold,
 * an appeal that is upheld reopens the case it came from. The steps are
 * configuration; this class only knows three kinds of step:
 *
 *  - `set`: write fields on the target;
 *  - `setDependents`: write fields on every object that references the target
 *    through a named field;
 *  - `transition`: run a named transition on the target's case, or on the case
 *    of an object the target references (`via` + `viaSchema`).
 *
 * A value is a literal, or `{"input": "<name>"}` taken from the caller. Every
 * input a cascade names is checked before the first write, so a missing input
 * refuses the cascade instead of leaving it half done.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Runs a configured cascade on a target object.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
 */
class OutcomeCascade {

	use SearchesObjects;

	/**
	 * The app config key that holds the cascades.
	 *
	 * @var string
	 */
	public const CASCADES_KEY = 'link_cascades';

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settingsService Register, schema keys, cascades and the object service.
	 * @param StatusTransitionService $transitions     Runs a transition on a case.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StatusTransitionService $transitions,
	) {
	}//end __construct()

	/**
	 * The case a cascade's target belongs to, for the caller's access check.
	 *
	 * @param string $name     The cascade.
	 * @param string $targetId The target uuid.
	 *
	 * @return string The case uuid, or '' when the target names none.
	 *
	 * @throws InvalidArgumentException When the cascade is unknown or the target is not found.
	 * @throws RuntimeException When the register cannot be reached.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
	 */
	public function caseOf(string $name, string $targetId): string {
		$cascade = $this->cascade(name: $name);
		$target  = $this->target(cascade: $cascade, targetId: $targetId);

		return $this->text(value: ($target[($cascade['caseField'] ?? 'case')] ?? ''));
	}//end caseOf()

	/**
	 * Run a cascade on a target.
	 *
	 * @param string               $name     The cascade.
	 * @param string               $targetId The target uuid.
	 * @param array<string, mixed> $inputs   Values the cascade's steps ask for by name.
	 *
	 * @return array<string, mixed> The target as it stands after the cascade.
	 *
	 * @throws InvalidArgumentException When the cascade is unknown, malformed, or an input is missing.
	 * @throws RuntimeException When the register cannot be reached or a step fails.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
	 */
	public function run(string $name, string $targetId, array $inputs = []): array {
		$cascade = $this->cascade(name: $name);
		$steps   = ($cascade['steps'] ?? []);
		if (is_array($steps) === false || $steps === []) {
			throw new InvalidArgumentException("Cascade '$name' has no steps.");
		}

		$this->guardInputs(name: $name, steps: $steps, inputs: $inputs);

		$target = $this->target(cascade: $cascade, targetId: $targetId);
		foreach ($steps as $step) {
			$target = $this->runStep(cascade: $cascade, step: $step, target: $target, targetId: $targetId, inputs: $inputs);
		}

		return $target;
	}//end run()

	/**
	 * Run one step and answer the target as it stands after it.
	 *
	 * @param array<string, mixed> $cascade  The cascade.
	 * @param mixed                $step     The step.
	 * @param array<string, mixed> $target   The target.
	 * @param string               $targetId The target uuid.
	 * @param array<string, mixed> $inputs   The caller's values.
	 *
	 * @return array<string, mixed> The target.
	 *
	 * @throws InvalidArgumentException When the step is of no known kind.
	 */
	private function runStep(array $cascade, mixed $step, array $target, string $targetId, array $inputs): array {
		if (is_array($step) === true && is_array($step['set'] ?? null) === true) {
			return ($this->write(
				schemaKey: (string)$cascade['target'],
				id: $targetId,
				changes: $this->values(values: $step['set'], inputs: $inputs)
			) ?? $target);
		}

		if (is_array($step) === true && is_array($step['setDependents'] ?? null) === true) {
			$this->setDependents(spec: $step['setDependents'], targetId: $targetId, inputs: $inputs);
			return $target;
		}

		if (is_array($step) === true && is_string($step['transition'] ?? null) === true) {
			$this->transition(step: $step, cascade: $cascade, target: $target, targetId: $targetId);
			return $target;
		}

		throw new InvalidArgumentException('A cascade step must be set, setDependents or transition.');
	}//end runStep()

	/**
	 * Write fields on every object that references the target through a field.
	 *
	 * @param array<string, mixed> $spec     `schema`, `field` and `values`.
	 * @param string               $targetId The target uuid.
	 * @param array<string, mixed> $inputs   The caller's values.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the step is missing its schema, field or values.
	 */
	private function setDependents(array $spec, string $targetId, array $inputs): void {
		$schemaKey = $this->text(value: ($spec['schema'] ?? ''));
		$field     = $this->text(value: ($spec['field'] ?? ''));
		if ($schemaKey === '' || $field === '' || is_array($spec['values'] ?? null) === false) {
			throw new InvalidArgumentException('A setDependents step needs schema, field and values.');
		}

		$changes    = $this->values(values: $spec['values'], inputs: $inputs);
		$dependents = $this->searchObjectsAsArrays(
			objectService: $this->objectService(),
			register: $this->settingsService->getConfigValue(key: 'register'),
			schema: $this->schema(key: $schemaKey),
			filters: [$field => $targetId]
		);
		foreach ($dependents as $dependent) {
			$id = $this->text(value: ($dependent['@self']['id'] ?? ($dependent['id'] ?? '')));
			if ($id !== '') {
				$this->write(schemaKey: $schemaKey, id: $id, changes: $changes);
			}
		}
	}//end setDependents()

	/**
	 * Run a named transition on the case of the target, or of an object it references.
	 *
	 * @param array<string, mixed> $step     The step: `transition`, optional `via` and `viaSchema`.
	 * @param array<string, mixed> $cascade  The cascade.
	 * @param array<string, mixed> $target   The target.
	 * @param string               $targetId The target uuid, for the comment.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no case can be found to run it on.
	 */
	private function transition(array $step, array $cascade, array $target, string $targetId): void {
		$caseField = (string)($cascade['caseField'] ?? 'case');
		$holder    = $target;
		$via       = $this->text(value: ($step['via'] ?? ''));
		if ($via !== '') {
			$holder = $this->findObjectAsArray(
				objectService: $this->objectService(),
				register: $this->settingsService->getConfigValue(key: 'register'),
				schema: $this->schema(key: $this->text(value: ($step['viaSchema'] ?? ''))),
				id: $this->text(value: ($target[$via] ?? ''))
			) ?? [];
		}

		$caseId = $this->text(value: ($holder[$caseField] ?? ''));
		if ($caseId === '') {
			throw new RuntimeException("Cascade transition '{$step['transition']}' found no case to run on.");
		}

		$this->transitions->execute(
			caseId: $caseId,
			transitionId: $step['transition'],
			comment: 'Cascade from '.$targetId,
		);
	}//end transition()

	/**
	 * Refuse before writing when the steps ask for an input the caller did not give.
	 *
	 * @param string               $name   The cascade, for the message.
	 * @param array<int, mixed>    $steps  The steps.
	 * @param array<string, mixed> $inputs The caller's values.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When an input is missing or not a scalar.
	 */
	private function guardInputs(string $name, array $steps, array $inputs): void {
		$asked = [];
		array_walk_recursive(
			$steps,
			static function (mixed $value, int|string $key) use (&$asked): void {
				if ($key === 'input' && is_string($value) === true) {
					$asked[] = $value;
				}
			}
		);

		foreach (array_unique($asked) as $input) {
			if (array_key_exists($input, $inputs) === false || is_scalar($inputs[$input]) === false) {
				throw new InvalidArgumentException("Cascade '$name' needs the input '$input'.");
			}
		}
	}//end guardInputs()

	/**
	 * Resolve `{"input": name}` values against the caller's inputs.
	 *
	 * @param array<string, mixed> $values The configured values.
	 * @param array<string, mixed> $inputs The caller's values.
	 *
	 * @return array<string, mixed> The values to write.
	 */
	private function values(array $values, array $inputs): array {
		$resolved = [];
		foreach ($values as $field => $value) {
			if (is_array($value) === true && is_string($value['input'] ?? null) === true) {
				$value = $inputs[$value['input']];
			}

			$resolved[$field] = $value;
		}

		return $resolved;
	}//end values()

	/**
	 * One cascade from app config.
	 *
	 * @param string $name The cascade.
	 *
	 * @return array<string, mixed> The cascade.
	 *
	 * @throws InvalidArgumentException When it is not configured or names no target schema.
	 */
	private function cascade(string $name): array {
		$all = json_decode($this->settingsService->getConfigValue(key: self::CASCADES_KEY), true);
		if (is_array($all) === false || is_array($all[$name] ?? null) === false) {
			throw new InvalidArgumentException("No cascade '$name' is configured.");
		}

		if ($this->text(value: ($all[$name]['target'] ?? '')) === '') {
			throw new InvalidArgumentException("Cascade '$name' names no target schema.");
		}

		return $all[$name];
	}//end cascade()

	/**
	 * The target of a cascade.
	 *
	 * @param array<string, mixed> $cascade  The cascade.
	 * @param string               $targetId The target uuid.
	 *
	 * @return array<string, mixed> The target.
	 *
	 * @throws InvalidArgumentException When it is not found.
	 */
	private function target(array $cascade, string $targetId): array {
		$target = $this->findObjectAsArray(
			objectService: $this->objectService(),
			register: $this->settingsService->getConfigValue(key: 'register'),
			schema: $this->schema(key: (string)$cascade['target']),
			id: $targetId
		);
		if ($target === null) {
			throw new InvalidArgumentException('The cascade target was not found.');
		}

		return $target;
	}//end target()

	/**
	 * Write fields onto one object.
	 *
	 * @param string               $schemaKey Config key of its schema.
	 * @param string               $id        Its uuid.
	 * @param array<string, mixed> $changes   The fields.
	 *
	 * @return array<string, mixed>|null The object after the write.
	 */
	private function write(string $schemaKey, string $id, array $changes): ?array {
		return $this->patchObjectAsArray(
			objectService: $this->objectService(),
			register: $this->settingsService->getConfigValue(key: 'register'),
			schema: $this->schema(key: $schemaKey),
			id: $id,
			changes: $changes
		);
	}//end write()

	/**
	 * The object service, or a refusal when OpenRegister is absent.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end objectService()

	/**
	 * The schema behind a config key, or a refusal naming the key.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is not configured.
	 */
	private function schema(string $key): string {
		$schema = '';
		if ($key !== '') {
			$schema = $this->settingsService->getConfigValue(key: $key);
		}

		if ($schema === '') {
			throw new RuntimeException("The schema '$key' is not configured.");
		}

		return $schema;
	}//end schema()

	/**
	 * A trimmed string, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
