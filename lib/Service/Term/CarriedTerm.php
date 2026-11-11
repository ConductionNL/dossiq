<?php

/**
 * Dossiq CarriedTerm
 *
 * The term state a source carries onto a case's statutory term.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

/**
 * The deadline, extensions and suspension a source declares, with what an extension is recorded as.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-an-imported-records-running-term-is-carried-onto-the-case-req-cri-003
 */
final class CarriedTerm {
	/**
	 * Constructor.
	 *
	 * @param string               $deadline   The end date, `Y-m-d`, or ''.
	 * @param int                  $extensions How many times it was extended, at least 0.
	 * @param bool                 $suspended  Whether it is suspended.
	 * @param string               $reason     Why it was extended, possibly empty.
	 * @param int                  $days       What one extension is worth, at least 0.
	 * @param array<string, mixed> $definitie  The term definition it runs under, once read.
	 */
	public function __construct(
		public readonly string $deadline,
		public readonly int $extensions,
		public readonly bool $suspended,
		public readonly string $reason,
		public readonly int $days,
		public readonly array $definitie = [],
	) {
	}//end __construct()

	/**
	 * The status the case's term takes: paused, extended or running.
	 *
	 * @return string The status.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-an-imported-records-running-term-is-carried-onto-the-case-req-cri-003
	 */
	public function status(): string {
		if ($this->suspended === true) {
			return 'paused';
		}

		if ($this->extensions > 0) {
			return 'verlengd';
		}

		return 'lopend';
	}//end status()

	/**
	 * The same term state, under the definition read for it.
	 *
	 * @param array<string, mixed> $definitie The term definition.
	 *
	 * @return self The carried term.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-an-imported-records-running-term-is-carried-onto-the-case-req-cri-003
	 */
	public function withDefinitie(array $definitie): self {
		return new self(
			deadline: $this->deadline,
			extensions: $this->extensions,
			suspended: $this->suspended,
			reason: $this->reason,
			days: $this->days,
			definitie: $definitie
		);
	}//end withDefinitie()
}//end class
