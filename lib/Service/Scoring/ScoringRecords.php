<?php

/**
 * ScoringRecords
 *
 * Reads the submissions, evaluations and formulas a scoring sheet works on.
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

use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ReadsRegisterRows;


/**
 * Reads the submissions, evaluations and formulas a scoring sheet works on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Scoring
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
class ScoringRecords {
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
	 * The submission states that can be scored; a bid proposed for the award stays on the sheet.
	 *
	 * @var list<string>
	 */
	public const SCORABLE_STATES = ['admissible', 'evaluated', 'proposed-award'];

	/**
	 * How many rows one read takes (read by ReadsRegisterRows).
	 *
	 * @var int
	 */
	public const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settings      The settings, which hand out the ObjectService.
	 * @param OpenRegisterRowNormaliser $rowNormaliser Reads a stored row as an array.
	 * @param ScoringValue              $value         Reads numbers, case answers, ids and prices.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly OpenRegisterRowNormaliser $rowNormaliser = new OpenRegisterRowNormaliser(),
		private readonly ScoringValue $value = new ScoringValue(),
	) {
	}//end __construct()

	/**
	 * The case's submissions in a scorable state.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return list<array<string, mixed>> The submissions.
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
	 */
	public function scorableSubmissions(string $caseId): array {
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
	public function evaluationsBy(string $userId, array $submissions): array {
		$found = [];
		foreach ($submissions as $submission) {
			$id = $this->value->idOf(row: $submission);
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
	public function formulaRecord(string $key): ?array {
		foreach ($this->rows(filters: ['schema' => self::FORMULA_SCHEMA, 'key' => $key]) as $record) {
			if ((string)($record['key'] ?? '') === $key && ($record['active'] ?? true) !== false) {
				return $record;
			}
		}

		return null;
	}//end formulaRecord()

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
			if ($this->value->idOf(row: $data) === '') {
				$data['id'] = $normalised['uuid'];
			}

			$found[] = $data;
		}

		return $found;
	}//end rows()
}//end class
