<?php

/**
 * Reading custody holdings from the dossier register.
 *
 * The chain reads the holdings of one case and the query reads the holdings of
 * one unit. Both read the same schema the same way and answer an empty list,
 * after a warning, when the register cannot be read; this is that read.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Searches the custody schema, answering an empty list when it cannot be read.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
 */
final class CustodyRows {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Records a read that could not be answered.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The holdings matching the filters, or an empty list after a warning.
	 *
	 * The warning carries the filters other than `_limit`, then the exception
	 * message, so it names what was being read.
	 *
	 * @param array<string, mixed> $filters The search filters, `_limit` included.
	 * @param string               $failure The warning logged when the read fails.
	 *
	 * @return array<int, array<string, mixed>> The holdings, in the order the register gave them.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function find(array $filters, string $failure): array {
		try {
			$register = new DossierRegister(settingsService: $this->settingsService);
			[$objectService, $registerId] = $register->context();

			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $registerId,
				schema: $register->schema(key: 'case_custody_schema'),
				filters: $filters,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				$failure,
				array_merge(array_diff_key($filters, ['_limit' => true]), ['exception' => $e->getMessage()]),
			);

			return [];
		}
	}//end find()
}//end class
