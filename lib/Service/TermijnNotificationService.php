<?php

/**
 * Dossiq TermijnNotificationService.
 *
 * Renders + routes the four AWB notification templates (ontvangstbevestiging,
 * extension, ingebrekestelling-receipt, dwangsom-payment) using the
 * application's translation layer (en/nl) and dispatches them to the
 * recipient via {@see BerichtenboxRoutingService} (or returns the
 * rendered payload when no router is wired).
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
use OCA\Dossiq\Service\Termijn\TermLetters;
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
	 * @param BerichtenboxRoutingService $router Router (dossiq notification-router).
	 * @param LoggerInterface $logger Logger.
	 * @param IJobList|null $jobList Optional job list for async dispatch.
	 * @param TermLetters|null $letters The wording of every term notification. Left out it is
	 *        built here, because it has no collaborators of its own and every caller that
	 *        wired this service before the letters were split out passes four arguments.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly BerichtenboxRoutingService $router,
		private readonly LoggerInterface $logger,
		private readonly ?IJobList $jobList = null,
		private readonly TermLetters $letters = new TermLetters(),
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
	 * @param string $recipientUserId Recipient user id.
	 * @param array<string, mixed> $context Extra context (zaak ref, dates, amounts).
	 *
	 * @return array<string, mixed> Dispatched payload (with rendered subject +
	 *                              body and the `verzending` delivery record).
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
	 */
	public function sendTermijnNotification(
		string $type,
		string $termInstanceId,
		string $recipientUserId,
		array $context = [],
	): array {
		if (in_array($type, self::TEMPLATES, true) === false) {
			throw new InvalidArgumentException('Unknown template: ' . $type);
		}

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		$payload = $this->renderTemplate(type: $type, instance: $instance ?? [], context: $context);

		$payload['recipient'] = $recipientUserId;
		$payload['deadlineInstance'] = $termInstanceId;
		$payload['template'] = $type;

		// Route the rendered notification through the dossiq notification
		// router so the burger actually receives it; the returned delivery
		// record (kanaal / berichtId / verzondenOp) is attached to the payload
		// and is what the caller persists as proof of dispatch.
		$payload['dispatch'] = $this->router->routeToBerichtenbox(
			[
				'reference' => $termInstanceId,
				'addressee' => (array)($context['addressee'] ?? []),
			]
		);

		$this->logger->info(
			'TermijnNotification dispatched',
			[
				'type' => $type,
				'recipient' => $recipientUserId,
				'instance' => $termInstanceId,
				'notificationChannel' => $payload['dispatch']['notificationChannel'],
			]
		);

		return $payload;
	}//end sendTermijnNotification()

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
