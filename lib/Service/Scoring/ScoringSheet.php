<?php

/**
 * ScoringSheet
 *
 * Scores the submissions of a case with the scoring formula the case names.
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Scoring
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Scoring;

use InvalidArgumentException;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\ScoringSheetInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IL10N;

/**
 * The scoring sheet of a case.
 *
 * Where each value of the formula comes from, in this order:
 *  - `price` is the submission's own price and `lowestPrice` the lowest price
 *    among the case's scorable submissions; both are read, never entered.
 *  - A value the case holds under the variable's own name (a field such as
 *    `maxPoints` or `qualityWeight`) is fixed for every submission.
 *  - Every other variable is an input the evaluator enters per submission
 *    (`quality` in the price-quality formula).
 *
 * The case names its formula in `scoringFormula`, the key of a
 * `scoringFormula` record. A lot (a sub-case) that names none is scored by the
 * formula of the tender its submissions point at.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Scoring
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reads the case, its submissions and formulas, and writes the evaluation.
 */
class ScoringSheet implements ScoringSheetInterface {

	/**
	 * The case field that names the formula by its key.
	 *
	 * @var string
	 */
	public const FORMULA_FIELD = 'scoringFormula';

	/**
	 * The variables read from the submissions rather than entered or held by the case.
	 *
	 * @var list<string>
	 */
	public const DERIVED = ['price', 'lowestPrice'];

	/**
	 * The evaluation states the evaluator may still change.
	 *
	 * @var list<string>
	 */
	public const OPEN_EVALUATION_STATES = ['draft', 'submitted'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settings      The settings, which hand out the ObjectService.
	 * @param ScoringRecords            $records       Reads the submissions, evaluations and formulas.
	 * @param CaseObjectReaderInterface $cases         Reads a case by uuid.
	 * @param IL10N                     $l10n          Translates the refusals.
	 * @param FormulaScorer             $scorer        Evaluates a formula.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a stored row as an array.
	 * @param ScoringValue              $value         Reads numbers, case answers, ids and prices.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ScoringRecords $records,
		private readonly CaseObjectReaderInterface $cases,
		private readonly IL10N $l10n,
		private readonly FormulaScorer $scorer = new FormulaScorer(),
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
		private readonly ScoringValue $value = new ScoringValue(),
	) {
	}//end __construct()

