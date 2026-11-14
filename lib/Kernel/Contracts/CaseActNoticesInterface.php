<?php

/**
 * Contract of CaseActNotices
 *
 * The public surface of CaseActNotices that other components call, so they depend
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
 * Contract of CaseActNotices.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseActNoticesInterface {

	/**
	 * The engine refused to cancel a task, for its own reason.
	 *
	 * @param string $task   The task's title.
	 * @param string $reason The engine's reason.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-an-action-on-the-case-page-answers-with-a-card-notice
	 */
	public function cancelRefused(string $task, string $reason): array;

	/**
	 * OpenRegister refused to revoke a share.
	 *
	 * @return array{variant: string, role: string, title: string, text: string}
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function revokeRefused(): array;
}//end interface
