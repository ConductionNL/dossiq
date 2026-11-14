<?php

/**
 * Dossiq routing note: the facts of a case's last routing event.
 *
 * The note holds facts and no sentence, so every reader gets it in their own
 * language: the list row words it (src/services/routingNote.js).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/kcc-routing/spec.md#requirement-a-case-keeps-a-note-of-its-last-routing-event
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

/**
 * Builds the `routingNote` of a case.
 */
final class RoutingNote {

	/**
	 * The note of a refused handover.
	 *
	 * @param string $team   The team that refused.
	 * @param string $reason Why it refused.
	 *
	 * @return array{kind: string, level: string, team: string, reason: string}
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-a-case-keeps-a-note-of-its-last-routing-event
	 */
	public static function handoverRefused(string $team, string $reason): array {
		return ['kind' => 'handover-refused', 'level' => 'error', 'team' => $team, 'reason' => $reason];
	}//end handoverRefused()

	/**
	 * The note of a fan-out, for one of the cases it opened.
	 *
	 * @param string                               $submission The submission.
	 * @param array<int, string>                   $siblings   The titles of the other destinations opened.
	 * @param array<int, array<string, string>>    $failed     The destinations that opened no case.
	 *
	 * @return array<string, mixed> The note: kind, level, submission, siblings and failed.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-a-case-keeps-a-note-of-its-last-routing-event
	 */
	public static function intakeSplit(string $submission, array $siblings, array $failed): array {
		return [
			'kind' => 'intake-split',
			'level' => 'info',
			'submission' => $submission,
			'siblings' => $siblings,
			'failed' => array_map(
				static fn (array $entry): array => ['destination' => $entry['destination'], 'reason' => $entry['reason']],
				$failed
			),
		];
	}//end intakeSplit()
}//end class
