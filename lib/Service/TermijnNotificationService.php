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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCA\Dossiq\BackgroundJob\DeadlineNotificationDispatchJob;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Kernel\Contracts\TermijnNotificationServiceInterface;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Term\TermNoticeSources;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Burger notification template renderer + dispatcher.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
class TermijnNotificationService implements TermijnNotificationServiceInterface {
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
	 * @param TermNoticeSources $notices The term instances, the notice sender and the term letters.
	 * @param LoggerInterface $logger Logger.
	 * @param IJobList|null $jobList Optional job list for async dispatch.
	 * @param RequesterNoticeSender|null $requester The one sender for requester notices. Absent,
	 *        a sender over the e-mail transport alone, which is what this service did before.
	 * @param CaseNumberReader|null $caseNumbers Turns a case UUID into the number a letter names.
	 *        Absent, a letter names whatever reference its caller handed over.
	 */
	public function __construct(
		private readonly TermNoticeSources $notices,
		private readonly LoggerInterface $logger,
		private readonly ?IJobList $jobList = null,
		private readonly ?RequesterNoticeSender $requester = null,
		private readonly ?CaseNumberReader $caseNumbers = null,
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
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
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

		$this->refuseUnknownTemplate(type: $type);

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

		$this->enqueue(
			job: [
				'type' => $type,
				'termijnInstanceId' => $termInstanceId,
				'recipientUserId' => $recipientUserId,
				'context' => $context,
			]
		);
		return true;
	}//end queueTermijnNotification()

	/**
	 * Add the dispatch job and log that it was queued.
	 *
	 * @param array{type: string, termijnInstanceId: string, recipientUserId: string, context: array} $job The dispatch job's arguments.
	 *
	 * @return void
	 */
	private function enqueue(array $job): void {
		$this->jobList->add(DeadlineNotificationDispatchJob::class, $job);
		$this->logger->info(
			'TermijnNotification queued',
			['type' => $job['type'], 'recipient' => $job['recipientUserId'], 'instance' => $job['termijnInstanceId']]
		);
	}//end enqueue()

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
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-a-case-type-may-send-its-acknowledgement-by-e-mail-as-well-req-ack-copy-001
	 */
	public function sendTermijnNotification(
		string $type,
		string $termInstanceId,
		string $recipientUserId,
		array $context = [],
		array $requester = [],
	): array {
		$this->refuseUnknownTemplate(type: $type);

		$notice = new TermNotice();
		$notice->type = $type;
		$notice->termInstanceId = $termInstanceId;
		$notice->recipientUserId = $recipientUserId;
		$notice->context = $context;
		$notice->instance = ($this->notices->termService->getTermijnInstance($termInstanceId) ?? []);

		$payload = $this->noticePayload(notice: $notice);
		$notice->case = $this->noticeCase(notice: $notice, requester: $requester);

		$dispatch = $this->dispatchNotice(notice: $notice, payload: $payload);
		if ($dispatch['status'] !== RequesterNoticeSender::STATUS_SENT) {
			throw new NoticeNotSentException(
				reasonCode: (string)$dispatch['reasonCode'],
				reason: (string)$dispatch['reason'],
				delivery: $dispatch,
			);
		}

		$payload['dispatch'] = $dispatch;
		$this->logDispatched(notice: $notice, dispatch: $dispatch);

		return $payload;
	}//end sendTermijnNotification()

	/**
	 * Refuse a template this service does not know.
	 *
	 * @param string $type The template.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the template is unknown.
	 */
	private function refuseUnknownTemplate(string $type): void {
		if (in_array($type, self::TEMPLATES, true) === false) {
			throw new InvalidArgumentException('Unknown template: ' . $type);
		}
	}//end refuseUnknownTemplate()

	/**
	 * The rendered notice with its recipient, term instance and template.
	 *
	 * @param TermNotice $notice The notice.
	 *
	 * @return array<string, mixed>
	 */
	private function noticePayload(TermNotice $notice): array {
		$payload = $this->renderTemplate(
			type: $notice->type,
			instance: $notice->instance,
			context: $this->withCaseNumber(instance: $notice->instance, context: $notice->context)
		);

		$payload['recipient'] = $notice->recipientUserId;
		$payload['deadlineInstance'] = $notice->termInstanceId;
		$payload['template'] = $notice->type;

		return $payload;
	}//end noticePayload()

