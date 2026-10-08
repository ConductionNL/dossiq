<?php

/**
 * Dossiq OptOutGate.
 *
 * Asks integriq, before a case message goes to a citizen, whether that person
 * may be sent it. integriq owns the opt-out list (hydra opt-out-before-send);
 * dossiq only asks and obeys.
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
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One question to integriq per recipient: may I send this?
 *
 * The event is integriq's `OutboundSendDecisionRequestedEvent`, named by
 * string and resolved through FleetAppId behind class_exists(), so dossiq
 * stays installable without integriq and never imports its classes (ADR-041).
 *
 * Fail closed (hydra decision 1): when the class is missing, nobody answers,
 * or the listener throws, a non-exempt message is refused with
 * `authority-unavailable` and an exempt one is sent without a link.
 *
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */
class OptOutGate {

	/**
	 * The integriq event, relative to integriq's namespace.
	 */
	public const DECISION_EVENT = 'Event\\OutboundSendDecisionRequestedEvent';

	/**
	 * The rollback switch. Anything but `false` reads as on (ADR-102).
	 */
	public const CONFIG_KEY = 'outbound_optout_check';

	/**
	 * The code for "integriq could not answer".
	 */
	public const CODE_UNAVAILABLE = 'authority-unavailable';

	/**
	 * The code for "the check is switched off".
	 */
	public const CODE_CHECK_OFF = 'check-disabled';

	/**
	 * A status update or mail about one case. Respects opt-outs.
	 */
	public const CATEGORY_CASE_UPDATE = 'case-update';

	/**
	 * A decision. Always sent, never carries a link.
	 */
	public const CATEGORY_BESLUIT = 'besluit';

	/**
	 * A notice the law requires. Always sent, never carries a link.
	 */
	public const CATEGORY_STATUTORY = 'statutory';

	/**
	 * The exempt floor, as hydra's category list fixes it.
	 */
	private const EXEMPT = ['besluit', 'statutory', 'account', 'security'];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher    Carries the question to integriq.
	 * @param IAppConfig       $appConfig     Holds the rollback switch.
	 * @param LoggerInterface  $logger        Logs a refusal integriq could not answer.
	 * @param string           $eventRelative The event class, relative to integriq's namespace.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly string $eventRelative = self::DECISION_EVENT,
	) {
	}//end __construct()

	/**
	 * Whether a category is exempt from opt-outs.
	 *
	 * Exact match only: an unknown or misspelt category is never exempt.
	 *
	 * @param string $category The category.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-a-handler-can-send-a-besluit-that-is-always-delivered-req-coo-002
	 */
	public static function isExempt(string $category): bool {
		return in_array($category, self::EXEMPT, true);
	}//end isExempt()

	/**
	 * Ask whether one recipient may be sent one message about a case.
	 *
	 * @param string $recipient The address as it will be used.
	 * @param string $category  What kind of message this is.
	 * @param string $caseRef   The case it is about, so a case opt-out matches.
	 * @param string $channel   The channel, `email` for case mail.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
	 */
	public function ask(string $recipient, string $category, string $caseRef, string $channel = 'email'): array {
		if ($this->isSwitchedOn() === false) {
			return $this->decision(send: true, code: self::CODE_CHECK_OFF, reason: '');
		}

		$eventClass = FleetAppId::resolveClass(canonical: 'integriq', relative: $this->eventRelative);
		if ($eventClass === null) {
			return $this->unavailable(category: $category, caseRef: $caseRef, why: 'integriq or its decision event is not installed');
		}

		try {
			$event = new $eventClass(
				'dossiq',
				$channel,
				$category,
				[['address' => $recipient, 'caseRef' => $caseRef]],
				bin2hex(random_bytes(8)),
			);
			if (($event instanceof Event) === false) {
				return $this->unavailable(category: $category, caseRef: $caseRef, why: 'the decision event is not an event');
			}

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			return $this->unavailable(category: $category, caseRef: $caseRef, why: 'the question failed: ' . $e->getMessage());
		}

		return $this->read(event: $event, recipient: $recipient, category: $category, caseRef: $caseRef);
	}//end ask()

	/**
	 * Read integriq's answer for one recipient out of the event.
	 *
	 * @param Event  $event     The answered event.
	 * @param string $recipient The address as given.
	 * @param string $category  The category.
	 * @param string $caseRef   The case.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 */
	private function read(Event $event, string $recipient, string $category, string $caseRef): array {
		$answer = null;
		if (method_exists($event, 'isHandled') === true
			&& $event->isHandled() === true
			&& method_exists($event, 'getDecision') === true
		) {
			$answer = $event->getDecision($recipient);
		}

		if (is_array($answer) === false || array_key_exists('send', $answer) === false) {
			return $this->unavailable(category: $category, caseRef: $caseRef, why: 'integriq did not answer for this recipient');
		}

		$unsubscribe = null;
		if (is_array($answer['unsubscribe'] ?? null) === true && self::isExempt(category: $category) === false) {
			$unsubscribe = $answer['unsubscribe'];
		}

		return $this->decision(
			send: ($answer['send'] === true),
			code: (string)($answer['code'] ?? ''),
			reason: (string)($answer['reason'] ?? ''),
			unsubscribe: $unsubscribe,
		);
	}//end read()

	/**
	 * The fail-closed answer: exempt goes out without a link, the rest is refused.
	 *
	 * @param string $category The category.
	 * @param string $caseRef  The case, for the log.
	 * @param string $why      What went wrong, for the log.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 */
	private function unavailable(string $category, string $caseRef, string $why): array {
		$exempt  = self::isExempt(category: $category);
		$outcome = 'not sent';
		if ($exempt === true) {
			$outcome = 'sent, the category is exempt';
		}

		$this->logger->warning(
			'Dossiq: integriq could not say whether this person may be messaged; ' . $outcome,
			[
				'app' => Application::APP_ID,
				'caseRef' => $caseRef,
				'category' => $category,
				'why' => $why,
			]
		);

		return $this->decision(
			send: $exempt,
			code: self::CODE_UNAVAILABLE,
			reason: 'integriq is not available, so dossiq cannot check whether this person may be messaged.',
		);
	}//end unavailable()

	/**
	 * Whether the check is on.
	 *
	 * @return bool False only when the switch reads `false`.
	 */
	private function isSwitchedOn(): bool {
		try {
			$value = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, 'true');
		} catch (Throwable $e) {
			return true;
		}

		return strtolower(trim($value)) !== 'false';
	}//end isSwitchedOn()

	/**
	 * One decision in the shape callers read.
	 *
	 * @param bool                     $send        Whether to send.
	 * @param string                   $code        The code.
	 * @param string                   $reason      Why.
	 * @param array<string,mixed>|null $unsubscribe The link material, or null.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 */
	private function decision(bool $send, string $code, string $reason, ?array $unsubscribe = null): array {
		return ['send' => $send, 'code' => $code, 'reason' => $reason, 'unsubscribe' => $unsubscribe];
	}//end decision()
}//end class
