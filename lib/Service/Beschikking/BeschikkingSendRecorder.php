<?php

/**
 * Dossiq BeschikkingSendRecorder.
 *
 * What sending a beschikking puts on its case timeline: the public line for
 * a beschikking a transport took, and, for one no transport took, the
 * internal line that says why and the refusal that carries the transport's
 * sentence.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;

/**
 * Records the outcome of one send on the case timeline.
 *
 * A plain helper: BeschikkingService builds it from its own timeline.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */
final class BeschikkingSendRecorder {

	/**
	 * Constructor.
	 *
	 * @param CaseTimeline $timeline The case timeline.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Put the delivered beschikking on the case timeline, for both readers.
	 *
	 * PUBLIC BY CONSTRUCTION, NOT BY A TICKED BOX. The applicant is holding
	 * this letter: a transport took it and answered with its own message id,
	 * and the weeks they have to object started the day it went. A timeline
	 * that showed them everything except the decision they are objecting to
	 * would be the one entry worth hiding least.
	 *
	 * The entry carries the channel and the day it went, and nothing about the
	 * addressee. Who it was sent to is on the beschikking; a public line is the
	 * last place to repeat it.
	 *
	 * @param BeschikkingSendRun   $run   The send.
	 * @param array<string, mixed> $saved The saved beschikking.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-actions-placed-on-the-case-page/specs/portal-contribution/spec.md#requirement-a-case-records-the-day-its-decision-was-sent-req-cap-001
	 */
	public function delivered(BeschikkingSendRun $run, array $saved): void {
		$caseId = trim((string)($saved['caseId'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::DECISION_SENT,
			message: 'Beschikking verzonden',
			fields: $this->deliveredFields(run: $run, saved: $saved),
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);
	}//end delivered()

	/**
	 * The fields of the delivered line: the channel and the day it went, never the addressee.
	 *
	 * @param BeschikkingSendRun   $run   The send.
	 * @param array<string, mixed> $saved The saved beschikking.
	 *
	 * @return array<string, string>
	 */
	private function deliveredFields(BeschikkingSendRun $run, array $saved): array {
		return [
			'channel' => (string)($run->dispatch['channel'] ?? ''),
			'sentOn' => (string)($saved['announcementDate'] ?? ''),
			'decisionType' => (string)($saved['decisionType'] ?? ''),
			'reference' => (string)($saved['reference'] ?? ''),
			'beschikkingId' => $run->decisionId,
		];
	}//end deliveredFields()

	/**
	 * The refusal for a beschikking no transport took, after an internal line says so.
	 *
	 * INTERNAL, never public: the citizen's timeline must not read that the
	 * decision was sent. The handler reads why on the case.
	 *
	 * @param BeschikkingSendRun $run The send, with the sender's not-sent result.
	 *
	 * @return RefusedException The refusal, carrying the transport's sentence.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function notSent(BeschikkingSendRun $run): RefusedException {
		$caseId = trim((string)($run->decision['caseId'] ?? ''));
		if ($caseId !== '') {
			$this->timeline->record(
				caseId: $caseId,
				kind: TimelineKinds::DECISION_SENT,
				message: 'Beschikking niet verzonden',
				fields: $this->notSentFields(run: $run),
				visibility: CaseTimeline::INTERNAL,
			);
		}

		return $this->refusal(dispatch: $run->dispatch);
	}//end notSent()

	/**
	 * The fields of the internal not-sent line: why, and what was tried.
	 *
	 * @param BeschikkingSendRun $run The send, with the sender's not-sent result.
	 *
	 * @return array<string, mixed>
	 */
	private function notSentFields(BeschikkingSendRun $run): array {
		return [
			'reasonCode' => $this->reasonCode(dispatch: $run->dispatch),
			'reason' => $this->reason(dispatch: $run->dispatch),
			'channelsTried' => count((array)($run->dispatch['channelsTried'] ?? [])),
			'reference' => (string)($run->decision['reference'] ?? ''),
			'beschikkingId' => $run->decisionId,
		];
	}//end notSentFields()

	/**
	 * The refusal for a not-sent result: no address, or the transport's own sentence.
	 *
	 * @param array<string, mixed> $dispatch The sender's not-sent result.
	 *
	 * @return RefusedException
	 */
	private function refusal(array $dispatch): RefusedException {
		if ($this->reasonCode(dispatch: $dispatch) === 'no-channel') {
			return $this->noAddressRefusal();
		}

		return new RefusedException(
			rule: 'beschikking-not-sent',
			sentence: $this->reason(dispatch: $dispatch),
			status: RefusedException::STATUS_INDETERMINATE,
		);
	}//end refusal()

	/**
	 * The refusal for a case with no address for the requester.
	 *
	 * @return RefusedException
	 */
	private function noAddressRefusal(): RefusedException {
		return new RefusedException(
			rule: 'beschikking-no-address',
			sentence: 'This case has no address for the requester, so the beschikking was not sent. '
				. 'Add an address, then send it again.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end noAddressRefusal()

	/**
	 * Why the sender did not send, as a code.
	 *
	 * @param array<string, mixed> $dispatch The sender's not-sent result.
	 *
	 * @return string
	 */
	private function reasonCode(array $dispatch): string {
		return (string)($dispatch['reasonCode'] ?? 'not-sent');
	}//end reasonCode()

	/**
	 * Why the sender did not send, as its sentence.
	 *
	 * @param array<string, mixed> $dispatch The sender's not-sent result.
	 *
	 * @return string
	 */
	private function reason(array $dispatch): string {
		return (string)($dispatch['reason'] ?? 'No transport took the beschikking, so it was not sent.');
	}//end reason()
}//end class
