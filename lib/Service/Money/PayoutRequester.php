<?php

/**
 * Dossiq PayoutRequester.
 *
 * Hands a payout dossiq owes someone to the finance app (ADR-107): shillinq
 * answers PayableRaisedEvent with a payable it approves, pays or rejects, and
 * PayableCancelRequestedEvent withdraws one finance has not acted on yet.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCA\Dossiq\AppInfo\Application;
use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use RuntimeException;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Raises and withdraws payouts in the finance app.
 *
 * Both commands are answered in place. No answer at all (shillinq absent,
 * or installed without the payout command) returns null: the payout then
 * stays with dossiq, as before the handoff. A refusal is an error, because
 * finance looked at the payout and said no.
 *
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */
class PayoutRequester {
	/**
	 * The fields of a payout settled before finance kept it.
	 *
	 * @var array<int, string>
	 */
	private const HISTORY = ['historicalState', 'paidOn', 'bankReference'];


	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Delivers the command to the finance app.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Raise a payout in the finance app.
	 *
	 * @param array<string, mixed> $payout The payout: amountCents, payeeName, iban, reference, and
	 *        optionally description, category, correlationId, dueOn, callbackUrl, linkedObjects, and for a
	 *        payout settled before finance kept it historicalState, paidOn and bankReference.
	 *
	 * @return array{payableId: string, state: string}|null The payable, or null when no finance app answers.
	 *
	 * @throws RuntimeException When the finance app refuses the payout.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function request(array $payout): ?array {
		if (class_exists(PayableRaisedEvent::class) === false) {
			return null;
		}

		$arguments = [
			'sourceApp' => Application::APP_ID,
			'amount' => ((int)$payout['amountCents']) / 100,
			'payeeName' => (string)$payout['payeeName'],
			'payeeIban' => (string)$payout['iban'],
			'reference' => (string)$payout['reference'],
			'description' => (string)($payout['description'] ?? ''),
			'callbackUrl' => (string)($payout['callbackUrl'] ?? ''),
			'category' => (string)($payout['category'] ?? ''),
			'correlationId' => (string)($payout['correlationId'] ?? ''),
			'dueOn' => (string)($payout['dueOn'] ?? ''),
		];
		// An older shillinq lacks the newer parameters, and an unknown named
		// argument is a fatal error, so each one goes only where it is declared.
		$accepted = $this->acceptedParameters();
		if (in_array('linkedObjects', $accepted, true) === true) {
			$arguments['linkedObjects'] = array_values((array)($payout['linkedObjects'] ?? []));
		}

		foreach (self::HISTORY as $key) {
			if ((string)($payout[$key] ?? '') !== '' && in_array($key, $accepted, true) === true) {
				$arguments[$key] = (string)$payout[$key];
			}
		}

		$event = new PayableRaisedEvent(...$arguments);
		$this->dispatcher->dispatchTyped($event);

		return self::answer(handled: $event->isHandled(), result: $event->getResult(), error: $event->getError());
	}//end request()

	/**
	 * The parameter names the installed finance app's payout command declares.
	 *
	 * @return array<int, string> The names.
	 */
	private function acceptedParameters(): array {
		return array_map(
			static fn (ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionMethod(PayableRaisedEvent::class, '__construct'))->getParameters()
		);
	}//end acceptedParameters()

	/**
	 * Withdraw a payout the finance app has not acted on.
	 *
	 * @param string $reference The reference the payout was raised under.
	 * @param string $reason    Why it is withdrawn.
	 *
	 * @return array{payableId: string, state: string}|null The payable, or null when no finance app answers.
	 *
	 * @throws RuntimeException When the finance app refuses, for example because it already approved the payout.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function cancel(string $reference, string $reason): ?array {
		if (class_exists(PayableCancelRequestedEvent::class) === false) {
			return null;
		}

		$event = new PayableCancelRequestedEvent(
			sourceApp: Application::APP_ID,
			reference: $reference,
			reason: $reason,
		);
		$this->dispatcher->dispatchTyped($event);

		return self::answer(handled: $event->isHandled(), result: $event->getResult(), error: $event->getError());
	}//end cancel()

	/**
	 * Read a command's answer.
	 *
	 * @param bool                      $handled Whether a listener accepted it.
	 * @param array<string, mixed>|null $result  The answer.
	 * @param string|null               $error   The refusal.
	 *
	 * @return array{payableId: string, state: string}|null The payable, or null when nobody answered.
	 *
	 * @throws RuntimeException When the command was refused.
	 */
	private static function answer(bool $handled, ?array $result, ?string $error): ?array {
		if ($handled === true && $result !== null) {
			return [
				'payableId' => (string)($result['payableId'] ?? ''),
				'state' => (string)($result['state'] ?? ''),
			];
		}

		if ($error !== null && $error !== '') {
			throw new RuntimeException('The finance app refused the payout: ' . $error);
		}

		return null;
	}//end answer()
}//end class
