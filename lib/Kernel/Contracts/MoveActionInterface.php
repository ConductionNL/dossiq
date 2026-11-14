<?php

/**
 * Contract of MoveAction
 *
 * The public surface of MoveAction that other components call, so they depend
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

use OCA\Dossiq\Service\Email\InboundMessage;

/**
 * Contract of MoveAction.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MoveActionInterface {

	/**
	 * File one message in another folder of the same account.
	 *
	 * @param InboundMessage        $message The message.
	 * @param string                $target  The folder it goes to.
	 * @param string                $note    Why, as the handler wrote it.
	 * @param string                $actorId Who moved it, or '' for the pipeline.
	 * @param array<string, string> $results The four authentication results.
	 *
	 * @return array{moved: bool, entryId: string} What happened, and the log entry.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function move(
		InboundMessage $message,
		string $target,
		string $note = '',
		string $actorId = '',
		array $results = [],
	): array;
}//end interface
