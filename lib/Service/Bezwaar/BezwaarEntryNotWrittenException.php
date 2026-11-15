<?php

/**
 * Dossiq Bezwaar Entry Not Written Exception.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Bezwaar;

use RuntimeException;
use Throwable;

/**
 * A bezwaar entry could not be written to OpenRegister's audit trail.
 *
 * It carries the entry it could not write, so whoever catches it can log the
 * full entry at error level: an Awb record that is lost must at least leave
 * its content in the log.
 *
 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
 */
class BezwaarEntryNotWrittenException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string               $action     The action that was not written.
	 * @param string               $objectUuid The record it was for.
	 * @param array<string, mixed> $entry      The entry that was not written.
	 * @param string               $reason     Why, in one sentence.
	 * @param Throwable|null       $previous   The cause.
	 */
	public function __construct(
		private readonly string $action,
		private readonly string $objectUuid,
		private readonly array $entry,
		string $reason,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $reason, code: 0, previous: $previous);
	}//end __construct()

	/**
	 * The entry, its action and its record, for an error log.
	 *
	 * @return array<string, mixed> The log context.
	 *
	 * @spec openspec/specs/bezwaar-awb-audit-trail/spec.md
	 */
	public function logContext(): array {
		$context = ['action' => $this->action, 'object' => $this->objectUuid, 'entry' => $this->entry];
		if ($this->getPrevious() !== null) {
			$context['exception'] = $this->getPrevious()->getMessage();
		}

		return $context;
	}//end logContext()
}//end class
