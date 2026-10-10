<?php

/**
 * Hands a signed beschikking to a real transport and answers what it said.
 *
 * 🔴 WHY THIS EXISTS (decision 148). BeschikkingService::verzend() used to ask
 * BerichtenboxRoutingService for a channel NAME, which calls nothing, and then
 * mark the decision sent, start the six weeks to object and write a public
 * "Beschikking verzonden" line. A letter nobody received read as delivered on
 * the citizen's own timeline. This class sends the decision notice through
 * {@see RequesterNoticeSender}, the one sender for requester notices: the
 * portal inbox first, digital post on a BSN next, e-mail last. Its answer is
 * `sent` with the transport's own message id and channel, or `not-sent` with
 * a reason, and the sender has already appended that answer to the case's
 * `outboundCommunications`.
 *
 * THE REQUESTER. The case row says where the requester can be reached. A case
 * that carries no BSN while the beschikking is addressed to a burger with one
 * takes the addressee's BSN for digital post: the beschikking names who it is
 * for, and that person's box is where the law expects it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * Sends the decision notice of one beschikking through the requester sender.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */
class BeschikkingDelivery {

	/**
	 * The template a beschikking notice is recorded under.
	 */
	public const TEMPLATE = 'beschikking';

	/**
	 * The moment a beschikking notice is sent at (REQ-WRN-006).
	 */
	public const MOMENT = 'decision';

	/**
	 * Constructor.
	 *
	 * @param RequesterNoticeSender $sender The one sender for requester notices.
	 * @param CaseStatusStore       $cases  Reads the case the beschikking is about.
	 */
	public function __construct(
		private readonly RequesterNoticeSender $sender,
		private readonly CaseStatusStore $cases,
	) {
	}//end __construct()

	/**
	 * Send the decision notice and answer the transport's result.
	 *
	 * @param array<string, mixed> $decision   The signed beschikking.
	 * @param string               $decisionId The beschikking uuid.
	 *
	 * @return array<string, mixed> The sender's delivery result: `status` is `sent` with
	 *                              `channel`, `messageId` and `sentAt`, or `not-sent` with
	 *                              `reasonCode` and `reason`.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function deliver(array $decision, string $decisionId): array {
		$reference = trim((string)($decision['reference'] ?? ''));

		return $this->sender->send(
			case: $this->requesterOf(decision: $decision),
			template: self::TEMPLATE,
			rendered: $this->render(decision: $decision, reference: $reference),
			moment: self::MOMENT,
			options: [
				'instanceId' => $decisionId,
				'dedupeKey' => 'beschikking:' . $decisionId,
				'recordExtras' => ['beschikkingId' => $decisionId, 'reference' => $reference],
			],
		);
	}//end deliver()

	/**
	 * The case row the sender reads the requester from.
	 *
	 * @param array<string, mixed> $decision The beschikking.
	 *
	 * @return array<string, mixed> The case row; `id` alone when the case could not be read.
	 */
	private function requesterOf(array $decision): array {
		$caseId = trim((string)($decision['caseId'] ?? ''));
		$case = null;
		if ($caseId !== '') {
			$case = $this->cases->loadCase(caseId: $caseId);
		}

		if (is_array($case) === false) {
			$case = [];
		}

		$case['id'] = $caseId;

		$addressee = (array)($decision['addressee'] ?? []);
		$bsn = trim((string)($addressee['bsn'] ?? ''));
		$caseBsn = trim((string)($case['initiatorSourceId'] ?? ''));
		if (($addressee['type'] ?? '') === 'burger' && $bsn !== '' && preg_match('/^\d{9}$/', $caseBsn) !== 1) {
			$case['initiatorType'] = 'person';
			$case['initiatorSourceId'] = $bsn;
		}

		return $case;
	}//end requesterOf()

	/**
	 * The notice the requester reads, in Dutch.
	 *
	 * @param array<string, mixed> $decision  The beschikking.
	 * @param string               $reference Its reference, or ''.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function render(array $decision, string $reference): array {
		$subject = 'Besluit over uw zaak';
		$opening = 'Wij hebben een besluit genomen over uw zaak.';
		if ($reference !== '') {
			$subject .= ' ' . $reference;
			$opening = 'Wij hebben een besluit genomen over uw zaak ' . $reference . '.';
		}

		$lines = [$opening];
		foreach (['rationale', 'legalRemediesClause'] as $key) {
			$text = trim((string)($decision[$key] ?? ''));
			if ($text !== '') {
				$lines[] = $text;
			}
		}

		return ['subject' => $subject, 'body' => implode("\n\n", $lines)];
	}//end render()
}//end class
