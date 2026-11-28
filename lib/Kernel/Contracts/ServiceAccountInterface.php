<?php

/**
 * Contract of ServiceAccount
 *
 * The public surface of ServiceAccount that other components call, so they depend
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
 * Contract of ServiceAccount.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ServiceAccountInterface {

	/**
	 * Why the account cannot be used: the uid names no account.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown';

	/**
	 * Why the account cannot be used: the account is disabled.
	 *
	 * @var string
	 */
	public const REASON_DISABLED = 'disabled';

	/**
	 * Why the account cannot be used: it is not in the service group.
	 *
	 * @var string
	 */
	public const REASON_NOT_A_MEMBER = 'not-in-group';
}//end interface
