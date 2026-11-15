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
	 * The row builder, see {@see rows()}.
	 *
	 * @var RebindImpactRows|null
	 */
	private ?RebindImpactRows $rows = null;

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
	 * The row builder, built on first use from the store and the converter.
	 *
	 * @return RebindImpactRows
	 */
	private function rows(): RebindImpactRows {
		$this->rows ??= new RebindImpactRows(store: $this->store, converter: $this->converter);

		return $this->rows;
	}//end rows()

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
		$choice = new RebindChoice(caseTypeId: $targetCaseTypeId, statusId: $targetStatusId, remap: $remap, answers: $answers);

		return $this->impactOf(case: $case, choice: $choice);
	}//end compute()

	/**
	 * The impact of rebinding this case as the coordinator chose.
	 *
	 * @param array<string, mixed> $case   The case as read.
	 * @param RebindChoice         $choice The target, landing status, remaps and answers.
	 *
	 * @return (array<int, array<string, mixed>>|bool)[] The `dropped`, `ported` and `required` rows, and `complete`.
	 *
	 * @psalm-return array{dropped: list<array<string, mixed>>, ported: list<array<string, mixed>>, required: list<array<string, mixed>>, complete: bool}
	 *
	 * @throws RefusedException When a remap names an answer or a field that does not exist, is taken, or does not fit.
	 */
	private function impactOf(array $case, RebindChoice $choice): array {
		$run = new RebindImpactRun(
			sourceDefs: $this->definitionsOf(caseTypeId: $this->store->referenceId(value: ($case['caseType'] ?? ''))),
			targetDefs: $this->definitionsOf(caseTypeId: $choice->caseTypeId),
			remap: $this->comparableRemap(remap: $choice->remap)
		);

		$this->portByName(run: $run, entries: $this->answers->entriesOf(case: $case));
		$this->portRemapped(run: $run);
		$run->dropped = $this->withCandidates(run: $run);

		return $this->impact(run: $run, required: $this->requiredRows(run: $run, choice: $choice));
	}//end impactOf()

	/**
	 * The remaps keyed by comparable source name, each target name trimmed, empty ones left out.
	 *
	 * @param array<string, string> $remap Source answer name => target field name.
	 *
	 * @return array<string, string> The remaps.
	 */
	private function comparableRemap(array $remap): array {
		return array_filter(
			array_map(static fn (mixed $name): string => trim((string)$name), $this->answers->keyed(values: $remap)),
			static fn (string $name): bool => $name !== ''
		);
	}//end comparableRemap()

	/**
	 * The impact's four parts.
	 *
	 * @param RebindImpactRun                  $run      The walked impact.
	 * @param array<int, array<string, mixed>> $required The required rows.
	 *
	 * @return (array<int, array<string, mixed>>|bool)[] The `dropped`, `ported` and `required` rows, and `complete`.
	 *
	 * @psalm-return array{dropped: list<array<string, mixed>>, ported: list<array<string, mixed>>, required: list<array<string, mixed>>, complete: bool}
	 */
	private function impact(RebindImpactRun $run, array $required): array {
		return [
			'dropped' => $run->dropped,
			'ported' => $run->ported,
			'required' => $required,
			'complete' => in_array(false, array_column($required, 'valid'), true) === false,
		];
	}//end impact()

	/**
	 * Carry every answer onto the target field of the same name, or drop it.
	 *
	 * Answers the coordinator remapped are set aside for {@see portRemapped()}:
	 * an explicit choice is never second-guessed by a name match.
	 *
	 * @param RebindImpactRun                                                    $run     The impact.
	 * @param array<int, array{name: string, definition: string, value: string}> $entries The case's answers.
	 *
	 * @return void
	 */
	private function portByName(RebindImpactRun $run, array $entries): void {
		foreach ($entries as $entry) {
			$answer = new RebindAnswer(
				key: $this->answers->key(name: $entry['name']),
				entry: $entry,
				source: $this->sourceDefinition(entry: $entry, sourceDefs: $run->sourceDefs)
			);
			if (array_key_exists($answer->key, $run->remap) === true) {
				$run->deferred[$answer->key] = $answer;
				continue;
			}

			$this->portOrDrop(run: $run, answer: $answer);
		}
	}//end portByName()

	/**
	 * Port an answer onto the free target field of its name, or drop it.
	 *
	 * @param RebindImpactRun $run    The impact.
	 * @param RebindAnswer    $answer The answer.
	 *
	 * @return void
	 */
	private function portOrDrop(RebindImpactRun $run, RebindAnswer $answer): void {
		$target = ($run->targetDefs[$answer->key] ?? []);
		$fitted = $this->fitByName(run: $run, answer: $answer);
		if ($fitted === null) {
			$run->dropped[] = $this->rows()->droppedRow(answer: $answer, target: $target);
			return;
		}

		$run->taken[$answer->key] = true;
		$run->ported[] = $this->rows()->portedRow(answer: $answer, landing: new RebindLanding(target: $target, value: $fitted, remapped: false));
	}//end portOrDrop()

	/**
	 * The answer's value as the free target field of its name holds it, or null.
	 *
	 * @param RebindImpactRun $run    The impact.
	 * @param RebindAnswer    $answer The answer.
	 *
	 * @return string|null The fitted value; null when there is no such free field or the value does not fit.
	 */
	private function fitByName(RebindImpactRun $run, RebindAnswer $answer): ?string {
		$target = ($run->targetDefs[$answer->key] ?? []);
		if ($target === [] || isset($run->taken[$answer->key]) === true) {
			return null;
		}

		return $this->converter->fit(value: $answer->entry['value'], source: $answer->source, target: $target);
	}//end fitByName()

	/**
	 * Carry every remapped answer onto the field the coordinator chose.
	 *
	 * @param RebindImpactRun $run The impact, after {@see portByName()}.
	 *
	 * @return void
	 *
	 * @throws RefusedException When a remap cannot be made.
	 */
	private function portRemapped(RebindImpactRun $run): void {
		foreach (array_keys($run->remap) as $sourceKey) {
			$found = ($run->deferred[$sourceKey] ?? null);
			if ($found === null) {
				$this->refuseRemap(rule: 'rebind-remap-unknown-answer', sentence: 'This case has no answer called ' . $sourceKey . ' to move.');
			}

			$this->portOneRemapped(run: $run, answer: $found);
		}
	}//end portRemapped()

	/**
	 * Carry one remapped answer onto the field the coordinator chose for it.
	 *
	 * @param RebindImpactRun $run    The impact.
	 * @param RebindAnswer    $answer The remapped answer.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the field does not exist, is taken, or the value does not fit.
	 */
	private function portOneRemapped(RebindImpactRun $run, RebindAnswer $answer): void {
		$targetName = $run->remap[$answer->key];
		$targetKey = $this->answers->key(name: $targetName);
		$target = $this->remapTarget(run: $run, targetName: $targetName);
		$fitted = $this->converter->fit(value: $answer->entry['value'], source: $answer->source, target: $target);
		if ($fitted === null) {
			$this->refuseRemap(
				rule: 'rebind-remap-does-not-fit',
				sentence: 'The value of ' . $answer->entry['name'] . ' does not fit the field ' . $targetName . '.'
			);
		}

		$run->taken[$targetKey] = true;
		$run->ported[] = $this->rows()->portedRow(answer: $answer, landing: new RebindLanding(target: $target, value: $fitted, remapped: true));
	}//end portOneRemapped()

	/**
	 * The target field a remap names, when it exists and is still free.
	 *
	 * @param RebindImpactRun $run        The impact: the target definitions and the fields taken.
	 * @param string          $targetName The field asked for.
	 *
	 * @return array<string, mixed> The field.
	 *
	 * @throws RefusedException When it does not exist or is taken.
	 */
	private function remapTarget(RebindImpactRun $run, string $targetName): array {
		$targetKey = $this->answers->key(name: $targetName);
		$target = ($run->targetDefs[$targetKey] ?? []);
		if ($target === []) {
			$this->refuseRemap(rule: 'rebind-remap-unknown-field', sentence: 'The target case type has no field called ' . $targetName . '.');
		}

		if (isset($run->taken[$targetKey]) === true) {
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
	 * Name, for every dropped answer, the free target fields its value fits.
	 *
	 * @param RebindImpactRun $run The impact, its answers ported and dropped.
	 *
	 * @return array<int, array<string, mixed>> The dropped rows, with `candidates`.
	 */
	private function withCandidates(RebindImpactRun $run): array {
		$dropped = $run->dropped;
		foreach ($dropped as $index => $row) {
			$source = $this->sourceDefinition(entry: $row, sourceDefs: $run->sourceDefs);
			foreach ($run->targetDefs as $key => $target) {
				if (isset($run->taken[$key]) === true) {
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
	 * @param RebindImpactRun $run    The impact: the target definitions and the fields taken.
	 * @param RebindChoice    $choice The landing status and the answers given.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function requiredRows(RebindImpactRun $run, RebindChoice $choice): array {
		$answers = $this->answers->keyed(values: $choice->answers);
		$rows = [];
		foreach ($run->targetDefs as $key => $target) {
			if (isset($run->taken[$key]) === true || $this->isRequired(definition: $target, statusId: $choice->statusId) === false) {
				continue;
			}

			$rows[] = $this->rows()->requiredRow(target: $target, value: $this->converter->asText(value: ($answers[$key] ?? null)));
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
