<?php

/**
 * A stand-in for Nextcloud Mail's outbox row, for unit tests only.
 *
 * Nextcloud Mail is not installed in the unit-test run, and dossiq's gateway
 * builds `OCA\Mail\Db\LocalMessage` with `new`. This stand-in answers its
 * setters and getters the way Mail's `Entity::__call()` does, so the gateway's
 * real code path runs. Loaded by hand from the tests that need it, never by
 * the autoloader, so no other test sees a Mail class that is not there.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Mail\Db;

/**
 * Magic getters and setters over a property bag, like Mail's entity.
 */
class LocalMessage {

	/**
	 * The values set.
	 *
	 * @var array<string, mixed>
	 */
	public array $values = [];

	/**
	 * Answer setX($v) and getX() / isX().
	 *
	 * @param string            $name      The method.
	 * @param array<int, mixed> $arguments The arguments.
	 *
	 * @return mixed The value for a getter, null for a setter.
	 */
	public function __call(string $name, array $arguments): mixed {
		if (str_starts_with($name, 'set') === true) {
			$this->values[lcfirst(substr($name, 3))] = $arguments[0];
			return null;
		}

		$property = lcfirst(substr($name, (str_starts_with($name, 'is') === true ? 2 : 3)));
		if (array_key_exists($property, $this->values) === false) {
			throw new \BadFunctionCallException($property . ' is not set');
		}

		return $this->values[$property];
	}//end __call()
}//end class
