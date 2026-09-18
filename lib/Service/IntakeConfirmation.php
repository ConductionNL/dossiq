<?php

/**
 * The four things a citizen is told when their request is received.
 *
 * The reference, the moment it arrived, the moment its clock starts, and the
 * deadline. One producer, because the screen and the mail have to say the same
 * thing: a confirmation that disagrees with the mail sent ten seconds later is
 * worse than either alone.
 *
 * 🔴 THE EXPLANATION APPEARS ONLY WHEN IT IS NEEDED. A request filed on Tuesday
 * at ten reads as three dates and needs no sentence. A request filed on Sunday
 * gets one line saying the term starts on the first working day. An explanation
 * on every confirmation teaches people to stop reading confirmations, and the
 * one time it matters it is the line they skip.
 *
 * 🔴 IT READS THE STORED MOMENTS AND RECOMPUTES NOTHING. `termStartsAt` was
 * stamped at creation on the calendar as it stood that day. A calendar that has
 * since gained a holiday would answer differently, and the citizen was not told
 * the new answer.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IL10N;

/**
 * What the intake confirmation says, on screen and in the mail.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeConfirmation {

	/**
	 * Constructor.
	 *
	 * @param IntakeTermStart $intake The stored moments and the flag.
	 * @param IL10N           $l10n   The localisation service.
	 */
	public function __construct(
		private readonly IntakeTermStart $intake,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The confirmation for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{reference: string, receivedAt: string, termStartsAt: string, deadline: string, outsideWorkingHours: bool, explanation: string}
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function forCase(array $case): array {
		$outside = $this->intake->wasOutsideWorkingHours(case: $case);

		return [
			'reference' => trim((string)($case['identifier'] ?? '')),
			'receivedAt' => trim((string)($case[IntakeTermStart::RECEIVED_AT] ?? '')),
			'termStartsAt' => trim((string)($case[IntakeTermStart::TERM_STARTS_AT] ?? '')),
			'deadline' => trim((string)($case['deadline'] ?? '')),
			'outsideWorkingHours' => $outside,
			'explanation' => $this->explanation(case: $case, outside: $outside),
		];
	}//end forCase()

	/**
	 * The one sentence, or the empty string when none is needed.
	 *
	 * @param array<string, mixed> $case    The case.
	 * @param boolean              $outside Whether it arrived out of hours.
	 *
	 * @return string The sentence, or ''.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function explanation(array $case, bool $outside): string {
		if ($outside === false) {
			return '';
		}

		// 🔴 AND NOT WHEN THERE IS NOTHING TO POINT AT. The flag can be true on
		// a case whose `termStartsAt` never landed, and a sentence promising a
		// start the confirmation does not name is worse than no sentence.
		if (trim((string)($case[IntakeTermStart::TERM_STARTS_AT] ?? '')) === '') {
			return '';
		}

		return $this->l10n->t(
			'Your request arrived outside our working hours, so the term starts on the first working day.'
		);
	}//end explanation()

	/**
	 * The confirmation as `{{placeholder}}` values for an email template.
	 *
	 * The keys are the Dutch names the shipped templates use, because the
	 * templates are authored by the people who send them.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, string> The variable map fragment.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
	 */
	public function placeholdersFor(array $case): array {
		$confirmation = $this->forCase(case: $case);

		return [
			'ontvangenOp' => $confirmation['receivedAt'],
			'termijnStartOp' => $confirmation['termStartsAt'],
			// Empty when no explanation is due, which is what makes the
			// template's one conditional sentence possible: a template is a
			// flat string with no `if`, so the branch has to live in the value.
			'buitenKantoortijden' => $confirmation['explanation'],
		];
	}//end placeholdersFor()
}//end class
