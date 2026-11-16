<?php

/**
 * Dossiq formula total on save.
 *
 * Writes the total of a scored record from the scoring formula its case
 * names, in the same save. Generic: a scored record names its subject (the
 * record it scores) and its case, carries its scores per criterion, and gets
 * a total. The case names the formula by key in one of its fields; the
 * formula is a stored record (REQ-EVA-003), so a new way of scoring is data.
 *
 * The values the formula reads are, by name: every numeric score of the
 * record, every numeric case field or answered case property, the subject's
 * price, and the lowest price among the case's subjects still in the running.
 * A case that names no formula totals the scores as given (weighted points).
 * A formula that cannot be computed (a value it names is missing, a
 * division by zero) clears the total rather than keeping one that no longer
 * matches the scores; the reason is logged.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\SchemaMembership;
use OCA\Dossiq\Service\Scoring\FormulaScorer;
use OCA\Dossiq\Service\Support\ReferenceId;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Totals a scored record with its case's scoring formula before it is stored.
 *
 * @implements IEventListener<Event>
 *
 * @phpstan-type ScoredFields array{
 *     subject: string,
 *     case: string,
 *     scores: string,
 *     total: string,
 *     formula: string,
 *     subjectSchema: string,
 *     price: string,
 *     outOfRunning: list<string>
 * }
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */
class FormulaTotalOnSave implements IEventListener {

	use SearchesObjects;

	/**
	 * Per scored schema (by its config key): the field naming the subject,
	 * the field naming the case, the scores, the total, the case field naming
	 * the formula, the subject schema, the subject's price field, and the
	 * subject states that no longer count towards the lowest price.
	 *
	 * @var array<string, ScoredFields>
	 */
	public const SCORED = [
		'evaluation_schema' => [
			'subject' => 'bid',
			'case' => 'tender',
			'scores' => 'criterionScores',
			'total' => 'weightedTotal',
			'formula' => 'scoringFormula',
			'subjectSchema' => 'bid_schema',
			'price' => 'priceAmount',
			'outOfRunning' => ['inadmissible', 'excluded', 'withdrawn'],
		],
	];

	/**
	 * The config key of the schema the formulas are stored in.
	 */
	public const FORMULA_SCHEMA = 'scoring_formula_schema';

	/**
	 * The value names the subject's prices are offered to the formula under.
	 */
	public const PRICE = 'price';

	/**
	 * The value name of the lowest price in the running.
	 */
	public const LOWEST_PRICE = 'lowestPrice';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface  $settingsService Schema slug bridge and object service.
	 * @param CaseObjectReaderInterface $cases           Reads the case a scored record names.
	 * @param FormulaScorer             $scorer          Evaluates a stored formula.
	 * @param LoggerInterface           $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly CaseObjectReaderInterface $cases,
		private readonly FormulaScorer $scorer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set the total of a scored record that is being created or updated.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type
	 */
	public function handle(Event $event): void {
		$entity = $this->entityOf(event: $event);
		if ($entity === null) {
			return;
		}

		try {
			$record = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: formula total could not read the payload: ' . $e->getMessage());
			return;
		}

		$fields = $this->fieldsFor(record: $record);
		if ($fields === null) {
			return;
		}

		$total = $this->totalOf(record: $record, fields: $fields);
		$entity->setObject(array_merge($entity->getObject(), [$fields['total'] => $total]));
	}//end handle()

