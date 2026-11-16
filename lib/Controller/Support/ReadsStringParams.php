<?php

/**
 * Dossiq ReadsStringParams support trait.
 *
 * A request parameter that is a string, or null. The using class provides the controller `$request`.
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
 * A request parameter that is a string, or null.
 *
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */
trait ReadsStringParams {

	/**
	 * A request parameter as a string, or null when it is absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end stringParam()
}//end trait
