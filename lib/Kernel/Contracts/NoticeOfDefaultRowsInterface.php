<?php

/**
 * Contract of NoticeOfDefaultRows
 *
 * The public surface of NoticeOfDefaultRows that other components call, so they depend
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

use Throwable;

/**
 * Contract of NoticeOfDefaultRows.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NoticeOfDefaultRowsInterface {

	/**
	 * The notices of default on one case, newest receipt first.
	 *
	 * The same rows as the deadlines report, kept to the case the notice's
	 * term belongs to, so the case page and the report never disagree about a
	 * notice's assessment, penalty or payout.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{notices: list<array<string, mixed>>}
	 *
	 * @throws Throwable When the notices or the penalty calculations cannot be read.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md
	 */
	public function forCase(string $caseId): array;

	/**
	 * The notices of default, newest receipt first.
	 *
	 * @return array{notices: list<array<string, mixed>>}
	 *
	 * @throws Throwable When the notices or the penalty calculations cannot be read; a list
	 *                   without them would read "no notices" or "no penalty".
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function list(): array;
}//end interface
