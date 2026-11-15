<?php

/**
 * Dossiq BuildsRegistryWrites support trait.
 *
 * The registry write helper, wired to this controller. The using class provides `$registry`, `$request` and `$logger`.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;


/**
 * The registry write helper, wired to this controller.
 *
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */
trait BuildsRegistryWrites {

	/**
	 * Writes registry objects from this request's body.
	 *
	 * @return RegistryObjectWrite
	 */
	private function writes(): RegistryObjectWrite {
		$writes = new RegistryObjectWrite();
		$writes->registry = $this->registry;
		$writes->request = $this->request;
		$writes->logger = $this->logger;
		return $writes;
	}//end writes()
}//end trait
