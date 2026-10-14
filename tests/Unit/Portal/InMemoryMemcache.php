<?php

/**
 * An in-memory IMemcache for tests: `add()` claims a key at once, as APCu and Redis do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Portal
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCP\IMemcache;

/**
 * Keeps values in an array; ttl is ignored.
 */
class InMemoryMemcache implements IMemcache {

	/**
	 * The values.
	 *
	 * @var array<string, mixed>
	 */
	public array $values = [];

	public function get(string $key) {
		return ($this->values[$key] ?? null);
	}

	public function set(string $key, $value, int $ttl = 0) {
		$this->values[$key] = $value;
		return true;
	}

	public function hasKey(string $key) {
		return array_key_exists($key, $this->values);
	}

	public function remove(string $key) {
		unset($this->values[$key]);
		return true;
	}

	public function clear(string $prefix = '') {
		$this->values = [];
		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}

	public function add($key, $value, $ttl = 0) {
		if (array_key_exists($key, $this->values) === true) {
			return false;
		}

		$this->values[$key] = $value;
		return true;
	}

	public function inc($key, $step = 1) {
		$this->values[$key] = ((int)($this->values[$key] ?? 0) + $step);
		return $this->values[$key];
	}

	public function dec($key, $step = 1) {
		$this->values[$key] = ((int)($this->values[$key] ?? 0) - $step);
		return $this->values[$key];
	}

	public function cas($key, $old, $new) {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}

		$this->values[$key] = $new;
		return true;
	}

	public function cad($key, $old) {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}

		unset($this->values[$key]);
		return true;
	}

	public function ncad(string $key, mixed $old): bool {
		if (($this->values[$key] ?? null) === $old) {
			return false;
		}

		unset($this->values[$key]);
		return true;
	}
}
