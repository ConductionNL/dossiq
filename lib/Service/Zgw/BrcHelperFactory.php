<?php

/**
 * Dossiq BrcHelperFactory
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Zgw
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

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Kernel\Contracts\BrcCrossRegisterSyncInterface;
use OCA\Dossiq\Kernel\Contracts\BrcDocumentLinkHandlerInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;

/**
 * Builds the BRC helpers the ZGW controllers use, so the controllers name
 * the helpers' contracts rather than their implementations.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural factory, no functional requirement.
 */
final class BrcHelperFactory {
	/**
	 * The BRC cross-register sync.
	 *
	 * @param ZgwServiceInterface      $zgwService      The ZGW service.
	 * @param SettingsServiceInterface $settingsService The settings service.
	 *
	 * @return BrcCrossRegisterSyncInterface
	 *
	 * @spec exclude Structural factory, no functional requirement.
	 */
	public function brcCrossRegisterSync(ZgwServiceInterface $zgwService, SettingsServiceInterface $settingsService): BrcCrossRegisterSyncInterface {
		return new BrcCrossRegisterSync(zgwService: $zgwService, settingsService: $settingsService);
	}//end brcCrossRegisterSync()

	/**
	 * The BRC document-link handler.
	 *
	 * @param ZgwServiceInterface           $zgwService The ZGW service.
	 * @param BrcCrossRegisterSyncInterface $sync       The cross-register sync.
	 *
	 * @return BrcDocumentLinkHandlerInterface
	 *
	 * @spec exclude Structural factory, no functional requirement.
	 */
	public function brcDocumentLinkHandler(ZgwServiceInterface $zgwService, BrcCrossRegisterSyncInterface $sync): BrcDocumentLinkHandlerInterface {
		return new BrcDocumentLinkHandler(zgwService: $zgwService, sync: $sync);
	}//end brcDocumentLinkHandler()
}//end class
