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
	 *        optionally description, category, correlationId, dueOn, callbackUrl and linkedObjects.
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
		// A shillinq older than financial-documents-on-any-object has no linkedObjects
		// parameter, and an unknown named argument is a fatal error.
		if ($this->eventTakesLinkedObjects() === true) {
			$arguments['linkedObjects'] = array_values((array)($payout['linkedObjects'] ?? []));
		}

		$event = new PayableRaisedEvent(...$arguments);
		$this->dispatcher->dispatchTyped($event);

		return self::answer(handled: $event->isHandled(), result: $event->getResult(), error: $event->getError());
	}//end request()

	/**
	 * Whether the installed finance app's payout command takes linked objects.
	 *
	 * @return bool True when PayableRaisedEvent declares `linkedObjects`.
	 */
	private function eventTakesLinkedObjects(): bool {
		foreach ((new ReflectionMethod(PayableRaisedEvent::class, '__construct'))->getParameters() as $parameter) {
			if ($parameter->getName() === 'linkedObjects') {
				return true;
			}
		}

		return false;
	}//end eventTakesLinkedObjects()

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
