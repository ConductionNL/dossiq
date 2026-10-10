<?php

/**
 * Dossiq RequesterNoticeSender.
 *
 * The one sender for a notice to the requester of a case: the acknowledgement
 * of receipt, the request for more information, a stage notice, the extension
 * of the term, the decision and the transfer. It picks a channel for the
 * requester, calls that channel's real transport and answers what the
 * transport said: `sent` with the transport's own message id, or `not-sent`
 * with a reason code and a sentence.
 *
 * 🔴 WHY THIS EXISTS. Before it, a notice went to
 * BerichtenboxRoutingService::routeToBerichtenbox(), which picked a channel
 * name, hashed a message id out of the case reference and called nothing.
 * The acknowledgement duty read met, and the citizen's own portal timeline
 * said the acknowledgement was sent, when nothing was sent (Awb 4:3a). A
 * not-sent result here never carries a message id, so nothing downstream can
 * mistake it for a send.
 *
 * THE ORDER (REQ-WRN-002). The portal inbox first, when the case has a portal
 * subject and portaliq is installed: portaliq shows the message to the
 * signed-in requester and forwards it to the message box itself
 * (PortalMessageBoxRecipient), so dossiq never also sends it as digital post.
 * Digital post next, when the requester's BSN is known; integriq decides
 * whether the requester's box takes it and refuses when it does not. E-mail
 * last, through TermNoticeSender, which asks integriq's opt-out first and
 * needs no signed-in user. A channel that refuses hands over to the next one,
 * and the result names every channel tried with its refusal.
 *
 * THE RECORD (REQ-WRN-006). The same code that called the transport appends
 * the record to the case's `outboundCommunications`, from the transport's
 * answer, so the case says what went out and what did not.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
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

namespace OCA\Dossiq\Service\Notification;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoticeNotSentException;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\OptOutGate;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Termijn\TermNoticeSender;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Hands a requester notice to a real transport and answers what it said.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */
class RequesterNoticeSender {

	use SearchesObjects;

	public const STATUS_SENT = 'sent';
	public const STATUS_NOT_SENT = 'not-sent';

	public const CHANNEL_PORTAL = 'portal-inbox';
	public const CHANNEL_DIGITAL_POST = 'digital-post';
	public const CHANNEL_EMAIL = 'email';

	/**
	 * The app that shows the portal inbox. Not renamed in the fleet.
	 */
	public const PORTAL_APP = 'portaliq';

	/**
	 * The moment each term template is sent at (REQ-WRN-006).
	 */
	public const MOMENTS = [
		'ontvangstbevestiging' => 'acknowledgement',
		'ingebrekestelling-receipt' => 'acknowledgement',
		'hersteltermijn-request' => 'information-request',
		'hersteltermijn-reminder' => 'information-request',
		'extension' => 'extension',
		'niet-ontvankelijk' => 'decision',
		'dwangsom-payment' => 'decision',
		'doorzending' => 'transfer',
	];

	/**
	 * Constructor.
	 *
	 * Every collaborator but the e-mail transport is optional: a channel whose
	 * transport is absent is not offered, which is the same answer an instance
	 * without that transport gives.
	 *
	 * @param TermNoticeSender         $email       The e-mail transport (opt-out first, once per notice).
	 * @param BerichtenboxService|null $digitalPost Digital post over integriq.
	 * @param SettingsService|null     $settings    The register, the schemas and the object service.
	 * @param IAppManager|null         $appManager  Whether portaliq is installed.
	 * @param CaseFieldWriter|null     $writer      Partial writes to the stored case.
	 * @param CaseContactDirectory     $contacts    The addresses registered on a case.
	 * @param LoggerInterface          $logger      Logger.
	 */
	public function __construct(
		private readonly TermNoticeSender $email,
		private readonly ?BerichtenboxService $digitalPost = null,
		private readonly ?SettingsService $settings = null,
		private readonly ?IAppManager $appManager = null,
		private readonly ?CaseFieldWriter $writer = null,
		private readonly CaseContactDirectory $contacts = new CaseContactDirectory(),
		private readonly LoggerInterface $logger = new NullLogger(),
	) {
	}//end __construct()

	/**
	 * The moment a template is sent at.
	 *
	 * @param string $template The template.
	 *
	 * @return string The moment; `stage` for a template not named in MOMENTS.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
	 */
	public static function momentFor(string $template): string {
		return (self::MOMENTS[$template] ?? 'stage');
	}//end momentFor()

