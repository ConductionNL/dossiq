<?php

/**
 * Dossiq Inbound Filter Verdict
 *
 * One filter's answer, carrying the name of the filter that gave it and the
 * reason it gave. Both travel with the message into the intake log, because
 * "no case was created" without a name and a reason is the log line this change
 * exists to replace.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email\Filters
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email\Filters;

/**
 * A filter's decision about one message.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — the static calls here are named
 *  constructors and value-object factories (`InboundMessage::fromRow()`,
 *  `FilterVerdict::accept()`, `AuthenticationVerdict::unknown()`), which hold no
 *  state and exist so a caller cannot build a half-built value.
 */
final class FilterVerdict {

	/**
	 * Constructor.
	 *
	 * @param string $outcome    One of the {@see FilterOutcome} values.
	 * @param string $filterName The filter that decided, or '' when none did.
	 * @param string $reason     Why, in a sentence a handler can read.
	 * @param string $forwardTo  The address a `forward` goes to, or ''.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $filterName = '',
		public readonly string $reason = '',
		public readonly string $forwardTo = '',
	) {
	}//end __construct()

	/**
	 * A filter with no opinion.
	 *
	 * @param string $filterName The filter that looked.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function pass(string $filterName = ''): self {
		return new self(outcome: FilterOutcome::PASS, filterName: $filterName);
	}//end pass()

	/**
	 * This message must not become a case.
	 *
	 * @param string $filterName The filter that decided.
	 * @param string $reason     Why.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function reject(string $filterName, string $reason): self {
		return new self(outcome: FilterOutcome::REJECT, filterName: $filterName, reason: $reason);
	}//end reject()

	/**
	 * A person decides about this message.
	 *
	 * @param string $filterName The filter that decided.
	 * @param string $reason     Why.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function quarantine(string $filterName, string $reason): self {
		return new self(outcome: FilterOutcome::QUARANTINE, filterName: $filterName, reason: $reason);
	}//end quarantine()

	/**
	 * This message goes on to somebody else.
	 *
	 * @param string $filterName The filter that decided.
	 * @param string $reason     Why.
	 * @param string $forwardTo  The address it goes to.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function forward(string $filterName, string $reason, string $forwardTo): self {
		return new self(
			outcome: FilterOutcome::FORWARD,
			filterName: $filterName,
			reason: $reason,
			forwardTo: $forwardTo
		);
	}//end forward()

	/**
	 * This message may become a case.
	 *
	 * @param string $filterName The filter that decided, or '' for the default.
	 * @param string $reason     Why.
	 *
	 * @return self The verdict.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function accept(string $filterName = '', string $reason = ''): self {
		return new self(outcome: FilterOutcome::ACCEPT, filterName: $filterName, reason: $reason);
	}//end accept()

	/**
	 * Whether this verdict ends the pipeline.
	 *
	 * @return boolean True for everything except `pass`.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isDecisive(): bool {
		return FilterOutcome::isDecisive(outcome: $this->outcome);
	}//end isDecisive()

	/**
	 * The verdict as the intake log stores it.
	 *
	 * @return array{outcome: string, filter: string, reason: string, forwardTo: string} The row.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function toArray(): array {
		return [
			'outcome' => $this->outcome,
			'filter' => $this->filterName,
			'reason' => $this->reason,
			'forwardTo' => $this->forwardTo,
		];
	}//end toArray()
}//end class
