<?php

/**
 * Dossiq Integriq Berichtenbox Adapter.
 *
 * The adapter this seam was built for. `SubstitutableAdapterRegistrar` has
 * said since it was written that the real contract belongs in integriq and
 * that dossiq must carry neither the protocol nor the credential; until
 * 2026-09-18 there was nothing on the other side to name, so
 * `lib/Service/BerichtenboxAdapter/` held an interface and a mock and every
 * letter dossiq composed was delivered to nothing.
 *
 * integriq#2062 closed its half: `DigitalPostProviderInterface` with send,
 * status, inbound polling and `activationRefusals`, bindings for log,
 * berichtenbox and postex, a `digitalPostMessage` schema tracking the
 * lifecycle, and `DigitalPostSendRequestedEvent` carrying either a tracked
 * message id or a structured refusal and never both.
 *
 * 🔴 IT IMPORTS NO INTEGRIQ CLASS AND TYPE HINTS NOTHING FROM IT. integriq is
 * an optional runtime dependency, and a type hint on a class the instance does
 * not have is a fatal when the container builds this adapter rather than a
 * feature that is quietly missing. The event class is resolved through
 * {@see FleetAppId}, never by writing another app's id or namespace into a
 * literal: integriq's id is moving, and a duck-typed lookup against a dead
 * name answers false without erroring, which would make this adapter a silent
 * no-op rather than a refusal.
 *
 * 🔴 IT NEVER SIMULATES. Every path that is not a tracked message id is a
 * refusal carrying the reason, because a mock standing in for a real letter is
 * how a citizen stops being notified without anyone noticing. That includes
 * the path integriq's own contract names: an event nobody handled, whose
 * result slot holds neither an id nor a refusal, is a refusal here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\BerichtenboxAdapter
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
 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\BerichtenboxAdapter;

use DateTime;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends digital post by asking integriq to send it.
 *
 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
 */
class IntegriqAdapter implements BerichtenboxAdapterInterface {

	/**
	 * The app-config key naming the integriq digital post source to send over.
	 */
	public const SOURCE_CONFIG_KEY = 'digital_post_source';

	/**
	 * The integriq event class, relative to integriq's own namespace.
	 *
	 * Resolved through FleetAppId rather than written as an FQN, for the
	 * reason the class docblock gives.
	 */
	private const SEND_EVENT = 'Event\\DigitalPostSendRequestedEvent';

	/**
	 * The status this adapter records for a letter it could not send.
	 */
	public const STATUS_REFUSED = 'refused';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches the cross-app command.
	 * @param IAppManager      $appManager Answers whether integriq is present.
	 * @param IAppConfig       $appConfig  Holds the digital post source id.
	 * @param IUserSession     $userSession Who asked for this letter.
	 * @param LoggerInterface  $logger     Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask integriq to send one letter to a citizen's digital post.
	 *
	 * @param string      $bsn        The recipient's BSN.
	 * @param string      $subject    The letter's subject.
	 * @param string      $body       The letter's plain text body.
	 * @param string      $typeCode   The bericht type code.
	 * @param string|null $attachment Attachment content, base64.
	 *
	 * @return array<string, mixed> Either a tracked send, or a refusal with its reason.
	 *
	 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
	 */
	public function sendMessage(
		string $bsn,
		string $subject,
		string $body,
		string $typeCode,
		?string $attachment = null,
	): array {
		if (FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: 'integriq') === false) {
			return $this->refusal(
				code: 'integriq-missing',
				reason: 'Integriq is not installed or not enabled, so there is nothing to send '
					. 'digital post over. Nothing was sent.',
			);
		}

		$eventClass = FleetAppId::resolveClass(canonical: 'integriq', relative: self::SEND_EVENT);
		if ($eventClass === null) {
			return $this->refusal(
				code: 'integriq-seam-missing',
				reason: 'Integriq is installed but carries no digital post seam, so this version '
					. 'cannot send digital post. Nothing was sent.',
			);
		}

		$sourceId = $this->sourceId();
		if ($sourceId === '') {
			// MEASURED from integriq `development` on 2026-09-20:
			// `DigitalPostService::sourceConfig()` opens with
			// `if ($sourceId === '') { return null; }`, so an empty source can
			// never resolve and the send is refused every time. integriq's own
			// refusal reads `No digital post source is configured under "".
			// Nothing was sent.`, which names no key an administrator can set.
			// Refusing here costs a dispatch nobody wanted and says what to do.
			return $this->refusal(
				code: 'digital-post-source-unset',
				reason: 'No integriq digital post source is set, so there is nothing to send over. '
					. 'Set it with occ config:app:set dossiq ' . self::SOURCE_CONFIG_KEY
					. ' --value <slug>. Nothing was sent.',
			);
		}

		try {
			$event = new $eventClass(
				'dossiq',
				$sourceId,
				$bsn,
				$subject,
				$body,
				$this->attachmentsOf(attachment: $attachment),
				$this->requestedBy(),
				bin2hex(random_bytes(8)),
			);

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the digital post send event could not be dispatched',
				['exception' => $e->getMessage()]
			);

