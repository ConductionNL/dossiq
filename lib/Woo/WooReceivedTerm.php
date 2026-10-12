<?php

/**
 * Dossiq Woo received term
 *
 * Whether the statutory term of a Woo case that receive() just wrote really
 * runs. Read back, never assumed: the case must have a statutory term
 * instance, that instance an engine timer, and the case `deadline` must be
 * the instance's rolled `endDateCurrent` (REQ-WTO-002). When it does not, the
 * case gets an internal timeline entry saying why.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Throwable;

/**
 * Reads back whether a received Woo case's statutory term runs.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
 */
class WooReceivedTerm {

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService Resolves OpenRegister's term engine.
	 * @param TermijnService|null $terms           The term instances of a case.
	 * @param CaseTimeline|null   $timeline        Where a term that did not start is noted.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ?TermijnService $terms = null,
		private readonly ?CaseTimeline $timeline = null,
	) {
	}//end __construct()

	/**
	 * Whether a term can run at all: the term service and OpenRegister's engine are there.
	 *
	 * @return bool True when both are.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function isAvailable(): bool {
		if ($this->terms === null) {
			return false;
		}

		return $this->settingsService->getOpenRegisterClass(TermijnTimerService::ENGINE_CLASS) !== null;
	}//end isAvailable()

	/**
	 * Why the case's statutory term does not run, or '' when it does.
	 *
	 * @param string $caseId   The case.
	 * @param string $deadline The case `deadline` as read back, `Y-m-d` or ''.
	 *
	 * @return string The reason, or ''.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function refusal(string $caseId, string $deadline): string {
		if ($this->terms === null) {
			return 'the term service is not available.';
		}

		try {
			$statutory = $this->statutory(instances: $this->terms->instancesForCase(caseId: $caseId));
		} catch (Throwable $e) {
			return 'its term could not be read.';
		}

		if ($statutory === null) {
			return 'the case has no statutory term.';
		}

		if (trim((string)($statutory['engineTimerId'] ?? '')) === '') {
			return 'the term engine did not arm a timer.';
		}

		$end = substr(trim((string)($statutory['endDateCurrent'] ?? '')), 0, 10);
		if ($end === '' || $end !== $deadline) {
			return 'the case deadline is not the end date of its term.';
		}

		return '';
	}//end refusal()

	/**
	 * The answer receive() gives for a written case, read back.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $case    The case as read back, or [] when it could not be read.
	 * @param string               $caseUrl Where a handler opens it.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string, caseUrl: string}
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function answer(string $caseId, array $case, string $caseUrl): array {
		$answer = array_merge(
			WooReceivedAnswers::EMPTY_ANSWER,
			['requestId' => $caseId, 'reference' => trim((string)($case['identifier'] ?? '')), 'caseUrl' => $caseUrl]
		);

		$deadline = substr(trim((string)($case['deadline'] ?? '')), 0, 10);
		$reason = $this->refusal(caseId: $caseId, deadline: $deadline);
		if ($reason !== '') {
			$this->noteNotStarted(caseId: $caseId, reason: $reason);

			return array_merge($answer, ['outcome' => 'not-armed', 'message' => 'The request was received, but its term did not start: ' . $reason]);
		}

		return array_merge(
			$answer,
			['outcome' => 'armed', 'dueAt' => $deadline, 'message' => 'The request was received. A decision is due by ' . $deadline . '.']
		);
	}//end answer()

	/**
	 * Note on the case, internally, that its term did not start and why.
	 *
	 * @param string $caseId The case.
	 * @param string $reason What refusal() answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function noteNotStarted(string $caseId, string $reason): void {
		$this->timeline?->record(
			caseId: $caseId,
			kind: TimelineKinds::TERM_EVENT,
			message: 'De wettelijke termijn is niet gestart: ' . $reason,
			fields: ['event' => 'not-started', 'term' => TermKind::STATUTORY],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end noteNotStarted()

	/**
	 * The statutory instance among a case's terms, or null.
	 *
	 * @param array<int, mixed> $instances The case's term instances, newest first.
	 *
	 * @return array<string, mixed>|null
	 */
	private function statutory(array $instances): ?array {
		foreach ($instances as $instance) {
			if (is_array($instance) === false) {
				continue;
			}

			// An absent or unknown kind reads as statutory, as TermKind::ofInstance() reads it.
			$kind = (string)($instance['kind'] ?? '');
			if ($kind === TermKind::STATUTORY || in_array($kind, TermKind::ALL, true) === false) {
				return $instance;
			}
		}

		return null;
	}//end statutory()
}//end class