	/**
	 * The scoring sheet of a case.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $userId The evaluator's user id.
	 *
	 * @return array<string, mixed> The sheet.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function forCase(string $caseId, string $userId): array {
		$submissions = $this->records->scorableSubmissions(caseId: $caseId);
		$formula     = $this->formulaFor(caseId: $caseId, submissions: $submissions);
		if (isset($formula['refusal']) === true) {
			return ['formula' => null, 'inputs' => [], 'fixed' => [], 'submissions' => [], 'refusal' => $formula['refusal']];
		}

		$evaluations = $this->records->evaluationsBy(userId: $userId, submissions: $submissions);
		$rows        = [];
		foreach ($submissions as $submission) {
			$id     = $this->value->idOf(row: $submission);
			$rows[] = [
				'id' => $id,
				'label' => $this->value->labelOf(submission: $submission),
				'price' => $this->value->numberOf(value: ($submission['priceAmount'] ?? null)),
				'total' => $this->value->numberOf(value: ($evaluations[$id]['weightedTotal'] ?? null)),
				'scores' => (array)($evaluations[$id]['criterionScores'] ?? []),
			];
		}

		$refusal = null;
		if ($rows === []) {
			$refusal = $this->l10n->t('There is no admissible bid to score on this case.');
		}

		return [
			'formula' => ['key' => $formula['key'], 'title' => (string)($formula['record']['title'] ?? $formula['key'])],
			'inputs' => $formula['inputs'],
			'fixed' => $formula['fixed'],
			'submissions' => $rows,
			'refusal' => $refusal,
		];
	}//end forCase()

	/**
	 * Score one submission and store the caller's evaluation of it.
	 *
	 * @param string               $caseId       The case uuid.
	 * @param string               $submissionId The submission uuid.
	 * @param array<string, mixed> $scores       The evaluator's value per input.
	 * @param string               $userId       The evaluator's user id.
	 *
	 * @return array<string, mixed> `{evaluationId, total}` or `{refusal}`.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function score(string $caseId, string $submissionId, array $scores, string $userId): array {
		$submissions = $this->records->scorableSubmissions(caseId: $caseId);
		$submission  = $this->submissionById(submissions: $submissions, submissionId: $submissionId);
		if ($submission === null) {
			return ['refusal' => $this->l10n->t('Only an admissible bid on this case can be scored.')];
		}

		$formula = $this->formulaFor(caseId: $caseId, submissions: $submissions);
		if (isset($formula['refusal']) === true) {
			return ['refusal' => $formula['refusal']];
		}

		$values = $this->valuesFor(formula: $formula, submission: $submission, submissions: $submissions, scores: $scores);
		if (isset($values['refusal']) === true) {
			return ['refusal' => $values['refusal']];
		}

		try {
			$total = round($this->scorer->score(formula: $formula['record'], values: $values['values']), 2);
		} catch (InvalidArgumentException $e) {
			return ['refusal' => $this->l10n->t('The scoring formula could not be applied: %s', [$e->getMessage()])];
		}

		$stored = $this->storeEvaluation(
			submission: $submission,
			caseId: $caseId,
			scores: $values['entered'],
			total: $total,
			userId: $userId
		);
		if (isset($stored['refusal']) === true) {
			return $stored;
		}

		return ['evaluationId' => $stored['id'], 'total' => $total];
	}//end score()

	/**
	 * The formula the case scores by, split into fixed values and inputs.
	 *
	 * @param string                           $caseId      The case uuid.
	 * @param list<array<string, mixed>>       $submissions The case's scorable submissions.
	 *
	 * @return array<string, mixed> `{key, record, fixed, inputs}` or `{refusal}`.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function formulaFor(string $caseId, array $submissions): array {
		$case = $this->formulaCase(caseId: $caseId, submissions: $submissions);
		$key  = trim((string)$this->value->caseValue(case: $case, field: self::FORMULA_FIELD));
		if ($key === '') {
			return ['refusal' => $this->l10n->t('This case names no scoring formula. Set the scoring formula on the case first.')];
		}

		$record = $this->records->formulaRecord(key: $key);
		if ($record === null) {
			return ['refusal' => $this->l10n->t('The scoring formula "%s" is not in the register, or may no longer be chosen.', [$key])];
		}

		$fixed  = [];
		$inputs = [];
		foreach ((array)($record['variables'] ?? []) as $variable) {
			$name = (string)$variable;
			if ($name === '' || in_array($name, self::DERIVED, true) === true) {
				continue;
			}

			$value = $this->value->numberOf(value: $this->value->caseValue(case: $case, field: $name));
			if ($value !== null) {
				$fixed[$name] = $value;
				continue;
			}

			$inputs[] = $name;
		}

		return ['key' => $key, 'record' => $record, 'fixed' => $fixed, 'inputs' => $inputs];
	}//end formulaFor()

	/**
	 * The case that names the formula: the case itself, or the tender its
	 * submissions point at when the case (a lot) names none.
	 *
	 * @param string                     $caseId      The case uuid.
	 * @param list<array<string, mixed>> $submissions The case's scorable submissions.
	 *
	 * @return array<string, mixed> The case, or [] when it cannot be read.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function formulaCase(string $caseId, array $submissions): array {
		$case = ($this->cases->fetchCaseById(caseUuid: $caseId) ?? []);
		if (trim((string)$this->value->caseValue(case: $case, field: self::FORMULA_FIELD)) !== '') {
			return $case;
		}

		foreach ($submissions as $submission) {
			$tender = (string)($submission['tender'] ?? '');
			if ($tender !== '' && $tender !== $caseId) {
				return ($this->cases->fetchCaseById(caseUuid: $tender) ?? $case);
			}
		}

		return $case;
	}//end formulaCase()

	/**
	 * Every value the formula needs for one submission, or why one is missing.
	 *
	 * @param array<string, mixed>       $formula     The formula split as formulaFor() answers it.
	 * @param array<string, mixed>       $submission  The submission being scored.
	 * @param list<array<string, mixed>> $submissions The case's scorable submissions.
	 * @param array<string, mixed>       $scores      The evaluator's values.
	 *
	 * @return array<string, mixed> `{values, entered}` or `{refusal}`.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function valuesFor(array $formula, array $submission, array $submissions, array $scores): array {
		$entered = [];
		foreach ($formula['inputs'] as $input) {
			$value = $this->value->numberOf(value: ($scores[$input] ?? null));
			if ($value === null) {
				return ['refusal' => $this->l10n->t('Enter a number for %s.', [$input])];
			}

			$entered[$input] = $value;
		}

		$values    = ($formula['fixed'] + $entered);
		$variables = array_map('strval', (array)($formula['record']['variables'] ?? []));
		if (array_intersect(self::DERIVED, $variables) !== []) {
			$price  = $this->value->numberOf(value: ($submission['priceAmount'] ?? null));
			$lowest = $this->value->lowestPriceOf(submissions: $submissions);
			if ($price === null || $price <= 0.0 || $lowest === null) {
				return ['refusal' => $this->l10n->t('This bid names no price, and the scoring formula scores the price.')];
			}

			$values['price']       = $price;
			$values['lowestPrice'] = $lowest;
		}

		return ['values' => $values, 'entered' => $entered];
	}//end valuesFor()

	/**
	 * Create or update the caller's evaluation of the submission.
	 *
	 * @param array<string, mixed> $submission The submission.
	 * @param string               $caseId     The case uuid.
	 * @param array<string, float> $scores     The values the evaluator entered.
	 * @param float                $total      The formula's total.
	 * @param string               $userId     The evaluator's user id.
	 *
	 * @return array<string, mixed> `{id}` or `{refusal}`.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function storeEvaluation(array $submission, string $caseId, array $scores, float $total, string $userId): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return ['refusal' => $this->l10n->t('The register cannot be reached. Try again later.')];
		}

		$submissionId = $this->value->idOf(row: $submission);
		$existing     = ($this->records->evaluationsBy(userId: $userId, submissions: [$submission])[$submissionId] ?? null);
		if ($existing !== null && in_array((string)($existing['state'] ?? ''), self::OPEN_EVALUATION_STATES, true) === false) {
			return ['refusal' => $this->l10n->t('Your evaluation of this bid is no longer open, so its score cannot change.')];
		}

		$payload = [
			'bid' => $submissionId,
			'tender' => (string)($submission['tender'] ?? $caseId),
			'evaluator' => $userId,
			'criterionScores' => $scores,
			'weightedTotal' => $total,
			'state' => (string)($existing['state'] ?? 'submitted'),
		];
		$existingId = null;
		if ($existing !== null) {
			$existingId = $this->value->idOf(row: $existing);
		}

		$saved = $objectService->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: AppIdentity::REGISTER_SLUG, schema: ScoringRecords::EVALUATION_SCHEMA),
				uuid: $existingId
			)
		);

		$normalised = $this->rowNormaliser->normalise(row: $saved);
		$id         = $this->value->idOf(row: $normalised['data']);
		if ($id === '') {
			$id = (string)$normalised['uuid'];
		}

		return ['id' => $id];
	}//end storeEvaluation()

	/**
	 * One scorable submission by id.
	 *
	 * @param list<array<string, mixed>> $submissions  The case's scorable submissions.
	 * @param string                     $submissionId The id asked for.
	 *
	 * @return array<string, mixed>|null The submission.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function submissionById(array $submissions, string $submissionId): ?array {
		foreach ($submissions as $submission) {
			if ($submissionId !== '' && $this->value->idOf(row: $submission) === $submissionId) {
				return $submission;
			}
		}

		return null;
	}//end submissionById()

}//end class
