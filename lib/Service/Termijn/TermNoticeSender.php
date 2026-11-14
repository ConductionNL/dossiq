<?php

/**
 * Dossiq TermNoticeSender.
 *
 * The transport behind every term notice: the ontvangstbevestiging, the
 * request for more information, the reminder while a term waits on the
 * applicant, the extension, the doorzending and the rest of
 * {@see \OCA\Dossiq\Service\TermijnNotificationService::TEMPLATES}.
 *
 * 🔴 WHAT WAS HERE BEFORE SENT NOTHING. TermijnNotificationService handed each
 * notice to BerichtenboxRoutingService::routeToBerichtenbox(), which resolved a
 * channel, logged "beschikking gerouteerd" and returned a message id derived
 * from a hash (dossiq opt-out-before-send design, section 7). Every caller
 * stored that record as proof of dispatch, so a case read "reminder sent" for
 * a reminder nobody received.
 *
 * THE CHANNEL IS CASE MAIL. Every caller addresses a notice to an e-mail
 * address (the open request's recipient, the case's contact address), never a
 * BSN, so digital post cannot carry them. The burger-notifications spec names
 * e-mail as a channel, and the opt-out contract gives case mail its rules:
 * ask integriq through OptOutGate first, then carry integriq's unsubscribe
 * line and the RFC 8058 headers through OpenRegister's UnsubscribeHeaders.
 *
 * THE CATEGORY. `service` for every notice (integriq#2543: the `service`
 * purpose covers case updates and reminders), except the ontvangstbevestiging,
 * which is `statutory` because the dossiq opt-out design names it so, and the
 * beschikking, whose announcement is a legal duty (Awb 3:41, decision 158). A
 * statutory notice reaches a person who opted out and carries no link.
 *
 * ONCE PER NOTICE. A key per notice is claimed in {@see TermNoticeLedger}
 * before integriq is asked. A second trigger for the same notice finds the
 * claim and sends nothing. A refusal by an opt-out is kept, so the daily sweep
 * does not ask again for that notice; a failure that may pass (integriq absent,
 * no sender address, the mail server down) gives the claim back.
 *
 * THE TRANSPORT IS CONFIGURATION (decisions 165 and 182). A notice leaves
 * through {@see OutboundCaseMail} as kind `notice`, so
 * {@see MailTransportPolicy} decides whether it goes out through Nextcloud's
 * IMailer (the default: it carries the RFC 8058 headers) or through the
 * Nextcloud Mail account the case type sends from. This class builds no
 * message and holds no sender address of its own.
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
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\MailTransportPolicy;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\OptOutGate;
use Psr\Log\LoggerInterface;

/**
 * Mails one term notice, once, if integriq allows it.
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */
class TermNoticeSender {

	/**
	 * The category of every notice that is not named below.
	 */
	public const CATEGORY_DEFAULT = 'service';

	/**
	 * Notices the law requires. Exempt from opt-outs, sent without a link: the
	 * ontvangstbevestiging (Awb 4:3a) and the beschikking (Awb 3:41).
	 */
	public const STATUTORY = ['ontvangstbevestiging', 'beschikking'];

	/**
	 * After this many seconds a claim that never settled is taken as abandoned.
	 */
	private const STALE_CLAIM_SECONDS = 900;

	/**
	 * Codes that are a person's wish, kept on the claim.
	 */
	private const WISHES = ['opted-out', 'no-consent'];

	/**
	 * A refusal of the outbound mail, as the notice's reason code.
	 *
	 * The two that predate the transport policy keep their codes, so a
	 * caller's record and the daily sweep read them as before.
	 */
	private const OUTBOUND_CODES = [
		'mail-sender-not-configured' => 'no-sender-address',
		'mail-transport-unavailable' => 'mail-failed',
		'mail-account-unavailable' => 'mail-failed',
	];

