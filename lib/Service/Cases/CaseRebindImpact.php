<?php

/**
 * Dossiq case rebind impact.
 *
 * What a rebind does to the answers a case already carries: which ones the
 * target type has no field for (dropped), which ones carry over and where
 * (ported), and which fields the target requires that are still empty
 * (required). The preview shows it, and the rebind applies exactly it, so the
 * dialog and the write cannot disagree about what happens to an answer.
 *
 * The answers are read by {@see CaseAnswerReader}, which knows the register
 * stores them as a list; {@see apply()} writes that list back.
 *
 * Answers are matched by field NAME, case-insensitively. Across two case types
 * a name is the only thing two fields can share, and the coordinator sees
 * every match before confirming. A same-named answer whose value does not fit
 * the target field is dropped, never converted by force: see
 * {@see RebindValueConverter}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;

/**
 * Dropped, ported and required: one case onto one target case type.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
class CaseRebindImpact {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore        $store     The app's one case type reader.
	 * @param CaseTypeResolver     $resolver  Property definitions of one case type, inherited and shared included.
	 * @param RebindValueConverter $converter Whether an answer fits a field.
	 * @param CaseAnswerReader     $answers   The case's answers, in the register's shape.
	 */
	public function __construct(
		private readonly CaseTypeStore $store,
		private readonly CaseTypeResolver $resolver,
		private readonly RebindValueConverter $converter,
		private readonly CaseAnswerReader $answers,
	) {
	}//end __construct()

	/**
	 * The impact of rebinding this case onto that type, landing in that status.
	 *
	 * @param array<string, mixed>  $case             The case as read.
	 * @param string                $targetCaseTypeId The target case type.
	 * @param string                $targetStatusId   The landing status, or '' when none is chosen yet.
	 * @param array<string, string> $remap            Source answer name => target field name, chosen by the coordinator.
	 * @param array<string, mixed>  $answers          Target field name => answer, given in the dialog.
	 *
	 * @return (array<int, array<string, mixed>>|bool)[] The `dropped`, `ported` and `required` rows, and `complete`.
	 *
	 * @psalm-return array{dropped: list<array<string, mixed>>, ported: list<array<string, mixed>>, required: list<array<string, mixed>>, complete: bool}
	 *
	 * @throws RefusedException When a remap names an answer or a field that does not exist, is taken, or does not fit.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function compute(
		array $case,
		string $targetCaseTypeId,
		string $targetStatusId,
		array $remap = [],
		array $answers = [],
	): array {
		$sourceDefs = $this->definitionsOf(caseTypeId: $this->store->referenceId(value: ($case['caseType'] ?? '')));
		$targetDefs = $this->definitionsOf(caseTypeId: $targetCaseTypeId);
		$remap = array_filter(
			array_map(static fn (mixed $name): string => trim((string)$name), $this->answers->keyed(values: $remap)),
			static fn (string $name): bool => $name !== ''
		);

		$state = $this->portByName(
			entries: $this->answers->entriesOf(case: $case),
			remap: $remap,
			sourceDefs: $sourceDefs,
			targetDefs: $targetDefs
		);
		$state = $this->portRemapped(state: $state, remap: $remap, targetDefs: $targetDefs);

		$dropped = $this->withCandidates(dropped: $state['dropped'], targetDefs: $targetDefs, taken: $state['taken'], sourceDefs: $sourceDefs);
		$required = $this->requiredRows(
			targetDefs: $targetDefs,
			taken: $state['taken'],
			statusId: $targetStatusId,
			answers: $this->answers->keyed(values: $answers)
		);

		return [
			'dropped' => $dropped,
			'ported' => $state['ported'],
			'required' => $required,
			'complete' => in_array(false, array_column($required, 'valid'), true) === false,
		];
	}//end compute()

	/**
	 * Carry every answer onto the target field of the same name, or drop it.
	 *
	 * Answers the coordinator remapped are set aside for {@see portRemapped()}:
	 * an explicit choice is never second-guessed by a name match.
	 *
	 * @param array<int, array{name: string, definition: string, value: string}> $entries    The case's answers.
	 * @param array<string, string>                                              $remap      Remaps, keyed by comparable source name.
	 * @param array<string, array<string, mixed>>                                $sourceDefs The source definitions.
	 * @param array<string, array<string, mixed>>                                $targetDefs The target definitions.
	 *
	 * @return array<string, array<mixed>> The state: `taken`, `ported`, `dropped` and `deferred`.
	 */
	private function portByName(array $entries, array $remap, array $sourceDefs, array $targetDefs): array {
		$state = ['taken' => [], 'ported' => [], 'dropped' => [], 'deferred' => []];
		foreach ($entries as $entry) {
			$key = $this->answers->key(name: $entry['name']);
			$source = $this->sourceDefinition(entry: $entry, sourceDefs: $sourceDefs);
			if (array_key_exists($key, $remap) === true) {
				$state['deferred'][$key] = ['entry' => $entry, 'source' => $source];
				continue;
			}

			$target = ($targetDefs[$key] ?? []);
			$fitted = null;
			if ($target !== [] && isset($state['taken'][$key]) === false) {
				$fitted = $this->converter->fit(value: $entry['value'], source: $source, target: $target);
			}

			if ($fitted === null) {
				$reason = 'absent';
				if ($target !== []) {
					$reason = 'type';
				}

				$state['dropped'][] = $this->droppedRow(entry: $entry, source: $source, reason: $reason);
				continue;
			}

			$state['taken'][$key] = true;
			$state['ported'][] = $this->portedRow(entry: $entry, source: $source, target: $target, value: $fitted, remapped: false);
		}//end foreach

		return $state;
	}//end portByName()

	/**
	 * Carry every remapped answer onto the field the coordinator chose.
	 *
	 * @param array<string, array<mixed>>         $state      What {@see portByName()} left.
	 * @param array<string, string>               $remap      Remaps, keyed by comparable source name.
	 * @param array<string, array<string, mixed>> $targetDefs The target definitions.
	 *
	 * @return array<string, array<mixed>> The state.
	 *
	 * @throws RefusedException When a remap cannot be made.
	 */
	private function portRemapped(array $state, array $remap, array $targetDefs): array {
		foreach ($remap as $sourceKey => $targetName) {
			$found = ($state['deferred'][$sourceKey] ?? null);
			if ($found === null) {
				$this->refuseRemap(rule: 'rebind-remap-unknown-answer', sentence: 'This case has no answer called ' . $sourceKey . ' to move.');
			}

			$targetKey = $this->answers->key(name: $targetName);
			$target = $this->remapTarget(targetDefs: $targetDefs, taken: $state['taken'], targetName: $targetName);
			$fitted = $this->converter->fit(value: $found['entry']['value'], source: $found['source'], target: $target);
			if ($fitted === null) {
				$this->refuseRemap(
					rule: 'rebind-remap-does-not-fit',
					sentence: 'The value of ' . $found['entry']['name'] . ' does not fit the field ' . $targetName . '.'
				);
			}

			$state['taken'][$targetKey] = true;
			$state['ported'][] = $this->portedRow(entry: $found['entry'], source: $found['source'], target: $target, value: $fitted, remapped: true);
		}

		return $state;
	}//end portRemapped()

	/**
	 * The target field a remap names, when it exists and is still free.
	 *
	 * @param array<string, array<string, mixed>> $targetDefs The target definitions.
	 * @param array<string, bool>                 $taken      Fields that already receive an answer.
	 * @param string                              $targetName The field asked for.
	 *
	 * @return array<string, mixed> The field.
	 *
	 * @throws RefusedException When it does not exist or is taken.
	 */
	private function remapTarget(array $targetDefs, array $taken, string $targetName): array {
		$targetKey = $this->answers->key(name: $targetName);
		$target = ($targetDefs[$targetKey] ?? []);
		if ($target === []) {
			$this->refuseRemap(rule: 'rebind-remap-unknown-field', sentence: 'The target case type has no field called ' . $targetName . '.');
		}

		if (isset($taken[$targetKey]) === true) {
			$this->refuseRemap(rule: 'rebind-remap-field-taken', sentence: 'The field ' . $targetName . ' already receives another answer.');
		}

		return $target;
	}//end remapTarget()

	/**
	 * The case's `properties`, rewritten to what the impact says.
	 *
	 * Ported answers land on the target field, under its definition and name;
	 * valid required answers are added; dropped answers are left out, which is
	 * why the journal records them before this runs.
	 *
	 * @param array<string, mixed>                    $case   The case.
	 * @param array{ported: array, required: array} $impact What {@see compute()} answered.
	 *
	 * @return array<string, mixed> The case, with `properties` as the register's list.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function apply(array $case, array $impact): array {
		$entries = [];
		foreach ($impact['ported'] as $row) {
			$entries[] = ['propertyDefinition' => $row['targetDefinition'], 'name' => $row['target'], 'value' => $row['newValue']];
		}

		foreach ($impact['required'] as $row) {
			if ($row['valid'] === true) {
				$entries[] = ['propertyDefinition' => $row['definition'], 'name' => $row['name'], 'value' => $row['normalized']];
			}
		}

		$case['properties'] = $entries;

		return $case;
	}//end apply()


	/**
	 * A case type's property definitions, keyed by comparable name.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return array<string, array<string, mixed>> The definitions.
	 */
	private function definitionsOf(string $caseTypeId): array {
		if ($caseTypeId === '') {
			return [];
		}

		$keyed = [];
		foreach ($this->resolver->propertyDefinitionsFor(caseTypeId: $caseTypeId) as $definition) {
			$key = $this->answers->key(name: (string)($definition['name'] ?? ''));
			if ($key !== '' && isset($keyed[$key]) === false) {
				$keyed[$key] = $definition;
			}
		}

		return $keyed;
	}//end definitionsOf()

	/**
	 * The source definition an answer was given under, by id first, then by name.
	 *
	 * @param array{name: string, definition: string, value: string} $entry      The answer.
	 * @param array<string, array<string, mixed>>                    $sourceDefs The source definitions.
	 *
	 * @return array<string, mixed> The definition, or [] when the source type declares none.
	 */
	private function sourceDefinition(array $entry, array $sourceDefs): array {
		if ($entry['definition'] !== '') {
			foreach ($sourceDefs as $definition) {
				if ($this->store->rowId(row: $definition) === $entry['definition']) {
					return $definition;
				}
			}
		}

		return ($sourceDefs[$this->answers->key(name: $entry['name'])] ?? []);
	}//end sourceDefinition()

	/**
	 * One ported row.
	 *
	 * @param array{name: string, definition: string, value: string} $entry    The answer.
	 * @param array<string, mixed>                                   $source   Its definition.
	 * @param array<string, mixed>                                   $target   The field it lands on.
	 * @param string                                                 $value    The value it lands with.
	 * @param boolean                                                $remapped Whether the coordinator chose the field.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function portedRow(array $entry, array $source, array $target, string $value, bool $remapped): array {
		$sourceKind = $this->sourceKind(source: $source);
		$targetKind = $this->converter->kindOf(definition: $target);

		$mapping = 'same';
		if ($remapped === true) {
			$mapping = 'remapped';
		} else if ($sourceKind !== $targetKind || $value !== $entry['value']) {
			$mapping = 'converted';
		}

		return [
			'source' => $entry['name'],
			'target' => trim((string)($target['name'] ?? '')),
			'targetDefinition' => $this->store->rowId(row: $target),
			'sourceKind' => $sourceKind,
			'targetKind' => $targetKind,
			'value' => $entry['value'],
			'newValue' => $value,
			'mapping' => $mapping,
		];
	}//end portedRow()

	/**
	 * One dropped row, before its candidates are known.
	 *
	 * @param array{name: string, definition: string, value: string} $entry  The answer.
	 * @param array<string, mixed>                                   $source Its definition.
	 * @param string                                                 $reason `absent` (no field of that name) or `type` (the value does not fit).
	 *
	 * @return array<string, mixed> The row.
	 */
	private function droppedRow(array $entry, array $source, string $reason): array {
		return [
			'name' => $entry['name'],
			'definition' => $entry['definition'],
			'kind' => $this->sourceKind(source: $source),
			'value' => $entry['value'],
			'reason' => $reason,
			'candidates' => [],
		];
	}//end droppedRow()

	/**
	 * Name, for every dropped answer, the free target fields its value fits.
	 *
	 * @param array<int, array<string, mixed>>    $dropped    The dropped rows.
	 * @param array<string, array<string, mixed>> $targetDefs The target definitions.
	 * @param array<string, bool>                 $taken      Target fields that already receive an answer.
	 * @param array<string, array<string, mixed>> $sourceDefs The source definitions.
	 *
	 * @return array<int, array<string, mixed>> The rows, with `candidates`.
	 */
	private function withCandidates(array $dropped, array $targetDefs, array $taken, array $sourceDefs): array {
		foreach ($dropped as $index => $row) {
			$source = $this->sourceDefinition(entry: $row, sourceDefs: $sourceDefs);
			foreach ($targetDefs as $key => $target) {
				if (isset($taken[$key]) === true) {
					continue;
				}

				if ($this->converter->fit(value: $row['value'], source: $source, target: $target) !== null) {
					$dropped[$index]['candidates'][] = trim((string)($target['name'] ?? ''));
				}
			}
		}

		return $dropped;
	}//end withCandidates()

	/**
	 * The target fields that are required and still empty, with what was answered.
	 *
	 * Required means `isRequired`, or `requiredAtStatus` equal to the landing
	 * status: the same declaration the status machinery publishes, so the
	 * dialog asks for exactly what the next status change would refuse.
	 *
	 * @param array<string, array<string, mixed>> $targetDefs The target definitions.
	 * @param array<string, bool>                 $taken      Fields that already receive an answer.
	 * @param string                              $statusId   The landing status, or ''.
	 * @param array<string, mixed>                $answers    Answers keyed by comparable name.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function requiredRows(array $targetDefs, array $taken, string $statusId, array $answers): array {
		$rows = [];
		foreach ($targetDefs as $key => $target) {
			if (isset($taken[$key]) === true || $this->isRequired(definition: $target, statusId: $statusId) === false) {
				continue;
			}

			$value = $this->converter->asText(value: ($answers[$key] ?? null));
			$normalized = $this->converter->fit(value: $value, source: [], target: $target);

			$rows[] = [
				'name' => trim((string)($target['name'] ?? '')),
				'definition' => $this->store->rowId(row: $target),
				'kind' => $this->converter->kindOf(definition: $target),
				'choices' => $this->converter->choicesOf(definition: $target),
				'description' => trim((string)($target['description'] ?? '')),
				'value' => $value,
				'normalized' => $normalized,
				'valid' => ($normalized !== null),
			];
		}

		return $rows;
	}//end requiredRows()

	/**
	 * Whether a target field must hold a value in the landing status.
	 *
	 * @param array<string, mixed> $definition The field.
	 * @param string               $statusId   The landing status, or ''.
	 *
	 * @return boolean True when it must.
	 */
	private function isRequired(array $definition, string $statusId): bool {
		if (($definition['isRequired'] ?? false) === true) {
			return true;
		}

		if ($statusId === '') {
			return false;
		}

		return $this->store->referenceId(value: ($definition['requiredAtStatus'] ?? '')) === $statusId;
	}//end isRequired()

	/**
	 * The kind of an answer, `text` when the source type declares no field for it.
	 *
	 * @param array<string, mixed> $source The definition, or [].
	 *
	 * @return string The kind.
	 */
	private function sourceKind(array $source): string {
		if ($source === []) {
			return 'text';
		}

		return $this->converter->kindOf(definition: $source);
	}//end sourceKind()



	/**
	 * Refuse a remap the coordinator cannot make.
	 *
	 * @param string $rule     The rule.
	 * @param string $sentence What to tell them.
	 *
	 * @return never
	 *
	 * @throws RefusedException Always.
	 */
	private function refuseRemap(string $rule, string $sentence): never {
		throw new RefusedException(
			rule: $rule,
			sentence: $sentence,
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end refuseRemap()
}//end class
