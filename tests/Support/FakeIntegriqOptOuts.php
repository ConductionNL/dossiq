<?php

/**
 * An opt-out list that answers integriq's decision event the way integriq does.
 *
 * It holds opt-outs (instance wide, or for one case) and answers
 * `OutboundSendDecisionRequestedEvent` with integriq's decision shape:
 * `{send, overridden, code, reason, unsubscribe}`, keyed by the address as
 * given. The shape and the rules are read from integriq development b20053418:
 * `OutboundSendDecisionRequestedListener::handle()` and
 * `UnsubscribeTokenService::materialFor()`. An exempt category is sent and
 * carries no link; an opted-out address on any other category is refused with
 * `opted-out`. Every question it answers is kept in `$log`, which stands in for
 * integriq's decision log.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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

namespace OCA\Dossiq\Tests\Support;

use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;

/**
 * Answers the decision event from a list it holds.
 */
final class FakeIntegriqOptOuts {

	/**
	 * The exempt floor, as integriq's category list fixes it.
	 */
	public const EXEMPT = ['besluit', 'statutory', 'account', 'security'];

	/**
	 * Opt-outs: address => list of case refs, '' meaning every case.
	 *
	 * @var array<string, list<string>>
	 */
	private array $optOuts = [];

	/**
	 * One line per answered recipient.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $log = [];

	/**
	 * Listen on the dispatcher.
	 *
	 * @param InMemoryEventDispatcher $dispatcher The dispatcher.
	 *
	 * @return self
	 */
	public static function on(InMemoryEventDispatcher $dispatcher): self {
		$fake = new self();
		$dispatcher->addListener(
			OutboundSendDecisionRequestedEvent::class,
			static function (OutboundSendDecisionRequestedEvent $event) use ($fake): void {
				$fake->answer(event: $event);
			}
		);

		return $fake;
	}//end on()

	/**
	 * Stop one address, for one case or for all of them.
	 *
	 * @param string $address The address.
	 * @param string $caseRef The case, or '' for an instance-wide stop.
	 *
	 * @return void
	 */
	public function optOut(string $address, string $caseRef = ''): void {
		$this->optOuts[strtolower($address)][] = $caseRef;
	}//end optOut()

	/**
	 * Answer the event.
	 *
	 * @param OutboundSendDecisionRequestedEvent $event The question.
	 *
	 * @return void
	 */
	public function answer(OutboundSendDecisionRequestedEvent $event): void {
		$exempt = in_array($event->getCategory(), self::EXEMPT, true);
		foreach ($event->getRecipients() as $recipient) {
			$address = (string)($recipient['address'] ?? '');
			$caseRef = (string)($recipient['caseRef'] ?? '');
			$stopped = $this->isStopped(address: $address, caseRef: $caseRef);

			$send = ($exempt === true || $stopped === false);
			$code = 'allowed';
			if ($stopped === true && $exempt === true) {
				$code = 'exempt-override';
			} else if ($stopped === true) {
				$code = 'opted-out';
			}

			$unsubscribe = null;
			if ($send === true && $exempt === false) {
				$url = 'https://nc.example/index.php/apps/integriq/unsubscribe/tok-' . md5($address . '|' . $caseRef);
				$unsubscribe = [
					'url' => $url,
					'oneClickUrl' => $url,
					'smsText' => null,
					'headers' => [
						'List-Unsubscribe' => '<' . $url . '>',
						'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
					],
				];
			}

			$decision = [
				'send' => $send,
				'overridden' => ($stopped === true && $exempt === true),
				'code' => $code,
				'reason' => ($send === true ? '' : 'The recipient asked not to receive these messages.'),
				'unsubscribe' => $unsubscribe,
			];
			$event->setDecision($address, $decision);
			$this->log[] = [
				'sourceApp' => $event->getSourceApp(),
				'channel' => $event->getChannel(),
				'category' => $event->getCategory(),
				'address' => $address,
				'caseRef' => $caseRef,
			] + $decision;
		}//end foreach

		$event->setHandled(true);
	}//end answer()

	/**
	 * Whether an address is stopped for a case.
	 *
	 * @param string $address The address.
	 * @param string $caseRef The case.
	 *
	 * @return bool True when it is.
	 */
	private function isStopped(string $address, string $caseRef): bool {
		foreach (($this->optOuts[strtolower($address)] ?? []) as $stoppedCase) {
			if ($stoppedCase === '' || $stoppedCase === $caseRef) {
				return true;
			}
		}

		return false;
	}//end isStopped()
}//end class
