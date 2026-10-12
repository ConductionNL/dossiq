<?php

/**
 * Dossiq AccruingAmountSettler.
 *
 * Settles an amount that OpenRegister accrues as a stored calculation (a
 * daily penalty, a late fee, interest): it writes the day accrual stopped
 * and the new status, lets OpenRegister re-materialise the accrued amount on
 * that save, and locks the materialised amount into the definitive field.
 * The arithmetic is configuration on the schema (`graduated` over
 * `rateBands`); this class only stops the clock (ADR-118 C16).
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
 * @spec openspec/changes/penalty-is-a-stored-calculation/specs/accruing-amount/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Stops an accruing amount and locks what it reached.
 *
 * @spec openspec/changes/penalty-is-a-stored-calculation/specs/accruing-amount/spec.md
 */
class AccruingAmountSettler {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Settings (object service, register, schema keys).
	 * @param CaseDateNormaliser $dates           The administered calendar day of a moment.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

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
	): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('The store for ' . $schemaConfigKey . ' is not configured');
		}

		$row = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
		if ($row === null) {
			return null;
		}

		if ((string)($row['stoppedOn'] ?? '') === '') {
			$row['stoppedOn'] = $this->dates->formatCalendarDate(($stopAt ?? $this->dates->now()));
		}

		$row['status'] = $status;
		// OpenRegister re-materialises the stored calculations on this save,
		// so the answer carries the amount accrued up to stoppedOn.
		$stopped = ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $row) ?? $row);
		$stopped[$lockField] = (int)($stopped[$amountField] ?? 0);

		return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $stopped) ?? $stopped);
	}//end settle()
}//end class
