<?php

/**
 * Dossiq RepairHelperFactory
 *
 * @category  Repair
 * @package   OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\LoadDefaultZgwMappingsInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwMappingServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the repair steps other components run outside a repair, so they
 * name the step's contract rather than its implementation.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural factory, no functional requirement.
 */
final class RepairHelperFactory {
	/**
	 * The default ZGW mapping loader.
	 *
	 * @param ZgwMappingServiceInterface $zgwMappingService The ZGW mapping service.
	 * @param SettingsServiceInterface   $settingsService   The settings service.
	 * @param LoggerInterface            $logger            The logger.
	 *
	 * @return LoadDefaultZgwMappingsInterface
	 *
	 * @spec exclude Structural factory, no functional requirement.
	 */
	public function defaultZgwMappings(
		ZgwMappingServiceInterface $zgwMappingService,
		SettingsServiceInterface $settingsService,
		LoggerInterface $logger,
	): LoadDefaultZgwMappingsInterface {
		return new LoadDefaultZgwMappings(zgwMappingService: $zgwMappingService, settingsService: $settingsService, logger: $logger);
	}//end defaultZgwMappings()
}//end class
