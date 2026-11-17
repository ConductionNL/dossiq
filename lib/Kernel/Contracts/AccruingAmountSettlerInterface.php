<?php

/**
 * Contract of AccruingAmountSettler
 *
 * The public surface of AccruingAmountSettler that other components call, so they depend
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

use DateTimeImmutable;
use RuntimeException;

/**
 * Contract of AccruingAmountSettler.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccruingAmountSettlerInterface {

	/**
	 * Stop the accrual on a day, set the status, and lock the accrued amount.
	 *
	 * @param string                 $schemaConfigKey The app config key naming the schema.
	 * @param string                 $id              The object id.
	 * @param string                 $status          The status the object moves to.
	 * @param DateTimeImmutable|null $stopAt          The stop moment (now when null).
	 * @param string                 $amountField     The materialised accrued amount.
	 * @param string                 $lockField       The field the amount is locked into.
	 *
	 * @return array<string, mixed>|null The settled object, or null when it does not exist.
	 *
	 * @throws RuntimeException When the store is not configured or a save fails.
	 *
	 * @spec openspec/changes/penalty-is-a-stored-calculation/specs/accruing-amount/spec.md
	 */
	public function settle(
		string $schemaConfigKey,
		string $id,
		string $status,
		?DateTimeImmutable $stopAt = null,
		string $amountField = 'cumulativeAmount',
		string $lockField = 'definitiveAmount',
	): ?array;
}//end interface
