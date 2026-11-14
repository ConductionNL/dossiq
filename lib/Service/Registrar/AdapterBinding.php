<?php

/**
 * The adapter class a config key names, with the container and logger that build it or report why not.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Registrar
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Registrar;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The adapter class a config key names, with the container and logger that build it or report why not.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class AdapterBinding {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger
	 * @param string $configKey App-config key naming the adapter class
	 * @param string $named The class the key names
	 */
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly LoggerInterface $logger,
		public readonly string $configKey,
		public readonly string $named,
	) {
	}//end __construct()
}//end class
