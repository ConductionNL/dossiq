<?php

/**
 * Dossiq Money Config Keys
 *
 * The app config keys of the money code, and the procedure-named keys they
 * replace. `CarryRenamedSettingKeys` copies a stored legacy value onto its
 * current key on upgrade; `SettingsService::getConfigValue()` and
 * `SignedPayoutCallback` still read the legacy key while the current one is
 * missing, so an install that has not run the repair keeps working.
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

/**
 * Key names only; no behaviour.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
 */
final class MoneyConfigKeys {

	/**
	 * The schema of an amount that accrues per day until it is settled.
	 *
	 * @var string
	 */
	public const ACCRUING_AMOUNT_SCHEMA = 'accruing_amount_schema';

	/**
	 * The schema of a payout dossiq prepared.
	 *
	 * @var string
	 */
	public const PAYOUT_SCHEMA = 'payout_schema';

	/**
	 * The HMAC secret the payout callback is signed with.
	 *
	 * @var string
	 */
	public const PAYOUT_CALLBACK_SECRET = 'payout_callback_secret';

	/**
	 * The schema of a notice of default that starts an accrual.
	 *
	 * @var string
	 */
	public const NOTICE_OF_DEFAULT_SCHEMA = 'notice_of_default_schema';

	/**
	 * Current key => the procedure-named key it replaces.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_KEYS = [
		self::ACCRUING_AMOUNT_SCHEMA => 'dwangsom_berekening_schema',
		self::PAYOUT_SCHEMA => 'dwangsom_uitbetaling_schema',
		self::PAYOUT_CALLBACK_SECRET => 'dwangsom_callback_secret',
		self::NOTICE_OF_DEFAULT_SCHEMA => 'ingebrekestelling_schema',
	];
}//end class
