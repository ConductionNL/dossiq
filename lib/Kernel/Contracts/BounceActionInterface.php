<?php

/**
 * Contract of BounceAction
 *
 * The public surface of BounceAction that other components call, so they depend
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
 * Contract of BounceAction.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BounceActionInterface {

	/**
	 * Send one message on to another address.
	 *
	 * @param InboundMessage        $message   The message.
	 * @param string                $toAddress The body it goes to.
	 * @param string                $reason    Why, as the handler wrote it.
	 * @param string                $actorId   The user id of whoever bounced it, or '' for the pipeline.
	 * @param array<string, string> $results   The four authentication results.
	 *
	 * @return array{sent: bool, entryId: string} What happened, and the log entry.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function bounce(
		InboundMessage $message,
		string $toAddress,
		string $reason,
		string $actorId = '',
		array $results = [],
	): array;
}//end interface
