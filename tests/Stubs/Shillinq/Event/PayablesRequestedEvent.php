<?php

/**
 * Payables Requested Event
 *
 * The question an app that raised payouts asks to read them back: its own
 * payouts, narrowed by correlation id, category and the year they were paid
 * in. shillinq answers with one row per payout. The asking app never reads
 * shillinq's register itself (ADR-066).
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Asks for the payouts one app raised.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
 */
final class PayablesRequestedEvent extends Event {
	/**
	 * The version of the answer's shape.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The payouts, once shillinq answered.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $payables = null;

	/**
	 * Why the question was refused.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app that raised the payouts.
	 * @param array<int, string> $correlationIds Only payouts with one of these correlation ids, when not empty.
	 * @param string $category Only payouts in this category, when not empty.
	 * @param int $paidInYear Only payouts paid in this year, when above zero.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly array $correlationIds = [],
		private readonly string $category = '',
		private readonly int $paidInYear = 0,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that raised the payouts.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function getSourceApp(): string {
		return trim($this->sourceApp);
	}//end getSourceApp()

	/**
	 * The correlation ids to narrow to.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function getCorrelationIds(): array {
		return array_values(array_filter(array_map('strval', $this->correlationIds), static fn (string $id): bool => $id !== ''));
	}//end getCorrelationIds()

	/**
	 * The category to narrow to.
	 *
	 * @return string The category, or empty.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function getCategory(): string {
		return trim($this->category);
	}//end getCategory()

	/**
	 * The year the payouts were paid in.
	 *
	 * @return int The year, or zero.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function getPaidInYear(): int {
		return $this->paidInYear;
	}//end getPaidInYear()

	/**
	 * Answer with the payouts.
	 *
	 * @param array<int, array<string, mixed>> $payables One row per payout.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function answer(array $payables): void {
		$this->error = null;
		$this->payables = array_values($payables);
	}//end answer()

	/**
	 * Refuse the question.
	 *
	 * @param string $error Why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function refuse(string $error): void {
		$this->payables = null;
		$this->error = $error;
	}//end refuse()

	/**
	 * Whether shillinq answered.
	 *
	 * @return bool True when answered.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function isHandled(): bool {
		return $this->payables !== null;
	}//end isHandled()

	/**
	 * The payouts, or an empty list when unanswered.
	 *
	 * @return array<int, array<string, mixed>> The payouts.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function getPayables(): array {
		return ($this->payables ?? []);
	}//end getPayables()

	/**
	 * Why the question was refused.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-008)
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()
}//end class
