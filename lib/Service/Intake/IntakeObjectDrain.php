<?php

/**
 * Turn the objects an old intake layer holds into cases.
 *
 * Decision 179: there is no intake layer between a form and its case. Where
 * one existed, its objects are drained: each becomes the case it should have
 * been, through OpenRegister's form submit (so the case is validated against
 * the case schema exactly as a new submit is), or it is reported with the
 * findings that refused it. The old object keeps the id of its case, so a
 * second run skips it, and the case keeps the old id as `externalReference`,
 * so a resident's old link still finds it (decision 181, Q10).
 *
 * Generic by construction (decision 182). The plan names the source fields,
 * the case fields and which setting names the case type; the engine knows no
 * procedure and no schema.
 *
 * A plan:
 * - `fields`: source field => case field, copied when not empty.
 * - `fixed`: case field => value, always written.
 * - `caseTypeField`: the source field whose value picks the case type.
 * - `caseTypes`: that value => the IntakeCaseTypes setting naming the case type.
 * - `titleField`, `titles`: the source field for the case title, and a title per
 *   case-type value when that field is empty (a case needs a title).
 * - `deliveredField`: the source field that holds the case id once delivered.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use Throwable;

/**
 * Drains intake objects into cases, or reports why not.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
class IntakeObjectDrain {

	/**
	 * Constructor.
	 *
	 * @param IntakeCaseTypes $caseTypes Which case type a source value opens.
	 */
	public function __construct(
		private readonly IntakeCaseTypes $caseTypes,
	) {
	}//end __construct()

	/**
	 * The case one source object becomes, or the findings that stop it.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param array<string, mixed> $plan   The plan (see the file docblock).
	 *
	 * @return array{case?: array<string, mixed>, findings?: array<int, array<string, string>>} The case, or findings.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
	 */
	public function caseFor(array $source, array $plan): array {
		$kind = trim((string)($source[(string)($plan['caseTypeField'] ?? '')] ?? ''));
		$setting = (string)(($plan['caseTypes'] ?? [])[$kind] ?? '');
		$caseType = '';
		if ($setting !== '') {
			$caseType = $this->caseTypes->caseTypeFor(setting: $setting);
		}

		if ($caseType === '') {
			return ['findings' => [['property' => 'caseType', 'code' => 'case-type-unknown', 'message' => 'No case type is configured for "' . $kind . '".']]];
		}

		$case = (array)($plan['fixed'] ?? []);
		foreach ((array)($plan['fields'] ?? []) as $from => $to) {
			$value = ($source[(string)$from] ?? null);
			if ($value !== null && $value !== '' && $value !== []) {
				$case[(string)$to] = $value;
			}
		}

		$title = trim((string)($source[(string)($plan['titleField'] ?? '')] ?? ''));
		if ($title === '') {
			$title = (string)(($plan['titles'] ?? [])[$kind] ?? $kind);
		}

		$case['title'] = $title;
		$case['caseType'] = $caseType;
		$case['externalReference'] = $this->idOf(source: $source);

		return ['case' => $case];
	}//end caseFor()

	/**
	 * Drain every source object.
	 *
	 * @param array<int, array<string, mixed>>                          $sources   The source objects.
	 * @param array<string, mixed>                                      $plan      The plan.
	 * @param callable(array<string, mixed>): string                    $existing  The id of a case already holding this
	 *                                                                             reference, or the empty string.
	 * @param callable(array<string, mixed>): array<string, mixed>      $submit    Writes the case; answers `id` or `findings`.
	 * @param callable(array<string, mixed>, string): void              $markSource Records the case id on the source.
	 * @param bool                                                      $apply     False: report only, write nothing.
	 *
	 * @return array{delivered: array<int, array<string, string>>, already: array<int, string>, refused: array<int, array<string, mixed>>} The report.
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
	 */
	public function drain(array $sources, array $plan, callable $existing, callable $submit, callable $markSource, bool $apply): array {
		$report = ['delivered' => [], 'already' => [], 'refused' => []];
		$deliveredField = (string)($plan['deliveredField'] ?? '');

		foreach ($sources as $source) {
			$sourceId = $this->idOf(source: $source);
			$caseId = trim((string)($source[$deliveredField] ?? ''));
			if ($caseId === '') {
				$caseId = $existing($source);
			}

			if ($caseId !== '') {
				// Delivered before, perhaps by a run that could not mark the
				// source. Mark it now so the next run does not search again.
				if ($apply === true && trim((string)($source[$deliveredField] ?? '')) === '') {
					$markSource($source, $caseId);
				}

				$report['already'][] = $sourceId;
				continue;
			}

			$planned = $this->caseFor(source: $source, plan: $plan);
			if (isset($planned['findings']) === true) {
				$report['refused'][] = ['source' => $sourceId, 'findings' => $planned['findings']];
				continue;
			}

			if ($apply === false) {
				$report['delivered'][] = ['source' => $sourceId, 'case' => ''];
				continue;
			}

			$this->deliver(source: $source, case: $planned['case'], submit: $submit, markSource: $markSource, report: $report);
		}//end foreach

		return $report;
	}//end drain()

	/**
	 * Write one case and record the outcome.
	 *
	 * @param array<string, mixed>                                 $source     The source object.
	 * @param array<string, mixed>                                 $case       The case to write.
	 * @param callable(array<string, mixed>): array<string, mixed> $submit     Writes the case.
	 * @param callable(array<string, mixed>, string): void         $markSource Records the case id on the source.
	 * @param array<string, array<int, mixed>>                     $report     The report, extended in place.
	 *
	 * @return void
	 */
	private function deliver(array $source, array $case, callable $submit, callable $markSource, array &$report): void {
		$sourceId = $this->idOf(source: $source);
		try {
			$answer = $submit($case);
		} catch (Throwable $e) {
			$answer = ['findings' => [['property' => '', 'code' => 'write-failed', 'message' => $e->getMessage()]]];
		}

		$caseId = trim((string)($answer['id'] ?? ''));
		if ($caseId === '') {
			$report['refused'][] = ['source' => $sourceId, 'findings' => (array)($answer['findings'] ?? [])];
			return;
		}

		$markSource($source, $caseId);
		$report['delivered'][] = ['source' => $sourceId, 'case' => $caseId];
	}//end deliver()

	/**
	 * The source object's id.
	 *
	 * @param array<string, mixed> $source The source object.
	 *
	 * @return string The id.
	 */
	private function idOf(array $source): string {
		return trim((string)($source['id'] ?? ($source['@self']['id'] ?? ($source['uuid'] ?? ''))));
	}//end idOf()
}//end class