			return $this->refusal(
				code: 'dispatch-failed',
				reason: 'The digital post request could not be handed to integriq. Nothing was sent.',
			);
		}

		return $this->readSlot(event: $event);
	}//end sendMessage()

	/**
	 * What became of a sent letter.
	 *
	 * NOTHING IS POLLED AND NOTHING IS GUESSED. integriq dispatches
	 * `DigitalPostDeliveredEvent` on every status change of a tracked message,
	 * `read` and `failed` included, and {@see \OCA\Dossiq\Listener\DigitalPostDeliveredListener}
	 * writes it onto the case. So the answer here is "ask the event, not me",
	 * said in a shape the caller can tell apart from an answer: `unknown` is
	 * true, and `read` is false rather than absent, because a caller reading
	 * `read` off a missing key would get null and treat it as unread.
	 *
	 * Returning `read: true` on an unanswered question is the failure this
	 * whole change is about, one layer down: it would mark a letter read that
	 * nobody opened.
	 *
	 * @param string $messageId The external message id.
	 *
	 * @return array<string, mixed> The status, with `unknown` set.
	 *
	 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
	 */
	public function getReadStatus(string $messageId): array {
		return [
			'read' => false,
			'readAt' => null,
			'unknown' => true,
			'reason' => 'Integriq reports digital post status by event, so there is nothing '
				. 'to poll here.',
		];
	}//end getReadStatus()

	/**
	 * Read integriq's answer out of the event's result slot.
	 *
	 * The contract says the slot carries either the tracked message id or a
	 * structured refusal, never both, and is never left empty on a handled
	 * event. All three of the other cases are refusals here, because the one
	 * thing this adapter must never do is report a send it cannot show a
	 * tracked message for.
	 *
	 * @param object $event The dispatched event.
	 *
	 * @return array<string, mixed> The send result.
	 *
	 * @spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md
	 */
	private function readSlot(object $event): array {
		if (method_exists($event, 'isHandled') === true && $event->isHandled() === false) {
			return $this->refusal(
				code: 'unhandled',
				reason: 'Integriq did not handle the digital post request, so nothing was sent. '
					. 'Its digital post seam is present but no binding answered.',
			);
		}

		$refusal = null;
		if (method_exists($event, 'getRefusal') === true) {
			$refusal = $event->getRefusal();
		}

		if (is_array($refusal) === true && $refusal !== []) {
			return $this->refusal(
				code: (string)($refusal['code'] ?? self::STATUS_REFUSED),
				reason: (string)($refusal['reason'] ?? 'Integriq refused the send and gave no reason.'),
			);
		}

		$messageId = null;
		if (method_exists($event, 'getMessageId') === true) {
			$messageId = $event->getMessageId();
		}

		if (is_string($messageId) === false || trim($messageId) === '') {
			// A handled event with neither an id nor a refusal. integriq's own
			// contract says this cannot happen; treating it as a send anyway
			// is how a letter that went nowhere reads as delivered.
			return $this->refusal(
				code: 'no-tracked-message',
				reason: 'Integriq answered without a tracked message, so this letter cannot be '
					. 'followed and is not recorded as sent.',
			);
		}

		return [
			'messageId' => trim($messageId),
			'status' => 'sent',
			'sentAt' => (new DateTime())->format('c'),
		];
	}//end readSlot()

	/**
	 * A refusal in the shape the caller reads.
	 *
	 * No `messageId` key at all, deliberately: `BerichtenboxService` writes
	 * `$result['messageId'] ?? null` onto the stored message, and an empty
	 * string there would be an external id of its own kind.
	 *
	 * @param string $code   A machine-readable code.
	 * @param string $reason The sentence a handler reads.
	 *
	 * @return array<string, mixed> The refusal.
	 */
	private function refusal(string $code, string $reason): array {
		$this->logger->warning('Dossiq: digital post refused', ['code' => $code, 'reason' => $reason]);

		return [
			'status' => self::STATUS_REFUSED,
			'refused' => true,
			'code' => $code,
			'error' => $reason,
		];
	}//end refusal()

	/**
	 * The integriq digital post source this instance sends over.
	 *
	 * WHICH sources exist is integriq's to say, and dossiq never guesses one.
	 * That an EMPTY one cannot work is not a guess: integriq's
	 * `DigitalPostService::sourceConfig()` returns null on the empty string
	 * before it looks anything up, so the caller refuses on '' rather than
	 * dispatching a letter that is certain to come back refused.
	 *
	 * @return string The configured source id, or ''.
	 */
	private function sourceId(): string {
		return trim($this->appConfig->getValueString('dossiq', self::SOURCE_CONFIG_KEY, ''));
	}//end sourceId()

	/**
	 * Who asked for this letter.
	 *
	 * @return string The acting user id, or 'system'.
	 */
	private function requestedBy(): string {
		$user = $this->userSession->getUser();

		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end requestedBy()

	/**
	 * The attachment references the event carries.
	 *
	 * @param string|null $attachment Base64 attachment content, or null.
	 *
	 * @return array<int, array<string, mixed>> The attachment references.
	 */
	private function attachmentsOf(?string $attachment): array {
		if ($attachment === null || $attachment === '') {
			return [];
		}

		return [['content' => $attachment, 'encoding' => 'base64']];
	}//end attachmentsOf()
}//end class