	/**
	 * Send one notice to the requester of a case, and record it on the case.
	 *
	 * @param array<string, mixed> $case     The case row; `id` is enough for a caller that knows
	 *                                       only the address (`options.recipient`).
	 * @param string               $template The template the notice was rendered from.
	 * @param array<string, mixed> $rendered `subject` and `body`.
	 * @param string               $moment   The moment (REQ-WRN-006).
	 * @param array<string, mixed> $options  `recipient` (an address the caller holds), `instanceId`,
	 *                                       `dedupeKey`, `recordExtras` (more keys for the record).
	 *
	 * @return array<string, mixed> The delivery result, with the `record` it appended.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-the-channel-follows-the-requester-in-a-declared-order-req-wrn-002
	 */
	public function send(array $case, string $template, array $rendered, string $moment, array $options = []): array {
		$attemptedAt = (new DateTimeImmutable())->format('c');
		$notice = [
			'template' => $template,
			'subject' => (string)($rendered['subject'] ?? ''),
			'body' => (string)($rendered['body'] ?? ''),
			'instanceId' => (string)($options['instanceId'] ?? ''),
			'dedupeKey' => (string)($options['dedupeKey'] ?? ''),
			'recipient' => (string)($options['recipient'] ?? ''),
			'moment' => $moment,
		];

		$tried = [];
		$sent = null;
		foreach ([self::CHANNEL_PORTAL, self::CHANNEL_DIGITAL_POST, self::CHANNEL_EMAIL] as $channel) {
			$answer = $this->through(channel: $channel, case: $case, notice: $notice);
			if ($answer === null) {
				continue;
			}

			if ($answer['sent'] === true) {
				$sent = $answer + ['channel' => $channel];
				break;
			}

			$tried[] = ['channel' => $channel, 'reasonCode' => $answer['reasonCode'], 'reason' => $answer['reason']];
		}

		$result = $this->result(sent: $sent, tried: $tried, moment: $moment, template: $template, attemptedAt: $attemptedAt);
		$result['record'] = $this->record(case: $case, result: $result, extras: (array)($options['recordExtras'] ?? []));

		$this->logger->info(
			'Dossiq requester notice: {template} is {status}',
			[
				'template' => $template,
				'status' => $result['status'],
				'channel' => $result['channel'],
				'reasonCode' => (string)($result['reasonCode'] ?? ''),
			]
		);

		return $result;
	}//end send()

	/**
	 * Ask one channel, or null when the channel is not offered for this requester.
	 *
	 * @param string               $channel The channel.
	 * @param array<string, mixed> $case    The case row.
	 * @param array<string, mixed> $notice  The notice.
	 *
	 * @return array<string, mixed>|null `{sent: true, messageId, sentAt}` or `{sent: false, reasonCode, reason}`.
	 */
	private function through(string $channel, array $case, array $notice): ?array {
		return match ($channel) {
			self::CHANNEL_PORTAL => $this->portalInbox(case: $case, notice: $notice),
			self::CHANNEL_DIGITAL_POST => $this->digitalPostTo(case: $case, notice: $notice),
			default => $this->emailTo(case: $case, notice: $notice),
		};
	}//end through()

	/**
	 * The portal inbox: a `portaalBericht` from the organisation to the case's portal subject.
	 *
	 * @param array<string, mixed> $case   The case row.
	 * @param array<string, mixed> $notice The notice.
	 *
	 * @return array<string, mixed>|null Null when the case has no portal subject or portaliq is absent.
	 */
	private function portalInbox(array $case, array $notice): ?array {
		$subject = trim((string)($case['portalSubject'] ?? ''));
		if ($subject === '' || $this->appManager === null || $this->appManager->isInstalled(self::PORTAL_APP) === false) {
			return null;
		}

		$objectService = $this->settings?->getObjectService();
		$register = (string)$this->settings?->getConfigValue('register');
		$schema = (string)$this->settings?->getConfigValue('portaal_bericht_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return $this->refused(code: 'portal-inbox-unavailable', reason: 'The portal inbox is not configured, so the notice could not be put there.');
		}

		$sentAt = (new DateTimeImmutable())->format('c');
		$message = [
			'caseId' => (string)($case['id'] ?? ''),
			'caseReference' => (string)($case['identifier'] ?? ''),
			'recipientRef' => $subject,
			'senderRef' => 'dossiq',
			'senderType' => 'medewerker',
			'senderName' => 'Gemeente',
			'subject' => $notice['subject'],
			'content' => $notice['body'],
			'direction' => 'handler_to_citizen',
			'sentAt' => $sentAt,
		];

		try {
			// As the system: a background job has no user, and the requester's
			// inbox is not a register the acting handler holds a grant on.
			$saved = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->saveObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					object: $message
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq requester notice: the portal inbox refused it', ['exception' => $e]);
			return $this->refused(code: 'portal-inbox-failed', reason: 'The portal inbox did not accept the notice.');
		}

		$row = [];
		if (is_array($saved) === true) {
			$row = $saved;
		}

		$messageId = $this->idOf(row: $row);
		if ($messageId === '') {
			return $this->refused(code: 'portal-inbox-failed', reason: 'The portal inbox did not accept the notice.');
		}

		return ['sent' => true, 'messageId' => $messageId, 'sentAt' => $sentAt];
	}//end portalInbox()

