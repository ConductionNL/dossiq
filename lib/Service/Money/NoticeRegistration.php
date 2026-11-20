<?php

/**
 * One notice of default being registered against a term
 *
 * The term instance it is about, how and when it arrived, from whom, and the
 * document it came with: the input of AccrualNoticeService::registerNotice(),
 * carried as one value through its steps.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;

/**
 * The state of one notice registration; it never leaves the service that built it.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
 */
final class NoticeRegistration {

	/**
	 * The term instance id.
	 *
	 * @var string
	 */
	public string $termInstanceId = '';

	/**
	 * The stored term instance.
	 *
	 * @var array<string, mixed>
	 */
	public array $instance = [];

	/**
	 * When the notice arrived.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $receiptDate;

	/**
	 * How it arrived.
	 *
	 * @var string
	 */
	public string $channel = '';

	/**
	 * The document it came with, or ''.
	 *
	 * @var string
	 */
	public string $documentLink = '';

	/**
	 * Who sent it, or ''.
	 *
	 * @var string
	 */
	public string $sender = '';
}//end class
