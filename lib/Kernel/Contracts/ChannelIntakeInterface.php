<?php

/**
 * Contract of ChannelIntake
 *
 * The public surface of ChannelIntake that other components call, so they depend
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
 * Contract of ChannelIntake.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChannelIntakeInterface {

	/**
	 * Open a case for one routed message, or say why not.
	 *
	 * @param array<string, mixed> $message       The message, as integriq stored it.
	 * @param array<string, mixed> $targetPayload The rule's field mapping, already applied.
	 * @param string               $ruleName      The rule that matched, for the log.
	 *
	 * @return string The case id, or '' when no case was opened.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function caseFor(array $message, array $targetPayload, string $ruleName = ''): string;

	/**
	 * Whether a routed message is addressed at a dossiq case at all.
	 *
	 * A rule naming another app's schema is not this app's to answer, and
	 * answering it would take a message away from whoever it was for.
	 *
	 * @param string $targetSchema The schema the rule named.
	 *
	 * @return boolean True when it names this app's case schema.
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	public function isForACase(string $targetSchema): bool;
}//end interface
