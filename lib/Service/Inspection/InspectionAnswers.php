<?php

/**
 * The answers of one inspection run, in the shape `Task.responses` holds.
 *
 * Pure. Three request shapes arrive (design D2 of
 * inspection-checklists-onto-task): stack A's `items` (`result` pass, fail,
 * nvt), stack C's `answers` (`value` conform, non_conform, not_applicable) and
 * the native `responses` (`value` ja, nee, nvt). All three leave as one list
 * of `{itemId, value?, numericValue?, choice?, comment?, photos?,
 * gpsAtAnswer?}`, with a yes/no answer in the template's vocabulary: `ja`,
 * `nee` or `nvt`. One rule then decides the run's outcome.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

use OCA\Dossiq\Service\ChecklistService;

/**
 * Normalises answers and decides the outcome.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
 */
class InspectionAnswers {

	public const CONFORM = 'conform';

	public const NON_CONFORM = 'non_conform';

	public const PARTLY_CONFORM = 'partly_conform';

	/**
	 * Every older yes/no word onto the template's vocabulary.
	 *
	 * @var array<string, string>
	 */
	private const YES_NO = [
		'pass' => 'ja',
		'conform' => 'ja',
		'ja' => 'ja',
		'yes' => 'ja',
		'true' => 'ja',
		'fail' => 'nee',
		'non_conform' => 'nee',
		'nee' => 'nee',
		'no' => 'nee',
		'false' => 'nee',
		'nvt' => 'nvt',
		'not_applicable' => 'nvt',
		'na' => 'nvt',
	];

	/**
	 * Back from the template's vocabulary to the panel's `result` word.
	 *
	 * @var array<string, string>
	 */
	private const PANEL = ['ja' => 'pass', 'nee' => 'fail', 'nvt' => 'nvt'];

