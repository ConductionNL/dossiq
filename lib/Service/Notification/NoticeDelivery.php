<?php

/**
 * One requester notice on its way through the channels
 *
 * The case, the notice as it goes out, the moment it was attempted and what
 * the channels answered so far: the state one RequesterNoticeSender::send()
 * builds and its steps read, so each step takes one argument.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

/**
 * The state of one notice delivery; it never leaves the sender that built it.
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md
 */
final class NoticeDelivery {

	/**
	 * The case row.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];

	/**
	 * The template key.
	 *
	 * @var string
	 */
	public string $template = '';

	/**
	 * The moment of the notice (acknowledgement, decision, stage, ...).
	 *
	 * @var string
	 */
	public string $moment = '';

	/**
	 * The caller's options (channels, recipient, instanceId, dedupeKey, recordExtras).
	 *
	 * @var array<string, mixed>
	 */
	public array $options = [];

	/**
	 * When the delivery was attempted (ISO 8601).
	 *
	 * @var string
	 */
	public string $attemptedAt = '';

	/**
	 * The notice as the channels send it.
	 *
	 * @var array<string, string>
	 */
	public array $notice = [];

	/**
	 * The channels that refused, with their reason.
	 *
	 * @var array<int, array{channel: string, reasonCode: string, reason: string}>
	 */
	public array $tried = [];

	/**
	 * The answer of the channel that sent it, with that channel, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $sent = null;
}//end class