	/**
	 * Digital post over integriq, when the requester's BSN is known.
	 *
	 * @param array<string, mixed> $case   The case row.
	 * @param array<string, mixed> $notice The notice.
	 *
	 * @return array<string, mixed>|null Null when no BSN is known or digital post is not wired.
	 */
	private function digitalPostTo(array $case, array $notice): ?array {
		$bsn = trim((string)($case['initiatorSourceId'] ?? ''));
		if ($this->digitalPost === null
			|| ($case['initiatorType'] ?? '') !== 'person'
			|| preg_match('/^\d{9}$/', $bsn) !== 1
		) {
			return null;
		}

		try {
			$answer = $this->digitalPost->sendMessage(
				caseId: (string)($case['id'] ?? ''),
				bsn: $bsn,
				subject: $notice['subject'],
				body: $notice['body'],
				typeCode: $notice['template'],
				attachmentFileId: null,
				category: $this->postCategoryFor(template: $notice['template'], moment: $notice['moment']),
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq requester notice: digital post threw', ['exception' => $e]);
			return $this->refused(code: 'digital-post-failed', reason: 'Digital post did not accept the notice.');
		}

		if (($answer['refused'] ?? false) === true) {
			return $this->refused(
				code: (string)($answer['code'] ?? 'refused'),
				reason: (string)($answer['error'] ?? 'Integriq refused the notice.')
			);
		}

		$messageId = trim((string)($answer['externalMessageId'] ?? ''));
		if (isset($answer['error']) === true || $messageId === '') {
			// No tracked message, no send: an id we cannot show integriq gave
			// us is exactly the fabricated id this class replaces.
			return $this->refused(
				code: 'digital-post-failed',
				reason: (string)($answer['error'] ?? 'Digital post gave no tracked message id, so the notice counts as not sent.')
			);
		}

		return ['sent' => true, 'messageId' => $messageId, 'sentAt' => (string)($answer['sentAt'] ?? (new DateTimeImmutable())->format('c'))];
	}//end digitalPostTo()

	/**
	 * E-mail through TermNoticeSender, to the address the caller holds or the case carries.
	 *
	 * @param array<string, mixed> $case   The case row.
	 * @param array<string, mixed> $notice The notice.
	 *
	 * @return array<string, mixed>|null Null when no address is known.
	 */
	private function emailTo(array $case, array $notice): ?array {
		$address = $this->addressFor(case: $case, given: $notice['recipient']);
		if ($address === '') {
			return null;
		}

		try {
			$dispatch = $this->email->send(
				template: $notice['template'],
				instanceId: $notice['instanceId'],
				recipient: $address,
				caseRef: (string)($case['id'] ?? ''),
				subject: $notice['subject'],
				body: $notice['body'],
				dedupeKey: $notice['dedupeKey'],
			);
		} catch (NoticeNotSentException $e) {
			return $this->refused(code: $e->getReasonCode(), reason: $e->getMessage());
		}

		return [
			'sent' => true,
			'messageId' => (string)($dispatch['messageId'] ?? ''),
			'sentAt' => (string)($dispatch['sentOn'] ?? (new DateTimeImmutable())->format('c')),
			'duplicate' => (($dispatch['duplicate'] ?? false) === true),
		];
	}//end emailTo()

	/**
	 * The e-mail address: the caller's, the Woo requester's, a contact's, the mail intake sender's.
	 *
	 * @param array<string, mixed> $case  The case row.
	 * @param string               $given The address the caller holds, or ''.
	 *
	 * @return string The address, or ''.
	 */
	private function addressFor(array $case, string $given): string {
		$candidates = [
			$given,
			(string)($case['verzoekerEmail'] ?? ''),
			(string)(((array)($case['wooRequest'] ?? []))['verzoekerEmail'] ?? ''),
		];
		$candidates = array_merge($candidates, $this->contacts->collectAddresses(caseData: $case));
		$candidates[] = (string)($case['initiatorSourceId'] ?? '');

		foreach ($candidates as $candidate) {
			$candidate = strtolower(trim((string)$candidate));
			if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
				return $candidate;
			}
		}

		return '';
	}//end addressFor()

