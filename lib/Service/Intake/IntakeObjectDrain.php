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
 * - `caseTypes`: that value => the IntakeCaseTypes path key naming the case type.
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

use OCA\Dossiq\Kernel\Contracts\IntakeObjectDrainInterface;
use Throwable;

/**
 * Drains intake objects into cases, or reports why not.
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-portal-filing-must-not-be-held-in-an-intermediate-object
 */
class IntakeObjectDrain implements IntakeObjectDrainInterface {

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
		$caseType = $this->caseTypeOf(plan: $plan, kind: $kind);
		if ($caseType === '') {
			return ['findings' => [['property' => 'caseType', 'code' => 'case-type-unknown', 'message' => 'No case type is configured for "' . $kind . '".']]];
		}

		$case = $this->mappedFields(source: $source, plan: $plan);
		$case['title'] = $this->titleOf(source: $source, plan: $plan);
		$case['caseType'] = $caseType;
		$case['externalReference'] = $this->idOf(source: $source);

		return ['case' => $case];
	}//end caseFor()

	/**
	 * The case type configured for a kind of source.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param string               $kind The source's kind.
	 *
	 * @return string The case type, or the empty string when none is configured.
	 */
	private function caseTypeOf(array $plan, string $kind): string {
		$path = (string)(($plan['caseTypes'] ?? [])[$kind] ?? '');
		$caseType = '';
		if ($path !== '') {
			$caseType = $this->caseTypes->caseTypeFor(path: $path);
		}

		return $caseType;
	}//end caseTypeOf()

	/**
	 * The plan's fixed values, with every non-empty mapped source field added.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param array<string, mixed> $plan   The plan.
	 *
	 * @return array<string, mixed> The case so far.
	 */
	private function mappedFields(array $source, array $plan): array {
		$case = (array)($plan['fixed'] ?? []);
		foreach ((array)($plan['fields'] ?? []) as $from => $to) {
			$value = ($source[(string)$from] ?? null);
			if ($value !== null && $value !== '' && $value !== []) {
				$case[(string)$to] = $value;
			}
		}

		return $case;
	}//end mappedFields()

	/**
	 * The case title: the source's title field, else the kind's configured title, else the kind.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param array<string, mixed> $plan   The plan, naming the field the source's kind is read from.
	 *
	 * @return string The title.
	 */
	private function titleOf(array $source, array $plan): string {
		$kind = trim((string)($source[(string)($plan['caseTypeField'] ?? '')] ?? ''));
		$title = trim((string)($source[(string)($plan['titleField'] ?? '')] ?? ''));
		if ($title === '') {
			$title = (string)(($plan['titles'] ?? [])[$kind] ?? $kind);
		}

		return $title;
	}//end titleOf()

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
		$run = ['plan' => $plan, 'existing' => $existing, 'submit' => $submit, 'markSource' => $markSource, 'apply' => $apply];
		$report = ['delivered' => [], 'already' => [], 'refused' => []];

		foreach ($sources as $source) {
			[$bucket, $entry] = $this->drainOne(source: $source, run: $run);
			$report[$bucket][] = $entry;
		}

		return $report;
	}//end drain()

	/**
	 * Drain one source: already delivered, refused, planned (dry run) or delivered now.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param array<string, mixed> $run    The plan, the `existing`, `submit` and `markSource` callables, and `apply`.
	 *
	 * @return array{0: string, 1: mixed} The report list it belongs on, and its entry there.
	 */
	private function drainOne(array $source, array $run): array {
		$deliveredField = (string)($run['plan']['deliveredField'] ?? '');
		$sourceId = $this->idOf(source: $source);
		$caseId = trim((string)($source[$deliveredField] ?? ''));
		if ($caseId === '') {
			$caseId = ($run['existing'])($source);
		}

		if ($caseId !== '') {
			// Delivered before, perhaps by a run that could not mark the
			// source. Mark it now so the next run does not search again.
			if ($run['apply'] === true && trim((string)($source[$deliveredField] ?? '')) === '') {
				($run['markSource'])($source, $caseId);
			}

			return ['already', $sourceId];
		}

		$planned = $this->caseFor(source: $source, plan: $run['plan']);
		if (isset($planned['findings']) === true) {
			return ['refused', ['source' => $sourceId, 'findings' => $planned['findings']]];
		}

		if ($run['apply'] === false) {
			return ['delivered', ['source' => $sourceId, 'case' => '']];
		}

		return $this->deliver(source: $source, run: ['case' => $planned['case']] + $run);
	}//end drainOne()

	/**
	 * Write the case of one source and mark the source with it.
	 *
	 * @param array<string, mixed> $source The source object.
	 * @param array<string, mixed> $run    The case to write (`case`), `submit` (writes the case) and `markSource`
	 *                                     (records the case id on the source).
	 *
	 * @return array{0: string, 1: array<string, mixed>} `delivered` or `refused`, and the report entry.
	 */
	private function deliver(array $source, array $run): array {
		$sourceId = $this->idOf(source: $source);
		try {
			$answer = ($run['submit'])($run['case']);
		} catch (Throwable $e) {
			$answer = ['findings' => [['property' => '', 'code' => 'write-failed', 'message' => $e->getMessage()]]];
		}

		$caseId = trim((string)($answer['id'] ?? ''));
		if ($caseId === '') {
			return ['refused', ['source' => $sourceId, 'findings' => (array)($answer['findings'] ?? [])]];
		}

		($run['markSource'])($source, $caseId);

		return ['delivered', ['source' => $sourceId, 'case' => $caseId]];
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