	/**
	 * The entity being written, or null for any other event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return ObjectEntity|null
	 */
	private function entityOf(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatingEvent === true) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return null;
	}//end entityOf()

	/**
	 * The scored fields of the schema this record belongs to, or null when it is not scored.
	 *
	 * @param array<string, mixed> $record The record being written (incl. `@self`).
	 *
	 * @return ScoredFields|null
	 */
	private function fieldsFor(array $record): ?array {
		foreach (self::SCORED as $configKey => $fields) {
			if (SchemaMembership::belongsTo(object: $record, expected: $this->settingsService->getConfigValue($configKey)) === true) {
				return $fields;
			}
		}

		return null;
	}//end fieldsFor()

	/**
	 * The record's total: the case's formula over its values, the plain sum without one, null when it cannot be computed.
	 *
	 * @param array<string, mixed> $record The scored record.
	 * @param ScoredFields         $fields Its fields.
	 *
	 * @return float|null
	 */
	private function totalOf(array $record, array $fields): ?float {
		$scores = self::numbers(values: ($record[$fields['scores']] ?? null));
		$caseId = ReferenceId::of(value: ($record[$fields['case']] ?? null));
		$case = null;
		if ($caseId !== '') {
			$case = $this->cases->fetchCaseById(caseUuid: $caseId);
		}

		$formulaKey = '';
		if ($case !== null) {
			$formulaKey = self::stringValue(value: self::caseValue(case: $case, field: $fields['formula']));
		}

		if ($formulaKey === '') {
			return round(array_sum($scores), 2);
		}

		$formula = $this->formulaNamed(key: $formulaKey);
		if ($formula === null) {
			$this->logger->warning(
				'Dossiq: the scoring formula a case names does not exist; the total is cleared',
				['formula' => $formulaKey, 'case' => $caseId]
			);
			return null;
		}

		$values = array_merge(
			self::caseNumbers(case: (array)$case),
			$this->prices(record: $record, fields: $fields, caseId: $caseId),
			$scores
		);

		try {
			return $this->scorer->score(formula: $formula, values: $values);
		} catch (InvalidArgumentException $e) {
			$this->logger->warning(
				'Dossiq: the scoring formula could not total the record; the total is cleared',
				['formula' => $formulaKey, 'case' => $caseId, 'reason' => $e->getMessage()]
			);
			return null;
		}
	}//end totalOf()

	/**
	 * The stored formula with this key, or null.
	 *
	 * @param string $key The formula key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function formulaNamed(string $key): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue(self::FORMULA_SCHEMA),
				filters: ['key' => $key, '_limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the scoring formulas could not be read: ' . $e->getMessage());
			return null;
		}

		foreach ($rows as $row) {
			if (($row['key'] ?? null) === $key) {
				return $row;
			}
		}

		return null;
	}//end formulaNamed()

	/**
	 * The subject's price and the lowest price among the case's subjects in the running.
	 *
	 * @param array<string, mixed> $record The scored record.
	 * @param ScoredFields         $fields Its fields.
	 * @param string                                                                                                                                              $caseId The case.
	 *
	 * @return array<string, float>
	 */
	private function prices(array $record, array $fields, string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$subjects = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $this->settingsService->getConfigValue($fields['subjectSchema']),
				filters: [$fields['case'] => $caseId, '_limit' => 500]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the scored subjects could not be read: ' . $e->getMessage());
			return [];
		}

		$subjectId = ReferenceId::of(value: ($record[$fields['subject']] ?? null));
		$values = [];
		$running = [];
		foreach ($subjects as $subject) {
			$price = ($subject[$fields['price']] ?? null);
			if (is_int($price) === false && is_float($price) === false) {
				continue;
			}

			if ((string)($subject['id'] ?? ($subject['@self']['id'] ?? '')) === $subjectId) {
				$values[self::PRICE] = (float)$price;
			}

			if (in_array((string)($subject['state'] ?? ''), $fields['outOfRunning'], true) === false) {
				$running[] = (float)$price;
			}
		}

		if ($running !== []) {
			$values[self::LOWEST_PRICE] = min($running);
		}

		return $values;
	}//end prices()

	/**
	 * The numeric entries of a map, by name.
	 *
	 * @param mixed $values The map.
	 *
	 * @return array<string, float>
	 */
	private static function numbers(mixed $values): array {
		if (is_array($values) === false) {
			return [];
		}

		$numbers = [];
		foreach ($values as $name => $value) {
			$number = self::numberOrNull(value: $value);
			if (is_string($name) === true && $number !== null) {
				$numbers[$name] = $number;
			}
		}

		return $numbers;
	}//end numbers()

	/**
	 * The case's numeric fields and answered properties, by name.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, float>
	 */
	private static function caseNumbers(array $case): array {
		$numbers = self::numbers(values: $case);
		foreach ((array)($case['properties'] ?? []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = (string)($entry['name'] ?? '');
			$number = self::numberOrNull(value: ($entry['value'] ?? null));
			if ($name !== '' && $number !== null) {
				$numbers[$name] = $number;
			}
		}

		return $numbers;
	}//end caseNumbers()

	/**
	 * A number, or a string holding one, as a float; anything else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null
	 */
	private static function numberOrNull(mixed $value): ?float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric(trim($value)) === true) {
			return (float)trim($value);
		}

		return null;
	}//end numberOrNull()

	/**
	 * The field's value on the case, or in its answered properties.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param string               $field The field name.
	 *
	 * @return mixed The value, or null.
	 */
	private static function caseValue(array $case, string $field): mixed {
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
	 * A trimmed string, or '' for anything that is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function stringValue(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end stringValue()
}//end class
