<?php

/**
 * Contract of CaseEmailMatchService
 *
 * The public surface of CaseEmailMatchService that other components call, so they depend
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
 * Contract of CaseEmailMatchService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseEmailMatchServiceInterface {

	/**
	 * Instance toggle. Anything but an explicit yes means off.
	 *
	 * @var string
	 */
	public const INSTANCE_TOGGLE_KEY = 'email_case_matching_enabled';

	/**
	 * Whether matching is switched on for this instance.
	 *
	 * @return bool True only for an explicit yes.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function isInstanceEnabled(): bool;

	/**
	 * Run one matching pass over one user's mail.
	 *
	 * @param string $userId The mailbox owner.
	 *
	 * @return array{linked: int, scanned: int} What the run did.
	 *
	 * @spec openspec/specs/email-case-matching/spec.md
	 */
	public function runForUser(string $userId): array;
}//end interface
