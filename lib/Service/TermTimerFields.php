<?php

/**
 * Dossiq term timer fields.
 *
 * The parts of an engine timer configuration every dossiq term timer shares:
 * the subject, the metadata, and the rules of a hersteltermijn helper timer.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Builds the shared fields of a term timer's engine configuration.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
final class TermTimerFields {

	/**
	 * The one explicit rule of a hersteltermijn helper timer: the pause ran out.
	 *
	 * @var array<string, mixed>
	 */
	private const PAUSE_EXPIRED_RULE = [
		'trigger' => 'slaBreached',
		'offset' => 0,
		'offsetUnit' => 'calendarDays',
		'notifyRole' => ['handler'],
		'escalateToRole' => [],
		'priority' => 'high',
		'message' => 'pauze-verlopen',
		'openIncident' => false,
	];

	/**
	 * The fields that name a timer's subject: the instance, and its title.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param string               $label    The kind of term, leading the title.
	 *
	 * @return array<string, string> The fields.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function subject(array $instance, string $label): array {
		$instanceId = (string)($instance['id'] ?? '');

		return [
			'subjectType' => 'object',
			'subjectUuid' => $instanceId,
			'appId' => 'dossiq',
			'title' => $label . ' ' . (string)($instance['case'] ?? $instanceId),
			'purpose' => 'due',
		];
	}//end subject()

	/**
	 * The metadata every dossiq term timer carries.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param string               $kind     The kind of term.
	 *
	 * @return array<string, string> The metadata.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function metadata(array $instance, string $kind): array {
		return [
			'source' => TermijnTimerService::METADATA_SOURCE,
			'kind' => $kind,
			'termijnInstanceId' => (string)($instance['id'] ?? ''),
			'caseId' => (string)($instance['case'] ?? ''),
		];
	}//end metadata()

	/**
	 * The rules of a hersteltermijn helper timer: the expiry, then one per reminder.
	 *
	 * @param array<int, int> $offsets Days before the pause ends, one per reminder.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	public function pauseRules(array $offsets): array {
		return array_merge([self::PAUSE_EXPIRED_RULE], $this->chaseRules(offsets: $offsets));
	}//end pauseRules()

	/**
	 * The engine rules for the reminders on one pause.
	 *
	 * `preBreach` rather than a negative `slaBreached` offset, because
	 * `preBreach:<days>` is the rung shape the fired listener already parses
	 * and a second shape would need a second parser. The message is
	 * `pauze-chase` so a human reading the engine's own log can tell a reminder
	 * from the expiry beside it.
	 *
	 * @param array<int, int> $offsets Days before the pause ends, one per reminder.
	 *
	 * @return array<int, array<string, mixed>> The rules, empty when the reason does not chase.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	private function chaseRules(array $offsets): array {
		$rules = [];
		foreach ($offsets as $offset) {
			$days = (int)$offset;
			if ($days <= 0) {
				continue;
			}

			$rules[] = $this->chaseRule(days: $days);
		}

		return $rules;
	}//end chaseRules()

	/**
	 * The engine rule for one reminder, the given days before the pause ends.
	 *
	 * @param int $days Days before the pause ends.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function chaseRule(int $days): array {
		return [
			'trigger' => 'preBreach',
			'offset' => $days,
			'offsetUnit' => 'calendarDays',
			'notifyRole' => [],
			'escalateToRole' => [],
			'priority' => 'normal',
			'message' => 'pauze-chase',
			'openIncident' => false,
		];
	}//end chaseRule()
}//end class
