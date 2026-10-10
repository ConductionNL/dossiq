<?php

/**
 * Dossiq TermijnNotificationService.
 *
 * Renders the AWB notification templates (ontvangstbevestiging, extension,
 * ingebrekestelling-receipt, dwangsom-payment and the rest of TEMPLATES) in
 * en/nl and hands them to {@see RequesterNoticeSender}, which puts each in the
 * requester's portal inbox, sends it as digital post or mails it through
 * {@see TermNoticeSender} (integriq first, each notice once), and records the
 * delivery result on the case.
 *
 * It used to hand them to BerichtenboxRoutingService::routeToBerichtenbox(),
 * which only logged and returned a derived id, so no notice reached anyone.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\CaseType\CaseTypeHandling;

use InvalidArgumentException;
use OCA\Dossiq\BackgroundJob\DeadlineNotificationDispatchJob;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Termijn\TermLetters;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Burger notification template renderer + dispatcher.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
class TermijnNotificationService {
	public const TEMPLATES = [
		'ontvangstbevestiging',
		'extension',
		'ingebrekestelling-receipt',
		'dwangsom-payment',
		'hersteltermijn-request',
		'hersteltermijn-reminder',
		'doorzending',
		// The aanvraag was judged niet-ontvankelijk at intake and the case
		// closed on that result. The
		// applicant is told through the case type's declared moment, so this
		// is a template beside the others rather than a message a service
		// writes for itself.
		'niet-ontvankelijk',
	];

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService Termijn service.
	 * @param TermNoticeSender $sender Mails a notice once, after integriq allows it.
	 * @param LoggerInterface $logger Logger.
	 * @param IJobList|null $jobList Optional job list for async dispatch.
	 * @param TermLetters $letters The wording of every term notification. Defaulted rather than
	 *        required, because it has no collaborators of its own and every caller that wired
	 *        this service before the letters were split out passes four arguments.
	 * @param RequesterNoticeSender|null $requester The one sender for requester notices. Absent,
	 *        a sender over the e-mail transport alone, which is what this service did before.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly TermNoticeSender $sender,
		private readonly LoggerInterface $logger,
		private readonly ?IJobList $jobList = null,
		private readonly TermLetters $letters = new TermLetters(),
		private readonly ?RequesterNoticeSender $requester = null,
	) {
	}//end __construct()

	/**
	 * Enqueue a notification for asynchronous dispatch via NC's QueuedJob
	 * runner. The same payload contract as {@see sendTermijnNotification}
	 * but non-blocking on SMTP / berichtenbox-router failure — the job
	 * runner retries automatically.
	 *
	 * @param string $type Template type.
	 * @param string $termInstanceId Instance id.
	 * @param string $recipientUserId Recipient user id.
	 * @param array<string, mixed> $context Extra context.
	 * @param array<string, mixed> $caseType The case type of the term's case, or
	 *                                       [] when the caller has not resolved one.
	 *
	 * @return bool TRUE when the job was queued; FALSE when no job list is
	 *              wired (callers MAY fall back to synchronous send), or when
	 *              the case type does not send this message.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	public function queueTermijnNotification(
		string $type,
		string $termInstanceId,
		string $recipientUserId,
		array $context = [],
		array $caseType = [],
	): bool {
		if ($this->jobList === null) {
			return false;
		}

		if (in_array($type, self::TEMPLATES, true) === false) {
			throw new InvalidArgumentException('Unknown template: ' . $type);
		}

		// The case type decides which of these go out, and CaseTypeHandling is
		// the one reader of that decision. An empty $caseType is a caller that
		// has not resolved one, and it queues as it always did: silently
		// dropping a statutory message because a parameter was not threaded
		// through would be the worst possible reading of "not configured".
		if ($caseType !== [] && (new CaseTypeHandling())->sends(caseType: $caseType, message: $type) === false) {
			$this->logger->info(
				'TermijnNotification not sent: the case type does not send it',
				['type' => $type, 'instance' => $termInstanceId]
			);
			return false;
		}

		$this->jobList->add(
			DeadlineNotificationDispatchJob::class,
			[
				'type' => $type,
				'termijnInstanceId' => $termInstanceId,
				'recipientUserId' => $recipientUserId,
				'context' => $context,
			]
		);
		$this->logger->info(
			'TermijnNotification queued',
			['type' => $type, 'recipient' => $recipientUserId, 'instance' => $termInstanceId]
		);
		return true;
	}//end queueTermijnNotification()

	/**
	 * Send a templated termijnbewaking notification.
	 *
	 * @param string $type Template type.
	 * @param string $termInstanceId Instance id.
	 * @param string $recipientUserId The address the caller holds for the requester, or ''.
	 * @param array<string, mixed> $context Extra context (zaak ref, dates, amounts).
	 * @param array<string, mixed> $requester The case row, when the caller has it: the sender
	 *        then also offers the portal inbox and digital post. [] keeps to the address.
	 * @return array<string, mixed> Dispatched payload (with rendered subject +
	 *                              body and the `dispatch` delivery result, status `sent`).
	 * @throws NoticeNotSentException When nothing was sent; its getDelivery() is the
	 *         not-sent result. A throw rather than a returned `not-sent`, because every
	 *         caller already treats it as "nothing went out" and a caller that forgets to
	 *         read a status cannot record a notice nobody received as sent.
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 */
	public function sendTermijnNotification(
		string $type,
		string $termInstanceId,
		string $recipientUserId,
		array $context = [],
		array $requester = [],
	): array {
		if (in_array($type, self::TEMPLATES, true) === false) {
			throw new InvalidArgumentException('Unknown template: ' . $type);
		}

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		$payload = $this->renderTemplate(type: $type, instance: $instance ?? [], context: $context);

		$payload['recipient'] = $recipientUserId;
		$payload['deadlineInstance'] = $termInstanceId;
		$payload['template'] = $type;

		$case = $requester;
		if (trim((string)($case['id'] ?? '')) === '') {
			$case['id'] = $this->caseRefOf(instance: ($instance ?? []), context: $context);
		}

		$sender = ($this->requester ?? new RequesterNoticeSender(email: $this->sender, logger: $this->logger));
		$moment = (string)($context['moment'] ?? $sender->momentFor(template: $type));

		$dispatch = $sender->send(
			case: $case,
			template: $type,
			rendered: ['subject' => (string)$payload['subject'], 'body' => (string)$payload['body']],
			moment: $moment,
			options: [
				'recipient' => $recipientUserId,
				'instanceId' => $termInstanceId,
				'dedupeKey' => (string)($context['dedupeKey'] ?? ''),
				'recordExtras' => (array)($context['recordExtras'] ?? []),
			],
		);

		if ($dispatch['status'] !== RequesterNoticeSender::STATUS_SENT) {
			throw new NoticeNotSentException(
				reasonCode: (string)$dispatch['reasonCode'],
				reason: (string)$dispatch['reason'],
				delivery: $dispatch,
			);
		}

		$payload['dispatch'] = $dispatch;

		$this->logger->info(
			'TermijnNotification dispatched',
			[
				'type' => $type,
				'instance' => $termInstanceId,
				'notificationChannel' => (string)$dispatch['channel'],
				'duplicate' => (($dispatch['duplicate'] ?? false) === true),
			]
		);

		return $payload;
	}//end sendTermijnNotification()

	/**
	 * The case a notice is about, as integriq's case-scoped opt-out knows it.
	 *
	 * The term's own case link first, which is the case UUID the unsubscribe
	 * link of a case mail carries; the caller's context otherwise.
	 *
	 * @param array<string, mixed> $instance The term instance, or [].
	 * @param array<string, mixed> $context  The caller's context.
	 *
	 * @return string The case reference, or ''.
	 */
	private function caseRefOf(array $instance, array $context): string {
		$fromTerm = trim((string)($instance['case'] ?? ''));
		if ($fromTerm !== '') {
			return $fromTerm;
		}

		return trim((string)($context['case'] ?? ''));
	}//end caseRefOf()

	/**
	 * Render a template (nl) into a payload with subject + body.
	 *
	 * @param string $type Template type.
	 * @param array<string, mixed> $instance TermijnInstance (may be empty).
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return array{subject:string, body:string, locale:string}
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	public function renderTemplate(string $type, array $instance, array $context): array {
		return $this->letters->render(type: $type, instance: $instance, context: $context);
	}//end renderTemplate()

}//end class