	/**
	 * Constructor.
	 *
	 * @param OutboundCaseMail $outbound Sends the notice through the transport its kind is configured for.
	 * @param CaseMailOptOut   $optOut   Asks integriq whether the notice may go.
	 * @param TermNoticeLedger $ledger   Keeps each notice to one send.
	 * @param LoggerInterface  $logger   Logger.
	 */
	public function __construct(
		private readonly OutboundCaseMail $outbound,
		private readonly CaseMailOptOut $optOut,
		private readonly TermNoticeLedger $ledger,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The category a notice of this template travels under.
	 *
	 * @param string $template The template.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 */
	public static function categoryFor(string $template): string {
		if (in_array($template, self::STATUTORY, true) === true) {
			return OptOutGate::CATEGORY_STATUTORY;
		}

		return self::CATEGORY_DEFAULT;
	}//end categoryFor()

	/**
	 * Mail one notice.
	 *
	 * @param string $template   The template the notice was rendered from.
	 * @param string $instanceId The term instance, or '' when there is none.
	 * @param string $recipient  The address.
	 * @param string $caseRef    The case, so a case-scoped opt-out matches.
	 * @param string $subject    The subject.
	 * @param string $body       The body, as plain text.
	 * @param string $dedupeKey  The caller's name for this notice, or '' to key on its content.
	 * @param array<string, mixed> $case The case row, when the caller has it: a notice
	 *        configured to the Mail account then leaves from the account its case type names.
	 *
	 * @return array<string, mixed> The delivery record.
	 *
	 * @throws NoticeNotSentException When nothing was sent.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function send(
		string $template,
		string $instanceId,
		string $recipient,
		string $caseRef,
		string $subject,
		string $body,
		string $dedupeKey = '',
		array $case = [],
	): array {
		return $this->sendNotice(
			notice: new TermNotice(
				template: $template,
				instanceId: $instanceId,
				recipient: $recipient,
				caseRef: $caseRef,
				subject: $subject,
				body: $body,
				dedupeKey: $dedupeKey,
				case: $case,
			)
		);
	}//end send()

	/**
	 * Mail one notice: refuse one without an address, claim it once, ask
	 * integriq whether it may go, send it and settle the claim.
	 *
	 * @param TermNotice $notice The notice.
	 *
	 * @return array<string, mixed> The delivery record.
	 *
	 * @throws NoticeNotSentException When nothing was sent.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 */
	public function sendNotice(TermNotice $notice): array {
		$this->requireAddress(notice: $notice);

		$run = self::runFor(notice: $notice);

		$earlier = $this->claimOrEarlier(run: $run);
		if ($earlier !== null) {
			return $earlier;
		}

		$decision = $this->optOut->decide(recipient: $notice->address(), category: $run->category, caseId: $notice->caseRef);
		if ($decision['send'] === false) {
			$this->refuseUnwanted(run: $run, decision: $decision);
		}

		$this->deliver(run: $run, unsubscribe: $decision['unsubscribe']);

		return $this->settleSent(run: $run, code: $decision['code']);
	}//end sendNotice()

	/**
	 * Start the send of a notice: its claim key and opt-out category.
	 *
	 * @param TermNotice $notice The notice.
	 *
	 * @return TermNoticeRun The send.
	 */
	private static function runFor(TermNotice $notice): TermNoticeRun {
		return new TermNoticeRun(
			notice: $notice,
			key: self::keyFor(notice: $notice),
			category: self::categoryFor(template: $notice->template)
		);
	}//end runFor()

	/**
	 * Refuse a notice whose address is no e-mail address.
	 *
	 * @param TermNotice $notice The notice.
	 *
	 * @return void
	 *
	 * @throws NoticeNotSentException When the address is no e-mail address.
	 */
	private function requireAddress(TermNotice $notice): void {
		if (filter_var($notice->address(), FILTER_VALIDATE_EMAIL) !== false) {
			return;
		}

		$this->refused(context: self::logContext(notice: $notice, category: ''), code: 'no-email-address');
		throw new NoticeNotSentException(
			reasonCode: 'no-email-address',
			reason: 'This notice has no e-mail address to go to, so it was not sent.'
		);
	}//end requireAddress()

	/**
	 * Refuse a notice integriq says may not go, keeping the refusal when it
	 * is a person's wish.
	 *
	 * @param TermNoticeRun        $run      The send.
	 * @param array<string, mixed> $decision integriq's decision: `code`, `reason`.
	 *
	 * @return never
	 *
	 * @throws NoticeNotSentException Always.
	 */
	private function refuseUnwanted(TermNoticeRun $run, array $decision): never {
		$this->settleRefusal(key: $run->key, code: $decision['code']);
		$this->refused(context: self::logContext(notice: $run->notice, category: $run->category), code: $decision['code']);
		throw new NoticeNotSentException(reasonCode: $decision['code'], reason: $decision['reason']);
	}//end refuseUnwanted()

	/**
	 * Settle the claim of a notice that was sent, log it and answer its record.
	 *
	 * @param TermNoticeRun $run  The send.
	 * @param string        $code integriq's code.
	 *
	 * @return array<string, mixed> The delivery record.
	 */
	private function settleSent(TermNoticeRun $run, string $code): array {
		$this->ledger->settle(key: $run->key, outcome: TermNoticeLedger::SENT, now: time());

		$this->logger->info(
			'Dossiq term notice sent',
			self::logContext(notice: $run->notice, category: $run->category) + ['code' => $code]
		);

		return $this->record(run: $run, code: $code);
	}//end settleSent()

	/**
	 * Claim the key, or answer what an earlier claim on it decided.
	 *
	 * @param TermNoticeRun $run The send.
	 *
	 * @return array<string, mixed>|null Null when this caller holds the claim now.
	 *
	 * @throws NoticeNotSentException When the earlier claim refused or is still running.
	 */
	private function claimOrEarlier(TermNoticeRun $run): ?array {
		if ($this->claim(run: $run) === true) {
			return null;
		}

		$row = $this->ledger->find(key: $run->key);
		if ($row === null) {
			// Released between the failed insert and the read: try once more.
			if ($this->claim(run: $run) === true) {
				return null;
			}

			throw self::inFlight();
		}

		return $this->earlier(run: $run, row: $row);
	}//end claimOrEarlier()

	/**
	 * What an earlier claim on the key decided: sent, refused, or still running.
	 *
	 * @param TermNoticeRun        $run The send.
	 * @param array<string, mixed> $row The ledger row: `outcome`, `at`.
	 *
	 * @return array<string, mixed>|null The earlier record, or null when this caller took a stale claim over.
	 *
	 * @throws NoticeNotSentException When the earlier claim refused or is still running.
	 */
	private function earlier(TermNoticeRun $run, array $row): ?array {
		if ($row['outcome'] === TermNoticeLedger::SENT) {
			return $this->duplicateRecord(run: $run);
		}

		if (str_starts_with($row['outcome'], 'refused:') === true) {
			throw new NoticeNotSentException(
				reasonCode: substr($row['outcome'], strlen('refused:')),
				reason: 'This notice was refused before, so it was not sent.'
			);
		}

		if ($this->tookOverStaleClaim(run: $run, row: $row) === true) {
			return null;
		}

		throw self::inFlight();
	}//end earlier()

	/**
	 * Release a claim whose run died between claim and settle, and claim it.
	 * Its claim is not a send.
	 *
	 * @param TermNoticeRun        $run The send.
	 * @param array<string, mixed> $row The ledger row: `at`.
	 *
	 * @return bool Whether this caller holds the claim now.
	 */
	private function tookOverStaleClaim(TermNoticeRun $run, array $row): bool {
		if ((time() - $row['at']) <= self::STALE_CLAIM_SECONDS) {
			return false;
		}

		$this->ledger->release(key: $run->key);

		return $this->claim(run: $run);
	}//end tookOverStaleClaim()

	/**
	 * Claim the key of a send in the ledger. Each call tries the insert anew.
	 *
	 * @param TermNoticeRun $run The send.
	 *
	 * @return bool Whether the claim was taken.
	 *
	 * @phpstan-impure
	 */
	private function claim(TermNoticeRun $run): bool {
		return $this->ledger->claim(key: $run->key, template: $run->notice->template, instance: $run->notice->instanceId, now: time());
	}//end claim()

	/**
	 * The refusal for a notice another run is sending.
	 *
	 * @return NoticeNotSentException The refusal.
	 */
	private static function inFlight(): NoticeNotSentException {
		return new NoticeNotSentException(reasonCode: 'in-flight', reason: 'Another run is sending this notice.');
	}//end inFlight()

	/**
	 * Hand the notice to the outbound mail, as kind `notice`.
	 *
	 * @param TermNoticeRun            $run         The send; its key is released on failure.
	 * @param array<string,mixed>|null $unsubscribe integriq's link material, or null.
	 *
	 * @return void
	 *
	 * @throws NoticeNotSentException When there is no sender or the transport took nothing.
	 */
	private function deliver(TermNoticeRun $run, ?array $unsubscribe): void {
		$notice = $run->notice;
		try {
			$sender = $this->outbound->senderFor(caseData: $notice->case, kind: MailTransportPolicy::KIND_NOTICE);
			$this->outbound->send(
				sender: $sender,
				to: $notice->address(),
				subject: $notice->subject,
				body: nl2br(htmlspecialchars($notice->body, ENT_QUOTES)),
				unsubscribe: $unsubscribe,
				plain: $notice->body,
			);
		} catch (RefusedException $e) {
			$this->refuseTransport(run: $run, refusal: $e);
		}
	}//end deliver()

	/**
	 * Give the claim back for a notice the outbound mail refused, and say why.
	 *
	 * @param TermNoticeRun    $run     The send.
	 * @param RefusedException $refusal What the outbound mail refused.
	 *
	 * @return never
	 *
	 * @throws NoticeNotSentException Always.
	 */
	private function refuseTransport(TermNoticeRun $run, RefusedException $refusal): never {
		$this->ledger->release(key: $run->key);
		$code = (self::OUTBOUND_CODES[$refusal->getRule()] ?? $refusal->getRule());
		$this->logger->warning(
			'Dossiq term notice not sent: the outbound mail refused it',
			['app' => AppIdentity::APP_ID, 'code' => $code, 'rule' => $refusal->getRule()]
		);
		throw new NoticeNotSentException(reasonCode: $code, reason: $refusal->getSentence());
	}//end refuseTransport()

	/**
	 * Keep a refusal that is a person's wish; give back one that may pass.
	 *
	 * @param string $key  The notice key.
	 * @param string $code The refusal code.
	 *
	 * @return void
	 */
	private function settleRefusal(string $key, string $code): void {
		if (in_array($code, self::WISHES, true) === true) {
			$this->ledger->settle(key: $key, outcome: 'refused:' . $code, now: time());
			return;
		}

		$this->ledger->release(key: $key);
	}//end settleRefusal()

	/**
	 * What a notice's log lines name: the app, template, instance and category.
	 *
	 * @param TermNotice $notice   The notice.
	 * @param string     $category The category it was asked under, or ''.
	 *
	 * @return array<string, string> The context.
	 */
	private static function logContext(TermNotice $notice, string $category): array {
		return [
			'app' => AppIdentity::APP_ID,
			'template' => $notice->template,
			'instance' => $notice->instanceId,
			'category' => $category,
		];
	}//end logContext()

	/**
	 * Log a notice that was not sent.
	 *
	 * @param array<string, string> $context The notice's log context.
	 * @param string                $code    Why.
	 *
	 * @return void
	 */
	private function refused(array $context, string $code): void {
		$this->logger->info('Dossiq term notice not sent', $context + ['code' => $code]);
	}//end refused()

	/**
	 * The key one notice is claimed under.
	 *
	 * A caller that sends the same letter more than once on purpose (the
	 * reminders on one pause) names each one. Without a name the key is the
	 * content, so a retried job or a second trigger finds the same key while a
	 * letter that says something new gets its own.
	 *
	 * @param TermNotice $notice The notice.
	 *
	 * @return string 64 hex characters.
	 */
	private static function keyFor(TermNotice $notice): string {
		if (trim($notice->dedupeKey) !== '') {
			return hash('sha256', 'k|' . trim($notice->dedupeKey));
		}

		return hash(
			'sha256',
			implode('|', ['c', $notice->template, $notice->instanceId, $notice->address(), $notice->subject, $notice->body])
		);
	}//end keyFor()

	/**
	 * The delivery record callers attach to the payload, for a notice sent now.
	 *
	 * @param TermNoticeRun $run  The send.
	 * @param string        $code integriq's code.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function record(TermNoticeRun $run, string $code): array {
		return [
			'notificationChannel' => 'email',
			'sent' => true,
			'duplicate' => false,
			'category' => $run->category,
			'code' => $code,
			'sentOn' => (new DateTimeImmutable())->format('c'),
			'sentBy' => 'systeem',
			'messageId' => 'TN-' . substr($run->key, 0, 12),
		];
	}//end record()

	/**
	 * The delivery record for a notice an earlier run sent.
	 *
	 * @param TermNoticeRun $run The send.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function duplicateRecord(TermNoticeRun $run): array {
		return array_replace($this->record(run: $run, code: 'already-sent'), ['sent' => false, 'duplicate' => true]);
	}//end duplicateRecord()
}//end class
