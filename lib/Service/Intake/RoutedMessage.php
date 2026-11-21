<?php

/**
 * One routed channel message on its way to a case.
 *
 * Built once by `ChannelIntake::caseFor()` and handed through its private steps: the message, the
 * rule's mapped payload, the rule's name, and the channel and message id read off the message.
 *
 * @category Intake
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * One routed channel message on its way to a case.
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */
final class RoutedMessage {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $message    The message, as integriq stored it.
	 * @param array<string, mixed> $payload    The rule's field mapping, already applied.
	 * @param string               $ruleName   The rule that matched, for the log.
	 * @param string               $channel    The channel the message came in on.
	 * @param string               $externalId The message's own id on that channel.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $message,
		public readonly array $payload,
		public readonly string $ruleName,
		public readonly string $channel,
		public readonly string $externalId,
	) {
	}//end __construct()
}//end class
