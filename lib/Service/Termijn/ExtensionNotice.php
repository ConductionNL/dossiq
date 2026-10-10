<?php

/**
 * Dossiq ExtensionNotice.
 *
 * Tells the requester that their term was extended, with the reason and the
 * new end date (Woo art. 4.4 lid 2: the body extends once by two weeks and
 * tells the requester so, with reasons, before the first term ends).
 *
 * Called right after the extension, by WOOAssessmentController::extendDeadline()
 * once WOODeadlineService::extendDeadline() answered, never from a listener
 * nobody dispatches. The extension stands when the notice cannot go out: the
 * answer says so, and the case carries the not-sent record, so the handler can
 * tell the requester another way before the original term ends (REQ-WRN-005).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnNotificationService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the extension notice and answers whether it went out.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
 */
class ExtensionNotice {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService            $settings      The register, the case schema and the object service.
	 * @param TermijnNotificationService $notifications Renders the extension letter and hands it to the sender.
	 * @param LoggerInterface            $logger        Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly TermijnNotificationService $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Tell the requester of a case that its term was extended.
	 *
	 * @param string $caseId     The case UUID.
	 * @param string $instanceId The extended term instance.
	 * @param string $reason     Why the term was extended.
	 * @param string $newEnd     The new end date (Y-m-d).
	 *
	 * @return array{noticeStatus: string, noticeChannel: string, noticeReasonCode: string, noticeReason: string}
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
	 */
	public function tell(string $caseId, string $instanceId, string $reason, string $newEnd): array {
		$case = $this->caseRow(caseId: $caseId);

		try {
			$payload = $this->notifications->sendTermijnNotification(
				'extension',
				$instanceId,
				'',
				[
					'case' => (string)($case['identifier'] ?? $caseId),
					'reason' => $reason,
					'newEinddatum' => $newEnd,
					'dedupeKey' => 'extension|' . $instanceId . '|' . $newEnd,
				],
				$case,
			);
		} catch (NoticeNotSentException $e) {
			return $this->answer(status: 'not-sent', channel: '', code: $e->getReasonCode(), reason: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: the extension notice could not be sent', ['case' => $caseId, 'exception' => $e]);
			return $this->answer(status: 'not-sent', channel: '', code: 'send-failed', reason: 'The extension notice could not be sent.');
		}

		return $this->answer(status: 'sent', channel: (string)($payload['dispatch']['channel'] ?? ''), code: '', reason: '');
	}//end tell()

	/**
	 * The case row, read as the system; just its id when it cannot be read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case row.
	 */
	private function caseRow(string $caseId): array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['id' => $caseId];
		}

		try {
			$row = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the case behind an extension notice could not be read', ['case' => $caseId]);
			$row = null;
		}

		if (is_array($row) === false) {
			return ['id' => $caseId];
		}

		$row['id'] = (string)($row['id'] ?? $caseId);
		return $row;
	}//end caseRow()

	/**
	 * The notice part of the extension answer.
	 *
	 * @param string $status  `sent` or `not-sent`.
	 * @param string $channel The channel it went through, or ''.
	 * @param string $code    Why not, or ''.
	 * @param string $reason  The sentence, or ''.
	 *
	 * @return array{noticeStatus: string, noticeChannel: string, noticeReasonCode: string, noticeReason: string}
	 */
	private function answer(string $status, string $channel, string $code, string $reason): array {
		return [
			'noticeStatus' => $status,
			'noticeChannel' => $channel,
			'noticeReasonCode' => $code,
			'noticeReason' => $reason,
		];
	}//end answer()
}//end class
