<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Pipelinq;

use Psr\Log\LoggerInterface;

/**
 * A contact moment logged on a case, appended to the fleet's record too.
 *
 * One contact moment schema exists in the fleet, pipelinq's, as the
 * `contactmoment` facet of its ticket supertype. Dossiq declares two of its
 * own, `contactmoment` and `customerContact`, and a schema slug is global per
 * organisation: that is the collision the 2026-09-05 fleet audit found eighteen
 * times.
 *
 * 🔴 THIS IS A BRIDGE, AND IT IS MEANT TO BE TEMPORARY. Two records for one
 * telephone call is exactly what this programme ends, so writing both needs
 * saying rather than hiding: the dossiq record is what the KCC werkplek reads
 * today, the pipelinq record is what the fleet reads, and the follow-up change
 * that retires the dossiq schemas can only migrate rows that exist on both
 * sides first.
 *
 * 🔴 THE HANDLER'S WORK IS NEVER LOST. The append is best effort. A refusal, a
 * failure or an absent pipelinq leaves the dossiq write alone: somebody logging
 * a call they have just taken must not lose it because another app said no.
 * Where pipelinq refuses an OUTBOUND append because an indicator blocks it,
 * that refusal is REPORTED with the indicator named, because that is a sentence
 * the handler needs to read.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */
class ContactMomentBridge {

	/**
	 * How dossiq's `notificationChannel` values read in pipelinq's vocabulary.
	 *
	 * Mapped rather than passed through: pipelinq's channel is a free string
	 * spanning several vocabularies, and sending `webformulier` where the fleet
	 * reads `web` makes a facet nobody can filter.
	 *
	 * @var array<string, string>
	 */
	private const CHANNELS = [
		'phone' => 'telefoon',
		'email' => 'email',
		'webformulier' => 'web',
		'chat' => 'chat',
		'social_media' => 'social',
		'balie' => 'balie',
	];

	/**
	 * @param PipelinqGateway $gateway The only seam that names pipelinq.
	 * @param LoggerInterface $logger Says when an append did not land.
	 */
	public function __construct(
		private readonly PipelinqGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Append one logged contact moment to pipelinq's record.
	 *
	 * @param string $caseId The case the moment was logged on.
	 * @param array<string, mixed> $moment The dossiq contact moment, as written.
	 *
	 * @return array{appended: bool, reason: string, indicators: array<int, array<string, mixed>>}
	 *   Whether it landed, why not when it did not, and the indicators pipelinq
	 *   named when it refused an outbound append.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
	 */
	public function append(string $caseId, array $moment): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			// A moment with no case has no host to append against. Not an
			// error: a KCC call that opened no case is an ordinary thing.
			return ['appended' => false, 'reason' => 'the contact moment names no case', 'indicators' => []];
		}

		$answer = $this->gateway->ask(
			class: PipelinqGateway::CONTACT_MOMENTS,
			method: 'create',
			arguments: ['hostId' => $caseId, 'payload' => $this->payloadFor(moment: $moment)],
			fallback: null,
		);

		if ($answer['answered'] === false) {
			return ['appended' => false, 'reason' => $answer['reason'], 'indicators' => []];
		}

		$result = ($answer['value'] ?? []);
		if (is_array($result) === false) {
			return ['appended' => false, 'reason' => 'pipelinq answered an unusable shape', 'indicators' => []];
		}

		$status = (int)($result['status'] ?? 0);
		if ($status === 201) {
			return ['appended' => true, 'reason' => '', 'indicators' => []];
		}

		$reason = (string)($result['error'] ?? 'pipelinq refused the append');

		$this->logger->info(
			'Dossiq pipelinq: the contact moment was written here and refused there, so the two records '
			. 'disagree until somebody looks: ' . $reason,
			['case' => $caseId, 'status' => $status]
		);