	/**
	 * The category digital post travels under.
	 *
	 * @param string $template The template.
	 * @param string $moment   The moment.
	 *
	 * @return string `statutory`, `besluit` or `case-update`.
	 */
	private function postCategoryFor(string $template, string $moment): string {
		if (TermNoticeSender::categoryFor(template: $template) === OptOutGate::CATEGORY_STATUTORY) {
			return 'statutory';
		}

		if ($moment === 'decision') {
			return 'besluit';
		}

		return 'case-update';
	}//end postCategoryFor()

	/**
	 * A channel's refusal.
	 *
	 * @param string $code   The reason code.
	 * @param string $reason The sentence.
	 *
	 * @return array{sent: false, reasonCode: string, reason: string}
	 */
	private function refused(string $code, string $reason): array {
		return ['sent' => false, 'reasonCode' => $code, 'reason' => $reason];
	}//end refused()

	/**
	 * The delivery result.
	 *
	 * @param array<string, mixed>|null                                        $sent        The channel that took it, or null.
	 * @param list<array{channel: string, reasonCode: string, reason: string}> $tried       Every channel that refused.
	 * @param string                                                           $moment      The moment.
	 * @param string                                                           $template    The template.
	 * @param string                                                           $attemptedAt When.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function result(?array $sent, array $tried, string $moment, string $template, string $attemptedAt): array {
		$result = [
			'moment' => $moment,
			'template' => $template,
			'channelsTried' => $tried,
			'attemptedAt' => $attemptedAt,
		];

		if ($sent !== null) {
			return $result + [
				'status' => self::STATUS_SENT,
				'channel' => (string)$sent['channel'],
				'messageId' => (string)$sent['messageId'],
				'sentAt' => (string)$sent['sentAt'],
				'duplicate' => (($sent['duplicate'] ?? false) === true),
			];
		}

		if ($tried === []) {
			return $result + [
				'status' => self::STATUS_NOT_SENT,
				'channel' => '',
				'reasonCode' => 'no-channel',
				'reason' => 'This case has no address for the requester, so the notice was not sent.',
			];
		}

		$last = $tried[array_key_last($tried)];

		return $result + [
			'status' => self::STATUS_NOT_SENT,
			'channel' => '',
			'reasonCode' => $last['reasonCode'],
			'reason' => $last['reason'],
		];
	}//end result()

	/**
	 * Append the record of this notice to the case, from the transport's answer.
	 *
	 * A record that cannot be written is logged and the result stands: the
	 * transport already acted, and undoing what the requester received is not
	 * possible.
	 *
	 * @param array<string, mixed> $case   The case row.
	 * @param array<string, mixed> $result The delivery result.
	 * @param array<string, mixed> $extras More keys from the caller.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
	 */
	private function record(array $case, array $result, array $extras): array {
		$record = $extras + [
			'moment' => $result['moment'],
			'template' => $result['template'],
			'channel' => $result['channel'],
			'status' => $result['status'],
			'channelsTried' => $result['channelsTried'],
			'attemptedAt' => $result['attemptedAt'],
		];
		foreach (['messageId', 'sentAt', 'reasonCode', 'reason'] as $key) {
			if (isset($result[$key]) === true) {
				$record[$key] = $result[$key];
			}
		}

		$caseId = (string)($case['id'] ?? '');
		$objectService = $this->settings?->getObjectService();
		$register = (string)$this->settings?->getConfigValue('register');
		$schema = (string)$this->settings?->getConfigValue('case_schema');
		if ($caseId === '' || $objectService === null || $register === '' || $schema === '' || $this->writer === null) {
			return $record;
		}

		try {
			$stored = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId)
			);
			$list = [];
			if (is_array($stored) === true) {
				$list = ($stored['outboundCommunications'] ?? []);
			}

			if (is_array($list) === false) {
				$list = [];
			}

			$list[] = $record;
			$this->writer->write(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				case: ['id' => $caseId],
				changes: ['outboundCommunications' => array_values($list)],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq requester notice: case {case} could not record the notice',
				['case' => $caseId, 'reason' => $e->getMessage()]
			);
		}//end try

		return $record;
	}//end record()

	/**
	 * The id of a saved row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()
}//end class
