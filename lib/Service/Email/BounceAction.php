<?php

/**
 * Dossiq Bounce Action
 *
 * Sending a misdirected message on to the administrative body it was meant for,
 * with the original intact, and recording that we did (design D-4).
 *
 * 🔴 THIS IS THE DOORZENDPLICHT, NOT A REJECTION. Awb 2:3 says a document sent
 * to the wrong administrative body is forwarded to the right one. OTOBO calls
 * the act Bounce (`AgentTicketBounce.pm`) and it means send it on. A bounce
 * implemented as a reject loses the statutory duty entirely, and one that
 * creates a case first and closes it records a case that never existed. So this
 * class sends nothing back to the sender and creates nothing.
 *
 * 🔴 IT IS ALSO NOT THE SAME WORD AS
 * {@see \OCA\Dossiq\Service\Email\Filters\BounceNotificationFilter}. That
 * filter is about a bounce MESSAGE arriving, a postmaster's delivery failure
 * report. This is about the ACT of bouncing. One is a thing that happens to us,
 * the other a thing we do.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a misdirected message on to the right body, and records it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
class BounceAction {

	/**
	 * The name the intake log records against a bounce.
	 */
	public const NAME = 'bounce';

	/**
	 * Constructor.
	 *
	 * @param IMailer         $mailer    Nextcloud's mailer.
	 * @param IntakeLog       $log       The intake log.
	 * @param IAppConfig      $appConfig Instance configuration, for the from-address.
	 * @param LoggerInterface $logger    Logger.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IntakeLog $log,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one message on to another address.
	 *
	 * @param InboundMessage        $message   The message.
	 * @param string                $toAddress The body it goes to.
	 * @param string                $reason    Why, as the handler wrote it.
	 * @param string                $actorId   The user id of whoever bounced it, or '' for the pipeline.
	 * @param array<string, string> $results   The four authentication results.
	 *
	 * @return array{sent: bool, entryId: string} What happened, and the log entry.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function bounce(
		InboundMessage $message,
		string $toAddress,
		string $reason,
		string $actorId = '',
		array $results = [],
	): array {
		$recipient = InboundMessage::addressIn(value: $toAddress);
		if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
			$this->logger->warning(
				'Dossiq: a bounce was asked for without a usable address, so nothing was sent',
				['messageId' => $message->messageId]
			);
			return ['sent' => false, 'entryId' => ''];
		}

		$sent = $this->forward(message: $message, recipient: $recipient);

		$recorded = $results;
		if ($recorded === []) {
			$recorded = AuthenticationVerdict::unknown();
		}

		$entryId = $this->log->record(
			message: $message,
			verdict: FilterVerdict::forward(
				filterName: self::NAME,
				reason: $reason,
				forwardTo: $recipient
			),
			results: $recorded,
			outcome: IntakeLog::OUTCOME_FORWARDED,
			reason: $this->recordedReason(recipient: $recipient, reason: $reason, actorId: $actorId, sent: $sent)
		);

		if ($entryId !== '') {
			$this->log->amend(
				entryId: $entryId,
				changes: [
					'forwardedTo' => $recipient,
					'forwardedBy' => $actorId,
					'forwardedReason' => $reason,
				]
			);
		}

		return ['sent' => $sent, 'entryId' => $entryId];
	}//end bounce()

	/**
	 * Put the original on the wire, unchanged.
	 *
	 * The original source travels as an attached `message/rfc822` part rather
	 * than as quoted text, because Awb 2:3 forwards the DOCUMENT and a
	 * re-rendered body is a different document. When the source could not be
	 * read the headers we do hold are sent instead, and the log says the
	 * original was not attached rather than pretending it was.
	 *
	 * @param InboundMessage $message   The message.
	 * @param string         $recipient The address it goes to.
	 *
	 * @return boolean True when the mailer accepted it.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function forward(InboundMessage $message, string $recipient): bool {
		$fromAddress = trim($this->appConfig->getValueString(Application::APP_ID, 'email_from_address', ''));
		$fromName = trim($this->appConfig->getValueString(Application::APP_ID, 'email_from_name', ''));
		if ($fromAddress === '') {
			$this->logger->warning(
				'Dossiq: no from-address is configured, so a bounce could not be sent',
				['messageId' => $message->messageId]
			);
			return false;
		}

		try {
			$outbound = $this->mailer->createMessage();
			$outbound->setFrom([$fromAddress => $fromName]);
			$outbound->setTo([$recipient]);
			$outbound->setSubject('Doorgezonden: ' . mb_substr($message->subject, 0, 200));
			$outbound->setPlainBody($this->coveringNote(message: $message));
			if ($message->source !== '') {
				$attachment = $this->mailer->createAttachment(
					$message->source,
					'origineel.eml',
					'message/rfc822'
				);
				$outbound->attach($attachment);
			}

			$this->mailer->send($outbound);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: sending a bounce failed',
				['messageId' => $message->messageId, 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end forward()

	/**
	 * The covering note the receiving body reads above the original.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return string The note.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function coveringNote(InboundMessage $message): string {
		$lines = [
			'Dit bericht is bij ons binnengekomen en hoort bij uw organisatie.',
			'Wij zenden het door op grond van artikel 2:3 van de Algemene wet bestuursrecht.',
			'',
			'Afzender: ' . $message->from,
			'Onderwerp: ' . $message->subject,
		];
		if ($message->sentAt !== '') {
			$lines[] = 'Verzonden: ' . $message->sentAt;
		}

		if ($message->source === '') {
			$lines[] = '';
			$lines[] = 'Het originele bericht kon niet worden meegestuurd.';
		}

		return implode("\n", $lines);
	}//end coveringNote()

	/**
	 * What the log says about this bounce.
	 *
	 * @param string  $recipient The address it went to.
	 * @param string  $reason    The handler's reason.
	 * @param string  $actorId   Who bounced it.
	 * @param boolean $sent      Whether the mailer accepted it.
	 *
	 * @return string The recorded reason.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function recordedReason(string $recipient, string $reason, string $actorId, bool $sent): string {
		$who = 'the intake pipeline';
		if ($actorId !== '') {
			$who = $actorId;
		}

		if ($sent === false) {
			return 'A bounce to ' . $recipient . ' was asked for by ' . $who
				. ' and could not be sent. Reason given: ' . $reason;
		}

		return 'Forwarded to ' . $recipient . ' by ' . $who
			. ' under Awb 2:3, with the original intact. Reason given: ' . $reason;
	}//end recordedReason()
}//end class
