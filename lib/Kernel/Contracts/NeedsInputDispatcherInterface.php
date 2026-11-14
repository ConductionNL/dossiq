<?php

/**
 * Contract of NeedsInputDispatcher
 *
 * The public surface of NeedsInputDispatcher that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of NeedsInputDispatcher.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NeedsInputDispatcherInterface {

	/**
	 * Dispatch a needs-input event.
	 *
	 * @param string $type The event type slug (e.g. stuf_circuit_open, stuf_permanent_error, stuf_timeout).
	 * @param array<string,mixed> $context Structured context (endpointId, fout, etc.).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-needs-input-escalation
	 */
	public function dispatch(string $type, array $context = []): void;
}//end interface