		return [
			'appended' => false,
			'reason' => $reason,
			'indicators' => (array)($result['indicators'] ?? []),
		];
	}//end append()

	/**
	 * The contact moments on a case, by MEMBERSHIP, with the shared marker.
	 *
	 * Membership, not equality: one call filed on three cases is one record and
	 * each of the three has to see it. Reading only the primary reference shows
	 * it on one case and renders the other two as if the call never happened.
	 *
	 * @param string $caseId The case.
	 * @param string $partyId The party whose indicators travel with the panel.
	 *
	 * @return array{available: bool, moments: array<int, array<string, mixed>>, indicators: array<int, array<string, mixed>>}
	 *   The panel. `available` false means pipelinq did not answer, which is
	 *   not the same as a case with no contact moments.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function onCase(string $caseId, string $partyId = ''): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::CONTACT_MOMENTS,
			method: 'list',
			arguments: ['hostId' => trim($caseId), 'partyId' => trim($partyId)],
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return ['available' => false, 'moments' => [], 'indicators' => []];
		}

		$result = $answer['value'];
		if ((int)($result['status'] ?? 0) !== 200) {
			return ['available' => false, 'moments' => [], 'indicators' => []];
		}

		$moments = [];
		foreach ((array)($result['contactMoments'] ?? []) as $moment) {
			if (is_array($moment) === false) {
				continue;
			}

			$moments[] = array_merge(
				$moment,
				['sharedLabel' => $this->sharedLabel(moment: $moment)]
			);
		}

		return [
			'available' => true,
			'moments' => $moments,
			'indicators' => (array)($result['indicators'] ?? []),
		];
	}//end onCase()

	/**
	 * File an existing contact moment onto a further case.
	 *
	 * Through pipelinq's act, never by editing the set: the act records who
	 * filed it and when, and it is what guarantees no second record is made.
	 *
	 * @param string $momentId The contact moment.
	 * @param string $caseId The case to file it onto.
	 *
	 * @return array{filed: bool, reason: string} The outcome.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function fileOnAlsoCase(string $momentId, string $caseId): array {
		return $this->act(
			method: 'fileOnAlsoCase',
			arguments: ['momentId' => trim($momentId), 'caseId' => trim($caseId)],
			verb: 'filed',
		);
	}//end fileOnAlsoCase()

	/**
	 * Take a contact moment off one case.
	 *
	 * @param string $momentId The contact moment.
	 * @param string $caseId The case to take it off.
	 * @param string|null $newPrimary The next primary, when the primary is going.
	 *
	 * @return array{filed: bool, reason: string} The outcome.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): array {
		return $this->act(
			method: 'unfileFromCase',
			arguments: [
				'momentId' => trim($momentId),
				'caseId' => trim($caseId),
				'newPrimary' => $newPrimary,
			],
			verb: 'unfiled',
		);
	}//end unfileFromCase()

	/**
	 * What a shared contact moment says before somebody edits it.
	 *
	 * A case the reader may not see is COUNTED, never named: the marker warns
	 * an editor, it does not leak the docket of a case they have no business
	 * reading.
	 *
	 * @param array<string, mixed> $moment One row from pipelinq's leaf.
	 *
	 * @return string The marker, '' when the moment is on this case only.
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
	 */
	public function sharedLabel(array $moment): string {
		if (($moment['shared'] ?? false) !== true) {
			return '';
		}

		$named = count((array)($moment['alsoOnCases'] ?? []));
		$hidden = (int)($moment['alsoOnHiddenCount'] ?? 0);
		$total = ($named + $hidden);

		if ($total === 0) {
			return '';
		}

		if ($hidden === 0) {
			return "Also on {$total} other case" . ($total === 1 ? '' : 's');
		}

		if ($named === 0) {
			return "Also on {$hidden} case" . ($hidden === 1 ? '' : 's') . ' you may not see';
		}

		return "Also on {$named} other case" . ($named === 1 ? '' : 's')
			. " and {$hidden} you may not see";
	}//end sharedLabel()

	/**
	 * The payload pipelinq's leaf takes, from a dossiq contact moment.
	 *
	 * @param array<string, mixed> $moment The dossiq record.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payloadFor(array $moment): array {
		$channel = (string)($moment['notificationChannel'] ?? '');

		return [
			'title' => $this->titleFor(moment: $moment),
			'channel' => (self::CHANNELS[$channel] ?? $channel),
			// Required by pipelinq, and dossiq already requires it too, so
			// there is nothing to default and nothing to guess.
			'direction' => (string)($moment['direction'] ?? ''),
			'summary' => (string)($moment['summary'] ?? ''),
			'occurredAt' => (string)($moment['startTime'] ?? ''),
			'client' => (string)($moment['contact'] ?? ''),
		];
	}//end payloadFor()

	/**
	 * A subject for the appended moment.
	 *
	 * dossiq's contact moment has a summary and a nature but no subject, and
	 * pipelinq requires one. The nature is the closest thing to a subject that
	 * is not a copy of the body.
	 *
	 * @param array<string, mixed> $moment The dossiq record.
	 *
	 * @return string The subject.
	 */
	private function titleFor(array $moment): string {
		$nature = trim((string)($moment['nature'] ?? ''));
		if ($nature !== '') {
			return $nature;
		}

		$summary = trim((string)($moment['summary'] ?? ''));

		return ($summary === '' ? 'Contactmoment' : mb_substr($summary, 0, 120));
	}//end titleFor()

	/**
	 * Run one filing act and read its answer.
	 *
	 * @param string $method The act.
	 * @param array<string, mixed> $arguments Its named arguments.
	 * @param string $verb What the act did, for the log.
	 *
	 * @return array{filed: bool, reason: string} The outcome.
	 */
	private function act(string $method, array $arguments, string $verb): array {
		$answer = $this->gateway->ask(
			class: PipelinqGateway::CONTACT_MOMENT_FILING,
			method: $method,
			arguments: $arguments,
			fallback: null,
		);

		if ($answer['answered'] === false || is_array($answer['value']) === false) {
			return ['filed' => false, 'reason' => $answer['reason']];
		}

		$result = $answer['value'];
		$status = (int)($result['status'] ?? 0);

		if ($status === 200) {
			return ['filed' => true, 'reason' => ''];
		}

		$this->logger->info(
			"Dossiq pipelinq: a contact moment was not {$verb}, and the refusal is what a handler reads: "
			. (string)($result['error'] ?? ''),
			['status' => $status]
		);

		return ['filed' => false, 'reason' => (string)($result['error'] ?? 'pipelinq refused the act')];
	}//end act()
}//end class
