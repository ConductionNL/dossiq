<?php

/**
 * One createTask call on its way to the task handler
 *
 * What TaskTools::createTask() carries between its checks and the write:
 * the caller, what they asked for, and the case once it was read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCP\IUser;

/**
 * The state of one createTask call.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */
final class CreateTaskRun {

	/**
	 * The caller, null without a session.
	 *
	 * @var IUser|null
	 */
	public ?IUser $user = null;

	/**
	 * The trimmed case UUID.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The trimmed title.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * Working days until it is due; 0 for no due date.
	 *
	 * @var int
	 */
	public int $leadTimeDays = 0;

	/**
	 * `handler` or `me`.
	 *
	 * @var string
	 */
	public string $for = 'handler';

	/**
	 * The case, once read.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $openCase = null;
}//end class
