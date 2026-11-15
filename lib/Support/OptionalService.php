<?php

/**
 * Dossiq OptionalService support class.
 *
 * Resolves a collaborator of an app that may be missing (OpenRegister's legal
 * hold service, its mappers) without letting its absence throw.
 *
 * @category Support
 * @package  OCA\Dossiq\Support
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
 * @spec exclude container plumbing shared by a listener and a repair step
 */

declare(strict_types=1);

namespace OCA\Dossiq\Support;

use Psr\Container\ContainerInterface;

/**
 * A class from the container, or null when the class or the service is missing.
 *
 * @spec exclude container plumbing shared by a listener and a repair step
 */
final class OptionalService {

	/**
	 * Resolve a collaborator by FQN, or null when unavailable.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param string             $fqn       Fully-qualified class name.
	 *
	 * @return object|null
	 *
	 * @spec exclude container plumbing shared by a listener and a repair step
	 */
	public static function from(ContainerInterface $container, string $fqn): ?object {
		if (class_exists($fqn) === false) {
			return null;
		}

		try {
			$service = $container->get($fqn);
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end from()
}//end class
