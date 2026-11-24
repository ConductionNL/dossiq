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
use OCA\Dossiq\Service\Support\ReadsRegisterRows;
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
	use ReadsRegisterRows;

	/**
	 * The schema of the submissions that are scored.
	 *
	 * @var string
	 */
	public const SUBMISSION_SCHEMA = 'bid';

	/**
	 * The schema of the evaluations the scores are stored in.
	 *
	 * @var string
	 */
	public const EVALUATION_SCHEMA = 'evaluation';

	/**
	 * The schema of the formulas.
	 *
	 * @var string
	 */
	public const FORMULA_SCHEMA = 'scoringFormula';

	/**
	 * The case field that names the formula by its key.
	 *
	 * @var string
	 */
	public const FORMULA_FIELD = 'scoringFormula';

	/**
	 * The submission states that can be scored.
	 *
	 * @var list<string>
	 */
	public const SCORABLE_STATES = ['admissible', 'evaluated'];

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
	 * How many rows one read takes.
	 *
	 * @var int
	 */
	private const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settings      The settings, which hand out the ObjectService.
	 * @param CaseObjectReaderInterface $cases         Reads a case by uuid.
	 * @param IL10N                     $l10n          Translates the refusals.
	 * @param FormulaScorer             $scorer        Evaluates a formula.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a stored row as an array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly CaseObjectReaderInterface $cases,
		private readonly IL10N $l10n,
		private readonly FormulaScorer $scorer = new FormulaScorer(),
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
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
		$submissions = $this->scorableSubmissions(caseId: $caseId);
		$formula     = $this->formulaFor(caseId: $caseId, submissions: $submissions);
		if (isset($formula['refusal']) === true) {
			return ['formula' => null, 'inputs' => [], 'fixed' => [], 'submissions' => [], 'refusal' => $formula['refusal']];
		}

		$evaluations = $this->evaluationsBy(userId: $userId, submissions: $submissions);
		$rows        = [];
		foreach ($submissions as $submission) {
			$id     = $this->idOf(row: $submission);
			$rows[] = [
				'id' => $id,
				'label' => $this->labelOf(submission: $submission),
				'price' => $this->numberOf(value: ($submission['priceAmount'] ?? null)),
				'total' => $this->numberOf(value: ($evaluations[$id]['weightedTotal'] ?? null)),
				'scores' => (array)($evaluations[$id]['criterionScores'] ?? []),
			];
		}

		return [
			'formula' => ['key' => $formula['key'], 'title' => (string)($formula['record']['title'] ?? $formula['key'])],
			'inputs' => $formula['inputs'],
			'fixed' => $formula['fixed'],
			'submissions' => $rows,
			'refusal' => ($rows === [] ? $this->l10n->t('There is no admissible bid to score on this case.') : null),
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
		$submissions = $this->scorableSubmissions(caseId: $caseId);
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
		$key  = trim((string)$this->caseValue(case: $case, field: self::FORMULA_FIELD));
		if ($key === '') {
			return ['refusal' => $this->l10n->t('This case names no scoring formula. Set the scoring formula on the case first.')];
		}

		$record = $this->formulaRecord(key: $key);
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

			$value = $this->numberOf(value: $this->caseValue(case: $case, field: $name));
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
		if (trim((string)$this->caseValue(case: $case, field: self::FORMULA_FIELD)) !== '') {
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
			$value = $this->numberOf(value: ($scores[$input] ?? null));
			if ($value === null) {
				return ['refusal' => $this->l10n->t('Enter a number for %s.', [$input])];
			}

			$entered[$input] = $value;
		}

		$values    = ($formula['fixed'] + $entered);
		$variables = array_map('strval', (array)($formula['record']['variables'] ?? []));
		if (array_intersect(self::DERIVED, $variables) !== []) {
			$price  = $this->numberOf(value: ($submission['priceAmount'] ?? null));
			$lowest = $this->lowestPriceOf(submissions: $submissions);
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

		$submissionId = $this->idOf(row: $submission);
		$existing     = ($this->evaluationsBy(userId: $userId, submissions: [$submission])[$submissionId] ?? null);
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
		$saved   = $objectService->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: AppIdentity::REGISTER_SLUG, schema: self::EVALUATION_SCHEMA),
				uuid: ($existing === null ? null : $this->idOf(row: $existing))
			)
		);

		$normalised = $this->rowNormaliser->normalise(row: $saved);
		$id         = $this->idOf(row: $normalised['data']);

		return ['id' => ($id !== '' ? $id : (string)$normalised['uuid'])];
	}//end storeEvaluation()

	/**
	 * The case's submissions in a scorable state.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return list<array<string, mixed>> The submissions.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function scorableSubmissions(string $caseId): array {
		return array_values(
			array_filter(
				$this->rows(filters: ['schema' => self::SUBMISSION_SCHEMA, 'case' => $caseId]),
				static fn (array $row): bool => in_array((string)($row['state'] ?? ''), self::SCORABLE_STATES, true)
			)
		);
	}//end scorableSubmissions()

	/**
	 * The caller's evaluations of the given submissions, by submission id.
	 *
	 * @param string                     $userId      The evaluator's user id.
	 * @param list<array<string, mixed>> $submissions The submissions.
	 *
	 * @return array<string, array<string, mixed>> The evaluations.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function evaluationsBy(string $userId, array $submissions): array {
		$found = [];
		foreach ($submissions as $submission) {
			$id = $this->idOf(row: $submission);
			foreach ($this->rows(filters: ['schema' => self::EVALUATION_SCHEMA, 'bid' => $id, 'evaluator' => $userId]) as $evaluation) {
				if ((string)($evaluation['bid'] ?? '') === $id && (string)($evaluation['evaluator'] ?? '') === $userId) {
					$found[$id] = $evaluation;
				}
			}
		}

		return $found;
	}//end evaluationsBy()

	/**
	 * The active formula record with the key.
	 *
	 * @param string $key The formula key.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function formulaRecord(string $key): ?array {
		foreach ($this->rows(filters: ['schema' => self::FORMULA_SCHEMA, 'key' => $key]) as $record) {
			if ((string)($record['key'] ?? '') === $key && ($record['active'] ?? true) !== false) {
				return $record;
			}
		}

		return null;
	}//end formulaRecord()

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
			if ($submissionId !== '' && $this->idOf(row: $submission) === $submissionId) {
				return $submission;
			}
		}

		return null;
	}//end submissionById()

	/**
	 * The lowest positive price among the submissions.
	 *
	 * @param list<array<string, mixed>> $submissions The submissions.
	 *
	 * @return float|null The lowest price, or null when none names one.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function lowestPriceOf(array $submissions): ?float {
		$lowest = null;
		foreach ($submissions as $submission) {
			$price = $this->numberOf(value: ($submission['priceAmount'] ?? null));
			if ($price !== null && $price > 0.0 && ($lowest === null || $price < $lowest)) {
				$lowest = $price;
			}
		}

		return $lowest;
	}//end lowestPriceOf()

	/**
	 * A case value, on the case itself or among its property answers.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param string               $field The field name.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function caseValue(array $case, string $field): mixed {
		if (isset($case[$field]) === true) {
			return $case[$field];
		}

		foreach ((array)($case['properties'] ?? []) as $entry) {
			if (is_array($entry) === true && (string)($entry['name'] ?? '') === $field) {
				return ($entry['value'] ?? null);
			}
		}

		return null;
	}//end caseValue()

	/**
	 * How a submission is named in the sheet.
	 *
	 * @param array<string, mixed> $submission The submission.
	 *
	 * @return string The reference, else the supplier, else the id.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function labelOf(array $submission): string {
		foreach (['submissionRef', 'supplier'] as $field) {
			$value = trim((string)($submission[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return $this->idOf(row: $submission);
	}//end labelOf()

	/**
	 * A number, from a number or a numeric string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null The number, or null.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function numberOf(mixed $value): ?float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric(trim($value)) === true) {
			return (float)trim($value);
		}

		return null;
	}//end numberOf()

	/**
	 * The id of a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? ''));

		return (is_string($id) === true ? $id : '');
	}//end idOf()

	/**
	 * Register rows matching the filters, each read as an array with its id.
	 *
	 * @param array<string, string> $filters The filters, `schema` included.
	 *
	 * @return list<array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	private function rows(array $filters): array {
		$found = [];
		foreach ($this->registerRows(id: (string)($filters['schema'] ?? ''), filters: $filters) as $row) {
			$normalised = $this->rowNormaliser->normalise(row: $row);
			$data       = $normalised['data'];
			if ($this->idOf(row: $data) === '') {
				$data['id'] = $normalised['uuid'];
			}

			$found[] = $data;
		}

		return $found;
	}//end rows()
}//end class
