<?php

/**
 * Dossiq term end date transition action.
 *
 * Writes the day a declared term ends onto the case, counted from a case
 * date. Generic: the action names the case date the term runs from, the case
 * date that receives its end, and the legal basis of the term definition
 * (`termijn_definitie`) that sets its length and counting mode for the case
 * type. When the start date is still empty the term starts today, and that
 * day is written too, so the act that starts the term also records when.
 *
 * Action config shape:
 * `{type: 'setTermEnd', start: '<case date>', end: '<case date>', legalBasis: '<basis>'}`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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

namespace OCA\Dossiq\Service\Transitions;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts a declared term from a case date and writes its end onto the case.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type
 */
class TermEndDateHandler implements ActionHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param CaseObjectReaderInterface   $cases       Reads the case type, for the slug terms are bound by.
	 * @param TermDefinitions             $definitions The active term definitions and how they count.
	 * @param CaseDateNormaliserInterface $dates       Reads a case date and today in the administrator's time zone.
	 * @param SetCaseAnswerHandler        $answers     Writes a case date.
	 * @param LoggerInterface             $logger      Structured logger.
	 */
	public function __construct(
		private readonly CaseObjectReaderInterface $cases,
		private readonly TermDefinitions $definitions,
		private readonly CaseDateNormaliserInterface $dates,
		private readonly SetCaseAnswerHandler $answers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the end of the configured term onto the case.
	 *
	 * @param array<string, mixed> $actionConfig      `{start, end, legalBasis}`.
	 * @param array<string, mixed> $case              The case.
	 * @param array<string, mixed> $transitionContext The transition snapshot.
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/changes/tenders-run-on-the-tender-case-type
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		$startField = trim((string)($actionConfig['start'] ?? ''));
		$endField = trim((string)($actionConfig['end'] ?? ''));
		$legalBasis = trim((string)($actionConfig['legalBasis'] ?? ''));
		if ($startField === '' || $endField === '' || $legalBasis === '') {
			return new ActionResult(succeeded: false, error: 'set_term_end_incomplete');
		}

		$definition = $this->definitionFor(case: $case, legalBasis: $legalBasis);
		$days = (int)($definition['standardDurationDays'] ?? 0);
		if ($definition === null || $days <= 0) {
			return new ActionResult(succeeded: false, error: 'set_term_end_no_term');
		}

		$start = $this->dates->toCalendarDateOrNull(value: self::caseValue(case: $case, field: $startField));
		$changes = [];
		if ($start === null) {
			$start = $this->dates->todayAsCalendarDate();
			$changes[$startField] = $start;
		}

		try {
			$end = $this->definitions->endDateFor(start: new DateTimeImmutable($start), days: $days, definitie: $definition);
		} catch (Throwable $e) {
			$this->logger->warning('TermEndDateHandler: the term end could not be counted', ['start' => $start, 'exception' => $e->getMessage()]);
			return new ActionResult(succeeded: false, error: 'set_term_end_failed');
		}

		$changes[$endField] = $end->format('Y-m-d');
		foreach ($changes as $name => $value) {
			$written = $this->answers->handle(actionConfig: ['name' => $name, 'value' => $value], case: $case, transitionContext: $transitionContext);
			if ($written->succeeded === false) {
				return new ActionResult(succeeded: false, error: 'set_term_end_failed');
			}
		}

		return new ActionResult(succeeded: true, data: ['start' => $start, 'end' => $changes[$endField], 'days' => $days], caseChanges: $changes);
	}//end handle()

	/**
	 * The active term definition of the case's type with this legal basis, or null.
	 *
	 * @param array<string, mixed> $case       The case.
	 * @param string               $legalBasis The legal basis.
	 *
	 * @return array<string, mixed>|null
	 */
	private function definitionFor(array $case, string $legalBasis): ?array {
		$caseType = $this->cases->loadCaseType(caseTypeId: (string)($case['caseType'] ?? ''));
		$slug = trim((string)($caseType['identifier'] ?? ''));
		if ($slug === '') {
			return null;
		}

		foreach ($this->definitions->allActiveFor(caseType: $slug) as $definition) {
			if (trim((string)($definition['legalBasis'] ?? '')) === $legalBasis) {
				return $definition;
			}
		}

		return null;
	}//end definitionFor()

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
}//end class
