<?php

/**
 * Opens a portal read of one case.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;

/**
 * Opens a portal read of one case once OpenRegister and the register are there.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PortalCaseReads {

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService OpenRegister access and the configured register.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
	) {
	}//end __construct()

	/**
	 * The read of one case, or null without a case, an object service or a register.
	 *
	 * @param string $caseId The case.
	 *
	 * @return PortalCaseRead|null
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function open(string $caseId): ?PortalCaseRead {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($caseId === '' || $objectService === null || $register === '') {
			return null;
		}

		$read = new PortalCaseRead();
		$read->objectService = $objectService;
		$read->register = $register;
		$read->caseId = $caseId;

		return $read;
	}//end open()
}//end class
