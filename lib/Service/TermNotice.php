<?php

/**
 * One term notification being rendered and sent
 *
 * The template, the term instance it is about with its stored row, the
 * recipient, the caller's context and the case it goes out on: the state
 * TermijnNotificationService::sendTermijnNotification() builds and its
 * steps read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one term notification; it never leaves the service that built it.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md
 */
final class TermNotice {

	/**
	 * The template.
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * The term instance id.
	 *
	 * @var string
	 */
	public string $termInstanceId = '';

	/**
	 * The stored term instance, or [] when it cannot be read.
	 *
	 * @var array<string, mixed>
	 */
	public array $instance = [];

	/**
	 * The recipient.
	 *
	 * @var string
	 */
	public string $recipientUserId = '';

	/**
	 * The caller's context (moment, dedupeKey, recordExtras, channels, ...).
	 *
	 * @var array<string, mixed>
	 */
	public array $context = [];

	/**
	 * The case the notice goes out on.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];
}//end class