	/**
	 * The case the notice goes out on: the requester's, named after the term's case when it has no id.
	 *
	 * @param TermNotice           $notice    The notice.
	 * @param array<string, mixed> $requester The requester's case row, possibly empty.
	 *
	 * @return array<string, mixed>
	 */
	private function noticeCase(TermNotice $notice, array $requester): array {
		$case = $requester;
		if (trim((string)($case['id'] ?? '')) === '') {
			$case['id'] = $this->caseRefOf(instance: $notice->instance, context: $notice->context);
		}

		return $case;
	}//end noticeCase()

	/**
	 * Hand the rendered notice to the requester notice sender.
	 *
	 * @param TermNotice           $notice  The notice, with its case.
	 * @param array<string, mixed> $payload The rendered notice.
	 *
	 * @return array<string, mixed> What the sender answered.
	 */
	private function dispatchNotice(TermNotice $notice, array $payload): array {
		$sender = ($this->requester ?? new RequesterNoticeSender(email: $this->notices->sender, logger: $this->logger));
		$context = $notice->context;

		return $sender->send(
			case: $notice->case,
			template: $notice->type,
			rendered: ['subject' => (string)$payload['subject'], 'body' => (string)$payload['body']],
			moment: (string)($context['moment'] ?? $sender->momentFor(template: $notice->type)),
			options: [
				'recipient' => $notice->recipientUserId,
				'instanceId' => $notice->termInstanceId,
				'dedupeKey' => (string)($context['dedupeKey'] ?? ''),
				'recordExtras' => (array)($context['recordExtras'] ?? []),
				'channels' => ($context['channels'] ?? null),
			],
		);
	}//end dispatchNotice()

	/**
	 * Log that the notice went out.
	 *
	 * @param TermNotice           $notice   The notice.
	 * @param array<string, mixed> $dispatch What the sender answered.
	 *
	 * @return void
	 */
	private function logDispatched(TermNotice $notice, array $dispatch): void {
		$this->logger->info(
			'TermijnNotification dispatched',
			[
				'type' => $notice->type,
				'instance' => $notice->termInstanceId,
				'notificationChannel' => (string)$dispatch['channel'],
				'duplicate' => (($dispatch['duplicate'] ?? false) === true),
			]
		);
	}//end logDispatched()

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
	 * The caller's context, with the case named by its number.
	 *
	 * 🔴 A LETTER NAMES THE CASE BY ITS NUMBER, NEVER BY ITS UUID. The term
	 * instance links its case by UUID, and the information request and the
	 * pause reminder hand that same UUID over as `case`, so a requester read
	 * "Wij hebben nog iets nodig voor zaak 7849aeec-..." for case 2026-0018.
	 * A reference that is a UUID is looked up here, once, for every letter;
	 * a reference that is already a number is left as the caller wrote it.
	 * Only the rendered text changes: the opt-out and the delivery record keep
	 * the UUID, through caseRefOf().
	 *
	 * @param array<string, mixed> $instance The term instance, or [].
	 * @param array<string, mixed> $context  The caller's context.
	 *
	 * @return array<string, mixed> The context the letter is rendered with.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-letter-names-the-case-by-its-number
	 */
	private function withCaseNumber(array $instance, array $context): array {
		$reference = trim((string)($context['case'] ?? ''));
		if ($reference === '') {
			$reference = trim((string)($instance['case'] ?? ''));
		}

		if ($this->caseNumbers === null || $this->caseNumbers->isCaseId(reference: $reference) === false) {
			return $context;
		}

		$number = $this->caseNumbers->numberOf(caseId: $reference);
		if ($number !== '') {
			$context['case'] = $number;
		}

		return $context;
	}//end withCaseNumber()

	/**
	 * Render a template (nl) into a payload with subject + body.
	 *
	 * @param string $type Template type.
	 * @param array<string, mixed> $instance TermijnInstance (may be empty).
	 * @param array<string, mixed> $context Extra context.
	 *
	 * @return array{subject:string, body:string, locale:string}
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	public function renderTemplate(string $type, array $instance, array $context): array {
		return $this->notices->letters->render(type: $type, instance: $instance, context: $context);
	}//end renderTemplate()

}//end class
