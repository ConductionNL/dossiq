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
	];

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService Termijn service.
	 * @param BerichtenboxRoutingService $router Router (dossiq notification-router).
	 * @param LoggerInterface $logger Logger.
	 * @param IJobList|null $jobList Optional job list for async dispatch.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly BerichtenboxRoutingService $router,
		private readonly LoggerInterface $logger,
		private readonly ?IJobList $jobList = null,
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
		$locale = (string)($context['locale'] ?? 'nl');
		$case = (string)($instance['case'] ?? ($context['case'] ?? '–'));
		$end = (string)($instance['endDateCurrent'] ?? ($context['endDate'] ?? '–'));

		$subject = '';
		$body = '';

		switch ($type) {
			case 'ontvangstbevestiging':
				$rendered = $this->acknowledgement(case: $case, end: $end, locale: $locale, context: $context);
				$subject = $rendered['subject'];
				$body = $rendered['body'];
				break;
			case 'extension':
				$newEnd = (string)($context['newEinddatum'] ?? $end);
				$subject = 'Verlenging termijn zaak ' . $case;
				$body = "Beste aanvrager,\n\n"
					. 'De termijn voor zaak ' . $case . ' is verlengd. De nieuwe deadline is ' . $newEnd . ".\n"
					. 'U vindt de officiele verlengingsbrief in uw burgerportaal.';
				break;
			case 'ingebrekestelling-receipt':
				$graceEnd = (string)($context['graceEnd'] ?? '–');
				$subject = 'Bevestiging ingebrekestelling zaak ' . $case;
				$body = "Beste aanvrager,\n\n"
					. 'Wij hebben uw ingebrekestelling voor zaak ' . $case . " ontvangen.\n"
					. 'De wettelijke begunstigingstermijn (AWB 4:17) eindigt op ' . $graceEnd . ".\n"
					. 'Indien er voor dat moment een beschikking is afgegeven, vervalt de dwangsom.';
				break;
			case 'hersteltermijn-request':
				$rendered = $this->informationRequest(case: $case, locale: $locale, context: $context);
				$subject = $rendered['subject'];
				$body = $rendered['body'];
				break;
			case 'hersteltermijn-reminder':
				$rendered = $this->informationReminder(case: $case, locale: $locale, context: $context);
				$subject = $rendered['subject'];
				$body = $rendered['body'];
				break;
			case 'doorzending':
				$rendered = $this->doorzending(case: $case, locale: $locale, context: $context);
				$subject = $rendered['subject'];
				$body = $rendered['body'];
				break;
			case 'dwangsom-payment':
				$amountCents = (int)($context['bedragCents'] ?? 0);
				$amountEur = number_format($amountCents / 100, 2, ',', '.');
				$ref = (string)($context['betalingsreferentie'] ?? '–');
				$subject = 'Uitbetaling dwangsom zaak ' . $case;
				$body = "Beste aanvrager,\n\n"
					. 'De dwangsom van EUR ' . $amountEur . ' voor zaak ' . $case . " is overgemaakt.\n"
					. 'Onder betalingsreferentie ' . $ref . '.';
				break;
		}//end switch

		return ['subject' => $subject, 'body' => $body, 'locale' => $locale];
	}//end renderTemplate()

	/**
	 * The request for missing information, Awb 4:5.
	 *
	 * It names what is missing and the date by which it has to arrive, because
	 * a request that says only "your application is incomplete" makes the
	 * applicant guess, and a guess is a second incomplete application.
	 *
	 * 🔴 NOT RUN THROUGH `IL10N`, for the same reason the acknowledgement is
	 * not: `IL10N` serves the interface language of the signed-in reader, and
	 * the reader here is a citizen with no Nextcloud session.
	 *
	 * @param string               $case    The case kenmerk.
	 * @param string               $locale  The declared language.
	 * @param array<string, mixed> $context The render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
	 */
	private function informationRequest(string $case, string $locale, array $context): array {
		$items = array_values(array_filter(array_map('strval', (array)($context['items'] ?? []))));
		$due = (string)($context['pauseDeadline'] ?? '-');
		$english = ($locale === 'en');

		$list = '';
		foreach ($items as $item) {
			$list .= '- ' . $item . "\n";
		}//end foreach

		if ($english === true) {
			return [
				'subject' => 'We need something more for case ' . $case,
				'body' => "Dear applicant,\n\n"
					. 'We cannot decide on case ' . $case . " yet. Please send us:\n"
					. $list
					. "\nWe need this by " . $due . ".\n"
					. 'The term for your case is paused until your answer arrives (Awb 4:5).',
			];
		}

		return [
			'subject' => 'Wij hebben nog iets nodig voor zaak ' . $case,
			'body' => "Beste aanvrager,\n\n"
				. 'Wij kunnen nog niet beslissen over zaak ' . $case . ". Stuur ons:\n"
				. $list
				. "\nWij ontvangen dit graag voor " . $due . ".\n"
				. 'De termijn van uw zaak staat stil tot uw antwoord binnen is (Awb 4:5).',
		];
	}//end informationRequest()

	/**
	 * The reminder that the request is still open, Awb 4:5.
	 *
	 * IT IS A REMINDER, NOT A SECOND REQUEST. The applicant already got the
	 * list and the date; repeating the whole letter reads as a new demand and
	 * makes people send everything twice. So it names the case, the day, and
	 * the words the case type declared for this reason, and nothing else.
	 *
	 * 🔴 NOT RUN THROUGH `IL10N`, for the reason the request above is not: the
	 * reader is a citizen with no Nextcloud session, and the language is the
	 * one the case type declares.
	 *
	 * @param string               $case    The case kenmerk.
	 * @param string               $locale  The declared language.
	 * @param array<string, mixed> $context The render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	private function informationReminder(string $case, string $locale, array $context): array {
		$due = (string)($context['pauseDeadline'] ?? '-');
		$declared = trim((string)($context['chaseText'] ?? ''));
		$english = ($locale === 'en');

		if ($english === true) {
			$body = "Dear applicant,\n\n"
				. 'We are still waiting for what we asked you for on case ' . $case . ".\n";
			if ($declared !== '') {
				$body .= $declared . "\n";
			}

			return [
				'subject' => 'A reminder about case ' . $case,
				'body' => $body
					. "\nWe need this by " . $due . ".\n"
					. 'The term for your case stays paused until your answer arrives (Awb 4:5).',
			];
		}

		$body = "Beste aanvrager,\n\n"
			. 'Wij wachten nog op wat wij u gevraagd hebben voor zaak ' . $case . ".\n";
		if ($declared !== '') {
			$body .= $declared . "\n";
		}

		return [
			'subject' => 'Herinnering over zaak ' . $case,
			'body' => $body
				. "\nWij ontvangen dit graag voor " . $due . ".\n"
				. 'De termijn van uw zaak staat stil tot uw antwoord binnen is (Awb 4:5).',
		];
	}//end informationReminder()

	/**
	 * The acknowledgement of receipt, Awb 4:3a.
	 *
	 * It names the kenmerk, what was received, the statutory term and the date
	 * it ends, how to follow the case and who to contact. A case type with no
	 * beslistermijn still confirms receipt and says that no statutory term
	 * applies, because "we have it" is the duty and the deadline is not.
	 *
	 * 🔴 THE TEXT IS NOT RUN THROUGH `IL10N`, AND THAT IS DELIBERATE. `IL10N`
	 * serves the INTERFACE language of the signed-in reader. The reader here is
	 * a citizen with no Nextcloud session, and the language is the one the case
	 * type declares. Translating against the handler's interface would mail a
	 * Dutch citizen in English because an administrator's browser was English.
	 * So the two languages the spec asks for are rendered from the declared
	 * locale, and the l10n files carry the interface strings this change adds.
	 *
	 * @param string               $case    The case kenmerk.
	 * @param string               $end     The end date of the statutory term.
	 * @param string               $locale  The declared language.
	 * @param array<string, mixed> $context The render context.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	private function acknowledgement(string $case, string $end, string $locale, array $context): array {
		$english = ($locale === 'en');
		$subjectOf = trim((string)($context['subject'] ?? ''));
		$contact = trim((string)($context['contact'] ?? ''));
		// The end date IS the test for a statutory term. Reading a separate
		// `hasTerm` flag would let a caller that knows the date but not the
		// flag render "no statutory period applies" beside the date itself.
		$hasTerm = ($end !== '' && $end !== '–');

		if (($context['contentWithheld'] ?? false) === true) {
			return $this->acknowledgementWaiting(english: $english);
		}

		if ($english === true) {
			return $this->acknowledgementInEnglish(
				case: $case,
				end: $end,
				hasTerm: $hasTerm,
				subjectOf: $subjectOf,
				contact: $contact,
			);
		}

		$termijn = "Op deze aanvraag geldt geen wettelijke beslistermijn.\n";
		if ($hasTerm === true) {
			$termijn = 'Wij nemen uiterlijk op ' . $end . ' een besluit op uw aanvraag. '
				. "Hebben wij meer tijd nodig, dan laten wij u dat voor die datum weten.\n";
		}

		$waarover = '';
		if ($subjectOf !== '') {
			$waarover = ' over ' . $subjectOf;
		}

		$waar = '';
		if ($contact !== '') {
			$waar = 'Met vragen kunt u terecht bij ' . $contact . ".\n";
		}

		return [
			'subject' => 'Ontvangstbevestiging zaak ' . $case,
			'body' => "Beste aanvrager,\n\n"
				. 'Wij hebben uw aanvraag' . $waarover
				. ' ontvangen en geregistreerd onder kenmerk ' . $case . ".\n"
				. $termijn
				. "U volgt deze zaak via het burgerportaal.\n"
				. $waar
				. "\nMet vriendelijke groet",
		];
	}//end acknowledgement()

	/**
	 * The doorzending, Awb 2:3.
	 *
	 * The applicant is told two things and no more: their case moved, and who
	 * has it now. What they must NOT be told is that they have to do anything,
	 * because under Awb 2:3 they do not: sending it on is our duty, not theirs.
	 * A message that reads like a rejection sends people to the counter with a
	 * complaint about a case that is being handled.
	 *
	 * It carries no deadline. The receiving party owns the term from here, and
	 * quoting the term we were counting would be a promise made on somebody
	 * else's behalf.
	 *
	 * @param string               $case    The case kenmerk.
	 * @param string               $locale  The declared language.
	 * @param array<string, mixed> $context The render context, carrying the destination.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	private function doorzending(string $case, string $locale, array $context): array {
		$destination = trim((string)($context['destination'] ?? ''));
		if ($destination === '') {
			$destination = 'de behandelende afdeling';
		}

		if ($locale === 'en') {
			return [
				'subject' => 'Your case ' . $case . ' has moved',
				'body' => "Dear applicant,\n\n"
					. 'We passed your case ' . $case . ' on to ' . $destination . ".\n"
					. "They handle it from here, and you do not need to send anything again.\n"
					. "You follow the case in the citizen portal.\n"
					. "\nKind regards",
			];
		}

		return [
			'subject' => 'Uw zaak ' . $case . ' is doorgestuurd',
			'body' => "Beste aanvrager,\n\n"
				. 'Wij hebben uw zaak ' . $case . ' doorgestuurd naar ' . $destination . ".\n"
				. "Zij behandelen de zaak verder. U hoeft niets opnieuw op te sturen.\n"
				. "U volgt de zaak via het burgerportaal.\n"
				. "\nMet vriendelijke groet",
		];
	}//end doorzending()

	/**
	 * The acknowledgement in English.
	 *
	 * Split from its Dutch twin so neither has to be read through the other.
	 * They are two letters, not one letter with branches.
	 *
	 * @param string  $case      The case kenmerk.
	 * @param string  $end       The end date of the statutory term.
	 * @param boolean $hasTerm   Whether a statutory term applies at all.
	 * @param string  $subjectOf What the application is about, when it is known.
	 * @param string  $contact   Who to contact, when the case type names somebody.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	private function acknowledgementInEnglish(
		string $case,
		string $end,
		bool $hasTerm,
		string $subjectOf,
		string $contact,
	): array {
		$term = "No statutory decision period applies to this application.\n";
		if ($hasTerm === true) {
			$term = 'We decide on your application by ' . $end . ' at the latest. '
				. "If we need more time, we tell you before that date.\n";
		}

		$about = '';
		if ($subjectOf !== '') {
			$about = ' about ' . $subjectOf;
		}

		$where = '';
		if ($contact !== '') {
			$where = 'Questions go to ' . $contact . ".\n";
		}

		return [
			'subject' => 'Acknowledgement of receipt for case ' . $case,
			'body' => "Dear applicant,\n\n"
				. 'We have received your application' . $about
				. ' and registered it under reference ' . $case . ".\n"
				. $term
				. "You can follow this case in the citizen portal.\n"
				. $where
				. "\nKind regards",
		];
	}//end acknowledgementInEnglish()

	/**
	 * The acknowledgement that carries no case content.
	 *
	 * The Berichtenbox pattern: say a message is waiting and keep the content
	 * where it is. This is not a second template. It is the same message with
	 * its content withheld, decided once per case type rather than per message,
	 * because a municipality that rules personal data out of e-mail rules it out
	 * everywhere.
	 *
	 * @param boolean $english Whether the case type declares English.
	 *
	 * @return array{subject:string, body:string} The rendered message.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	private function acknowledgementWaiting(bool $english): array {
		if ($english === true) {
			return [
				'subject' => 'A message about your application is waiting for you',
				'body' => "Dear applicant,\n\n"
					. "We have received your application and a message about it is waiting for you.\n"
					. "Sign in to the citizen portal to read it.\n\n"
					. 'Kind regards',
			];
		}

		return [
			'subject' => 'Er staat een bericht over uw aanvraag voor u klaar',
			'body' => "Beste aanvrager,\n\n"
				. "Wij hebben uw aanvraag ontvangen en er staat een bericht voor u klaar.\n"
				. "Log in op het burgerportaal om het te lezen.\n\n"
				. 'Met vriendelijke groet',
		];
	}//end acknowledgementWaiting()
}//end class
