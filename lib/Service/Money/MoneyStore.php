<?php

/**
 * Where one kind of money record is stored
 *
 * The object service, the register and the schema PayoutService reads a
 * calculation from or writes a payout row to.
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

/**
 * One schema in the configured register, through the object service.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 */
final class MoneyStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register      Register identifier.
	 * @param string $schema        Schema identifier.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
