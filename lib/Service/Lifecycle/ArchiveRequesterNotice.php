<?php

/**
 * Tells the requester that their case is completed, when the handler archives it.
 *
 * Board DqArchiveren offers it under the reason ("Sanne de Vries laten weten
 * dat de zaak is afgehandeld"). The notice goes through RequesterNoticeSender,
 * the one sender for requester notices, so it takes the requester's channel in
 * the declared order and is recorded on the case like every other notice.
 *
 * The answer uses the keys an extension answers with (`noticeStatus`,
 * `noticeChannel`, `noticeReasonCode`, `noticeReason`), and a notice that did
 * not go out always says why.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
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
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * Sends the "your case is completed" notice for an archived case.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */
class ArchiveRequesterNotice {

	/**
	 * The template the notice is recorded under.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'case-archived';

	/**
	 * The moment the notice is sent at.
	 *
	 * @var string
	 */
	public const MOMENT = 'closure';

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore            $store  Reads the case.
	 * @param RequesterNoticeSender|null $sender The one sender for requester notices; absent, nothing is sent.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly ?RequesterNoticeSender $sender = null,
	) {
	}//end __construct()

	/**
	 * Tell the requester that the case is completed.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{noticeStatus: string, noticeChannel: string, noticeReasonCode: string, noticeReason: string}
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function tell(string $caseId): array {
		if ($this->sender === null) {
			return $this->notSent(code: 'notice-not-wired', reason: 'No sender is wired, so the requester was not told.');
		}

		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			return $this->notSent(code: 'case-not-found', reason: 'This case could not be found.');
		}

		$result = $this->sender->send(
			case: $case,
			template: self::TEMPLATE,
			rendered: $this->render(case: $case),
			moment: self::MOMENT,
			options: ['instanceId' => $caseId, 'dedupeKey' => 'archived:' . $caseId],
		);

		return [
			'noticeStatus' => (string)($result['status'] ?? RequesterNoticeSender::STATUS_NOT_SENT),
			'noticeChannel' => (string)($result['channel'] ?? ''),
			'noticeReasonCode' => (string)($result['reasonCode'] ?? ''),
			'noticeReason' => (string)($result['reason'] ?? ''),
		];
	}//end tell()

	/**
	 * The notice in the requester's words: the case, its result and what that result means.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function render(array $case): array {
		$number = trim((string)($case['identifier'] ?? ''));
		$title = trim((string)($case['title'] ?? ''));

		$subject = 'Uw zaak is afgehandeld';
		if ($number !== '') {
			$subject = 'Uw zaak ' . $number . ' is afgehandeld';
		}

		$named = trim($number . ' ' . $title);
		if ($named === '') {
			$named = 'over uw verzoek';
		}

		$lines = ['Wij hebben uw zaak ' . $named . ' afgehandeld.'];
		$result = trim((string)($case['resultPublicLabel'] ?? ''));
		if ($result !== '') {
			$lines[] = 'Het resultaat: ' . $result . '.';
		}

		$explained = trim((string)($case['resultPublicDescription'] ?? ''));
		if ($explained !== '') {
			$lines[] = $explained;
		}

		return ['subject' => $subject, 'body' => implode("\n\n", $lines)];
	}//end render()

	/**
	 * A notice that did not go out, with its reason.
	 *
	 * @param string $code   The reason code.
	 * @param string $reason The sentence.
	 *
	 * @return array{noticeStatus: string, noticeChannel: string, noticeReasonCode: string, noticeReason: string}
	 */
	private function notSent(string $code, string $reason): array {
		return [
			'noticeStatus' => RequesterNoticeSender::STATUS_NOT_SENT,
			'noticeChannel' => '',
			'noticeReasonCode' => $code,
			'noticeReason' => $reason,
		];
	}//end notSent()
}//end class
