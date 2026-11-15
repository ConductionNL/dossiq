<?php

/**
 * Payable Raised Event
 *
 * Another app owes someone money and asks shillinq to pay it out. It
 * dispatches this event with IEventDispatcher::dispatchTyped() and reads the
 * answer from the same object, as with the refund command.
 *
 * @category Event
 * @package  OCA\Shillinq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Event;

use OCP\EventDispatcher\Event;

/**
 * The payout command, answered in place.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The command carries the payout's fields as named arguments.
 *
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
 */
final class PayableRaisedEvent extends Event {

	/**
	 * The version of the answer's shape.
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The answer, once a listener accepted the command.
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Why the command was refused, once a listener refused it.
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp        The app that owes the money.
	 * @param float  $amount           How much to pay out, in the currency's major unit.
	 * @param string $payeeName        The account holder's name.
	 * @param string $payeeIban        The account the money goes to.
	 * @param string $reference        The app's reference for the payout, at least 6 characters, one per app.
	 * @param string $description      What the payout is for.
	 * @param string $callbackUrl      Where shillinq posts each step, signed; empty for none.
	 * @param string $category         The kind of payout, used to find the expense account.
	 * @param string $correlationId    The app's own id for the object the payout belongs to.
	 * @param string $administrationId The administration that books it.
	 * @param string $dueOn            The latest date the payout should reach the payee (Y-m-d), or empty.
	 * @param string $currency         The currency.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly float $amount,
		private readonly string $payeeName,
		private readonly string $payeeIban,
		private readonly string $reference,
		private readonly string $description = '',
		private readonly string $callbackUrl = '',
		private readonly string $category = '',
		private readonly string $correlationId = '',
		private readonly string $administrationId = '',
		private readonly string $dueOn = '',
		private readonly string $currency = 'EUR',
		private readonly array $linkedObjects = [],
		private readonly string $historicalState = '',
		private readonly string $paidOn = '',
		private readonly string $bankReference = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The command's fields as the payable stores them, before checks.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
	 */
	public function toArray(): array {
		return [
			'sourceApp' => trim($this->sourceApp),
			'category' => trim($this->category),
			'amount' => round($this->amount, 2),
			'currency' => strtoupper(trim($this->currency)),
			'payee' => [
				'name' => trim($this->payeeName),
				'iban' => strtoupper(str_replace(' ', '', $this->payeeIban)),
			],
			'reference' => trim($this->reference),
			'description' => trim($this->description),
			'correlationId' => trim($this->correlationId),
			'administrationId' => trim($this->administrationId),
			'dueOn' => trim($this->dueOn),
			'callbackUrl' => trim($this->callbackUrl),
			'linkedObjects' => array_values($this->linkedObjects),
			'historicalState' => trim($this->historicalState),
			'paidOn' => trim($this->paidOn),
			'bankReference' => trim($this->bankReference),
		];
	}//end toArray()

	/**
	 * Accept the command and answer with the payable.
	 *
	 * @param string $payableId The payable's uuid.
	 * @param string $state     Its state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
	 */
	public function accept(string $payableId, string $state): void {
		$this->error = null;
		$this->result = [
			'contractVersion' => self::CONTRACT_VERSION,
			'payableId' => $payableId,
			'state' => $state,
		];
	}//end accept()

	/**
	 * Refuse the command with a reason.
	 *
	 * @param string $error Why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
	 */
	public function refuse(string $error): void {
		$this->result = null;
		$this->error = $error;
	}//end refuse()

	/**
	 * Whether a listener accepted the command.
	 *
	 * @return bool True when accepted.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()

	/**
	 * The answer: contractVersion, payableId and state.
	 *
	 * @return array<string, mixed>|null The answer, or null when not accepted.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Why the command was refused.
	 *
	 * @return string|null The reason, or null when not refused.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md#REQ-PAY-001
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()
}//end class
