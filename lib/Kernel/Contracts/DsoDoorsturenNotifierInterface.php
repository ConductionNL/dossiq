<?php

/**
 * Contract of DsoDoorsturenNotifier
 *
 * The public surface of DsoDoorsturenNotifier that other components call, so they depend
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
 * Contract of DsoDoorsturenNotifier.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DsoDoorsturenNotifierInterface {

	/**
	 * Dispatch the VergunningDoorgestuurd event for a forwarded case.
	 *
	 * @param array<string,mixed> $case The zaak being forwarded.
	 * @param string $caseId The zaak UUID.
	 * @param string $targetBevoegdGezag The receiving bevoegd gezag.
	 * @param string $reason The reason for forwarding.
	 * @param string $userId The acting user id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function dispatchDoorgestuurd(
		array $case,
		string $caseId,
		string $targetBevoegdGezag,
		string $reason,
		string $userId,
	): void;
}//end interface