	/**
	 * The rules the run is checked against.
	 *
	 * @var ChecklistService
	 */
	private ChecklistService $rules;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rules = new ChecklistService();
	}//end __construct()

	/**
	 * The answers of a request, whichever shape it used.
	 *
	 * @param array<string, mixed> $payload The request body.
	 *
	 * @return array<int, array<string, mixed>> The answers.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function fromPayload(array $payload): array {
		$out = [];
		foreach (['responses', 'answers', 'items'] as $key) {
			if (is_array($payload[$key] ?? null) === false) {
				continue;
			}

			foreach ($payload[$key] as $raw) {
				if (is_array($raw) === true) {
					$answer = $this->answer(raw: $raw);
					if ($answer !== null) {
						$out[] = $answer;
					}
				}
			}

			break;
		}

		return $out;
	}//end fromPayload()

	/**
	 * What keeps the run from being submitted: required items left open and
	 * photo gates not met, checked against the frozen template.
	 *
	 * @param array<string, mixed>             $snapshot The frozen template.
	 * @param array<int, array<string, mixed>> $answers  The answers.
	 *
	 * @return array<int, string> One line per violation; empty when it may be submitted.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function violations(array $snapshot, array $answers): array {
		return $this->rules->validateCompletion(checklist: ['templateSnapshot' => $snapshot, 'responses' => $answers]);
	}//end violations()

	/**
	 * The run's outcome: over the answered items that apply, no `nee` is
	 * conform, no conforming answer is non_conform, anything else is partly.
	 *
	 * @param array<string, mixed>             $snapshot The frozen template.
	 * @param array<int, array<string, mixed>> $answers  The answers.
	 *
	 * @return string conform, non_conform or partly_conform.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function outcome(array $snapshot, array $answers): string {
		$summary = $this->rules->getConformitySummary(checklist: ['templateSnapshot' => $snapshot, 'responses' => $answers]);
		if ($summary['nonConforming'] === 0) {
			return self::CONFORM;
		}

		if ($summary['conforming'] === 0) {
			return self::NON_CONFORM;
		}

		return self::PARTLY_CONFORM;
	}//end outcome()

	/**
	 * How many answers are `nee`.
	 *
	 * @param array<int, array<string, mixed>> $answers The answers.
	 *
	 * @return integer The count.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function failedCount(array $answers): int {
		return count(array_filter($answers, static fn (array $answer): bool => ($answer['value'] ?? null) === 'nee'));
	}//end failedCount()

	/**
	 * Every photo file id the answers name, once.
	 *
	 * @param array<int, array<string, mixed>> $answers The answers.
	 *
	 * @return array<int, string> The file ids.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function evidence(array $answers): array {
		$ids = [];
		foreach ($answers as $answer) {
			foreach (($answer['photos'] ?? []) as $photo) {
				$ids[(string)$photo] = true;
			}
		}

		// Numeric string keys come back as integers; a file id stays a string.
		return array_map('strval', array_keys($ids));
	}//end evidence()

	/**
	 * An answer in the panel's words (`result` pass, fail, nvt).
	 *
	 * @param array<string, mixed> $answer The stored answer.
	 *
	 * @return array<string, mixed> The answer with `result`.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-panel-on-case-dashboard
	 */
	public function forPanel(array $answer): array {
		$value = (string)($answer['value'] ?? '');
		$answer['result'] = (self::PANEL[$value] ?? $value);
		if (isset($answer['numericValue']) === true) {
			$answer['measurement'] = $answer['numericValue'];
		}

		return $answer;
	}//end forPanel()

	/**
	 * One answer, or null when it names no item.
	 *
	 * @param array<string, mixed> $raw The answer as sent.
	 *
	 * @return array<string, mixed>|null The answer.
	 */
	private function answer(array $raw): ?array {
		$itemId = trim((string)($raw['itemId'] ?? $raw['itemRef'] ?? ''));
		if ($itemId === '') {
			return null;
		}

		$answer = ['itemId' => $itemId];
		$value = ($raw['value'] ?? $raw['result'] ?? null);
		if ($value !== null && $value !== '') {
			$word = strtolower(trim((string)$value));
			$answer['value'] = (self::YES_NO[$word] ?? (string)$value);
		}

		$numeric = ($raw['numericValue'] ?? $raw['measurement'] ?? null);
		if (is_numeric($numeric) === true) {
			$answer['numericValue'] = (float)$numeric;
		}

		return $this->withExtras(answer: $answer, raw: $raw);
	}//end answer()

	/**
	 * The free text, photos and GPS of one answer.
	 *
	 * @param array<string, mixed> $answer The answer so far.
	 * @param array<string, mixed> $raw    The answer as sent.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function withExtras(array $answer, array $raw): array {
		foreach (['choice' => 'choice', 'comment' => 'comment', 'remark' => 'comment'] as $from => $to) {
			if (trim((string)($raw[$from] ?? '')) !== '' && isset($answer[$to]) === false) {
				$answer[$to] = (string)$raw[$from];
			}
		}

		$photos = $this->photos(raw: $raw);
		if ($photos !== []) {
			$answer['photos'] = $photos;
		}

		if (is_array($raw['gpsAtAnswer'] ?? null) === true) {
			$answer['gpsAtAnswer'] = $raw['gpsAtAnswer'];
		}

		return $answer;
	}//end withExtras()

	/**
	 * The photo file ids of one answer.
	 *
	 * @param array<string, mixed> $raw The answer as sent.
	 *
	 * @return array<int, string> The ids.
	 */
	private function photos(array $raw): array {
		$photos = [];
		if (is_array($raw['photos'] ?? null) === true) {
			foreach ($raw['photos'] as $photo) {
				if (is_scalar($photo) === true && (string)$photo !== '') {
					$photos[] = (string)$photo;
				}
			}
		}

		if (is_scalar($raw['photoRef'] ?? null) === true && (string)$raw['photoRef'] !== '') {
			$photos[] = (string)$raw['photoRef'];
		}

		return $photos;
	}//end photos()
}//end class
