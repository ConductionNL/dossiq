<?php

/**
 * Shillinq BillablePeriodClosedEvent test stub.
 *
 * Mirrors shillinq's real command (ConductionNL/shillinq, change
 * billable-period-becomes-an-invoice, `lib/Event/BillablePeriodClosedEvent.php`)
 * verbatim, so ShillinqIntegrationService is tested against the real
 * constructor order and answer slots rather than against its own assumption
 * about them. tests/bootstrap.php loads it only when the real class is absent.
 *
 * @category Tests
 * @package  OCA\Shillinq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Event;

use OCP\EventDispatcher\Event;

/**
 * A billable period of an account closed; answered with a draft invoice or a refusal.
 *
 */
final class BillablePeriodClosedEvent extends Event {

	/**
	 * The version of the answer's shape.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The answer, once a listener accepted the command.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Why the command was refused, once a listener refused it.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app that asks, for example `dossiq`.
	 * @param string $externalReference The customer's id in the asking app, carried by CustomerMaster.externalReference.
	 * @param string $period The billed month, `YYYY-MM`.
	 * @param array<int, array<string, mixed>> $lines Lines: description, quantity, unitPrice (euros), optional currency and sourceId.
	 * @param string $correlationId The asking app's own id for this command.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $externalReference,
		private readonly string $period,
		private readonly array $lines,
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that asks.
	 *
	 * @return string The app id.
	 *
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The customer's id in the asking app.
	 *
	 * @return string The reference.
	 *
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * The billed month.
	 *
	 * @return string `YYYY-MM`.
	 *
	 */
	public function getPeriod(): string {
		return $this->period;
	}//end getPeriod()

	/**
	 * The lines to bill.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 *
	 */
	public function getLines(): array {
		return $this->lines;
	}//end getLines()

	/**
	 * The asking app's own id for this command.
	 *
	 * @return string The correlation id.
	 *
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Accept the command and answer with the invoice drafted for it.
	 *
	 * @param string $invoiceId The BillableInvoice uuid.
	 * @param string $invoiceNumber The invoice number.
	 *
	 * @return void
	 *
	 */
	public function accept(string $invoiceId, string $invoiceNumber): void {
		$this->error  = null;
		$this->result = [
			'contractVersion' => self::CONTRACT_VERSION,
			'invoiceId' => $invoiceId,
			'invoiceNumber' => $invoiceNumber,
			'status' => 'draft',
			'duplicated' => false,
		];
	}//end accept()

	/**
	 * Accept a repeat: this month was drafted before, and that invoice is the answer.
	 *
	 * @param string $invoiceId The BillableInvoice uuid drafted earlier.
	 * @param string $invoiceNumber Its invoice number.
	 *
	 * @return void
	 *
	 */
	public function acceptDuplicate(string $invoiceId, string $invoiceNumber): void {
		$this->accept(invoiceId: $invoiceId, invoiceNumber: $invoiceNumber);
		$this->result['duplicated'] = true;
	}//end acceptDuplicate()

	/**
	 * Refuse the command with a reason.
	 *
	 * @param string $error Why, in words the asking app can record.
	 *
	 * @return void
	 *
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
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()

	/**
	 * The answer: contractVersion, invoiceId, invoiceNumber, status and duplicated.
	 *
	 * @return array<string, mixed>|null The answer, or null when not accepted.
	 *
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Why the command was refused.
	 *
	 * @return string|null The reason, or null when not refused.
	 *
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()
}//end class
