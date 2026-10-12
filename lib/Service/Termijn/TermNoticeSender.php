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
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Exception\RefusedException;
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
		$address = strtolower(trim($recipient));
		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			$this->refused(template: $template, instanceId: $instanceId, code: 'no-email-address');
			throw new NoticeNotSentException(
				reasonCode: 'no-email-address',
				reason: 'This notice has no e-mail address to go to, so it was not sent.'
			);
		}

		$key = $this->keyFor(
			template: $template,
			instanceId: $instanceId,
			address: $address,
			subject: $subject,
			body: $body,
			dedupeKey: $dedupeKey,
		);
		$category = self::categoryFor(template: $template);

		$earlier = $this->claimOrEarlier(key: $key, template: $template, instanceId: $instanceId);
		if ($earlier !== null) {
			return $earlier;
		}

		$decision = $this->optOut->decide(recipient: $address, category: $category, caseId: $caseRef);
		if ($decision['send'] === false) {
			$this->settleRefusal(key: $key, code: $decision['code']);
			$this->refused(template: $template, instanceId: $instanceId, code: $decision['code'], category: $category);
			throw new NoticeNotSentException(reasonCode: $decision['code'], reason: $decision['reason']);
		}

		$this->deliver(
			key: $key,
			case: $case,
			address: $address,
			subject: $subject,
			body: $body,
			unsubscribe: $decision['unsubscribe'],
		);

		$this->ledger->settle(key: $key, outcome: TermNoticeLedger::SENT, now: time());

		$this->logger->info(
			'Dossiq term notice sent',
			[
				'app' => Application::APP_ID,
				'template' => $template,
				'instance' => $instanceId,
				'category' => $category,
				'code' => $decision['code'],
			]
		);

		return $this->record(key: $key, category: $category, code: $decision['code'], duplicate: false);
	}//end send()

	/**
	 * Claim the key, or answer what an earlier claim on it decided.
	 *
	 * @param string $key        The notice key.
	 * @param string $template   The template.
	 * @param string $instanceId The term instance.
	 *
	 * @return array<string, mixed>|null Null when this caller holds the claim now.
	 *
	 * @throws NoticeNotSentException When the earlier claim refused or is still running.
	 */
	private function claimOrEarlier(string $key, string $template, string $instanceId): ?array {
		if ($this->ledger->claim(key: $key, template: $template, instance: $instanceId, now: time()) === true) {
			return null;
		}

		$row = $this->ledger->find(key: $key);
		if ($row === null) {
			// Released between the failed insert and the read: try once more.
			if ($this->ledger->claim(key: $key, template: $template, instance: $instanceId, now: time()) === true) {
				return null;
			}

			throw new NoticeNotSentException(reasonCode: 'in-flight', reason: 'Another run is sending this notice.');
		}

		if ($row['outcome'] === TermNoticeLedger::SENT) {
			return $this->record(key: $key, category: self::categoryFor(template: $template), code: 'already-sent', duplicate: true);
		}

		if (str_starts_with($row['outcome'], 'refused:') === true) {
			throw new NoticeNotSentException(
				reasonCode: substr($row['outcome'], strlen('refused:')),
				reason: 'This notice was refused before, so it was not sent.'
			);
		}

		if ((time() - $row['at']) > self::STALE_CLAIM_SECONDS) {
			// A run that died between claim and settle. Its claim is not a send.
			$this->ledger->release(key: $key);
			if ($this->ledger->claim(key: $key, template: $template, instance: $instanceId, now: time()) === true) {
				return null;
			}
		}

		throw new NoticeNotSentException(reasonCode: 'in-flight', reason: 'Another run is sending this notice.');
	}//end claimOrEarlier()

	/**
	 * Hand the notice to the outbound mail, as kind `notice`.
	 *
	 * @param string                   $key         The notice key, released on failure.
	 * @param array<string, mixed>     $case        The case row, or [].
	 * @param string                   $address     The address.
	 * @param string                   $subject     The subject.
	 * @param string                   $body        The plain text body.
	 * @param array<string,mixed>|null $unsubscribe integriq's link material, or null.
	 *
	 * @return void
	 *
	 * @throws NoticeNotSentException When there is no sender or the transport took nothing.
	 */
	private function deliver(string $key, array $case, string $address, string $subject, string $body, ?array $unsubscribe): void {
		try {
			$sender = $this->outbound->senderFor(caseData: $case, kind: MailTransportPolicy::KIND_NOTICE);
			$this->outbound->send(
				sender: $sender,
				to: $address,
				subject: $subject,
				body: nl2br(htmlspecialchars($body, ENT_QUOTES)),
				unsubscribe: $unsubscribe,
				plain: $body,
			);
		} catch (RefusedException $e) {
			$this->ledger->release(key: $key);
			$code = (self::OUTBOUND_CODES[$e->getRule()] ?? $e->getRule());
			$this->logger->warning(
				'Dossiq term notice not sent: the outbound mail refused it',
				['app' => Application::APP_ID, 'code' => $code, 'rule' => $e->getRule()]
			);
			throw new NoticeNotSentException(reasonCode: $code, reason: $e->getSentence());
		}
	}//end deliver()

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
	 * Log a notice that was not sent.
	 *
	 * @param string $template   The template.
	 * @param string $instanceId The term instance.
	 * @param string $code       Why.
	 * @param string $category   The category it was asked under.
	 *
	 * @return void
	 */
	private function refused(string $template, string $instanceId, string $code, string $category = ''): void {
		$this->logger->info(
			'Dossiq term notice not sent',
			[
				'app' => Application::APP_ID,
				'template' => $template,
				'instance' => $instanceId,
				'category' => $category,
				'code' => $code,
			]
		);
	}//end refused()

	/**
	 * The key one notice is claimed under.
	 *
	 * A caller that sends the same letter more than once on purpose (the
	 * reminders on one pause) names each one. Without a name the key is the
	 * content, so a retried job or a second trigger finds the same key while a
	 * letter that says something new gets its own.
	 *
	 * @param string $template   The template.
	 * @param string $instanceId The term instance.
	 * @param string $address    The address.
	 * @param string $subject    The subject.
	 * @param string $body       The body.
	 * @param string $dedupeKey  The caller's name for this notice.
	 *
	 * @return string 64 hex characters.
	 */
	private function keyFor(
		string $template,
		string $instanceId,
		string $address,
		string $subject,
		string $body,
		string $dedupeKey,
	): string {
		if (trim($dedupeKey) !== '') {
			return hash('sha256', 'k|' . trim($dedupeKey));
		}

		return hash('sha256', implode('|', ['c', $template, $instanceId, $address, $subject, $body]));
	}//end keyFor()

	/**
	 * The delivery record callers attach to the payload.
	 *
	 * @param string $key       The notice key.
	 * @param string $category  The category.
	 * @param string $code      integriq's code, or `already-sent`.
	 * @param bool   $duplicate Whether an earlier run sent it.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function record(string $key, string $category, string $code, bool $duplicate): array {
		return [
			'notificationChannel' => 'email',
			'sent' => ($duplicate === false),
			'duplicate' => $duplicate,
			'category' => $category,
			'code' => $code,
			'sentOn' => (new DateTimeImmutable())->format('c'),
			'sentBy' => 'systeem',
			'messageId' => 'TN-' . substr($key, 0, 12),
		];
	}//end record()
}//end class
