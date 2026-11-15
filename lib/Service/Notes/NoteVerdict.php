<?php

/**
 * How a note push ended: the sentence for the case history, the outcome code and the reason.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notes;

/**
 * The outcome of one push, before it is written on the case.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class NoteVerdict {

	/**
	 * Constructor.
	 *
	 * @param string $message The sentence for the case history.
	 * @param string $outcome The outcome code.
	 * @param string $reason  The reason, when there is one.
	 */
	public function __construct(
		public readonly string $message,
		public readonly string $outcome,
		public readonly string $reason,
	) {
	}//end __construct()

	/**
	 * The sentence with its reason, as the case history shows it.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function sentence(): string {
		if ($this->reason === '') {
			return $this->message;
		}

		return $this->message . ': ' . $this->reason;
	}//end sentence()
}//end class
