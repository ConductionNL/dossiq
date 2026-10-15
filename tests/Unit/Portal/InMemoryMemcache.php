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

	/**
	 * The value behind a key, or null.
	 *
	 * ICache types its parameters from Nextcloud 35 on and leaves them untyped in
	 * 32 to 34. Every parameter here is untyped so the class satisfies both: an
	 * implementation may widen a parameter type, never narrow it, so naming
	 * `string` is a fatal error on 32 to 34.
	 *
	 * @param string $key The key.
	 *
	 * @return mixed
	 */
	public function get($key) {
		return ($this->values[$key] ?? null);
	}

	/**
	 * Keep a value. The ttl is ignored.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 * @param int    $ttl   Ignored.
	 *
	 * @return bool
	 */
	public function set($key, $value, $ttl = 0) {
		$this->values[$key] = $value;
		return true;
	}

	/**
	 * Whether a key is present.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	public function hasKey($key) {
		return array_key_exists($key, $this->values);
	}

	/**
	 * Drop a key.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	public function remove($key) {
		unset($this->values[$key]);
		return true;
	}

	/**
	 * Drop every key. The prefix is ignored.
	 *
	 * @param string $prefix Ignored.
	 *
	 * @return bool
	 */
	public function clear($prefix = '') {
		$this->values = [];
		return true;
	}

	/**
	 * This cache is always available.
	 *
	 * @return bool
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Claim a key, as APCu and Redis do: false when it is already taken.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 * @param int    $ttl   Ignored.
	 *
	 * @return bool
	 */
	public function add($key, $value, $ttl = 0) {
		if (array_key_exists($key, $this->values) === true) {
			return false;
		}

		$this->values[$key] = $value;
		return true;
	}

	/**
	 * Raise a counter.
	 *
	 * @param string $key  The key.
	 * @param int    $step How much.
	 *
	 * @return int
	 */
	public function inc($key, $step = 1) {
		$this->values[$key] = ((int)($this->values[$key] ?? 0) + $step);
		return $this->values[$key];
	}

	/**
	 * Lower a counter.
	 *
	 * @param string $key  The key.
	 * @param int    $step How much.
	 *
	 * @return int
	 */
	public function dec($key, $step = 1) {
		$this->values[$key] = ((int)($this->values[$key] ?? 0) - $step);
		return $this->values[$key];
	}

	/**
	 * Replace a value only when it still holds the old one.
	 *
	 * @param string $key The key.
	 * @param mixed  $old The value it must hold.
	 * @param mixed  $new The value to write.
	 *
	 * @return bool
	 */
	public function cas($key, $old, $new) {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}

		$this->values[$key] = $new;
		return true;
	}

	/**
	 * Drop a key only when it still holds the old value.
	 *
	 * @param string $key The key.
	 * @param mixed  $old The value it must hold.
	 *
	 * @return bool
	 */
	public function cad($key, $old) {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}

		unset($this->values[$key]);
		return true;
	}

	/**
	 * Drop a key unless it still holds the old value. IMemcache types this one in
	 * every supported Nextcloud version, so it keeps its types.
	 *
	 * @param string $key The key.
	 * @param mixed  $old The value it must not hold.
	 *
	 * @return bool
	 */
	public function ncad(string $key, mixed $old): bool {
		if (($this->values[$key] ?? null) === $old) {
			return false;
		}

		unset($this->values[$key]);
		return true;
	}
}
