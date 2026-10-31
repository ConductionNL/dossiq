<?php

/**
 * Dossiq register fragment merger (ADR-037).
 *
 * Deep-merges the modular register fragments in `lib/Settings/register.d/*.json`
 * onto the `dossiq_register.json` monolith, so concurrent same-app builds can
 * add registers and schemas via isolated fragment files instead of all editing
 * one file and conflicting.
 *
 * Also fingerprints the applied fragment set, so adding, changing or removing a
 * fragment changes the import version — which is OpenRegister's idempotency key,
 * and therefore the only thing that forces a re-import.
 *
 * Split out of {@see \OCA\Dossiq\Service\SettingsService}: merging is a pure
 * data transformation with no config, no container and no logging, and both of
 * its callers only need the merged result.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

/**
 * Deep-merges modular register fragments onto the base register configuration.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class RegisterFragmentMerger {
	/**
	 * Merge modular register fragments (ADR-037) onto a base configuration.
	 *
	 * Reads every `*.json` file in the given fragment directory in sorted
	 * filename order and deep-merges each onto the base configuration. The
	 * `README.md` (and any non-JSON files) are ignored. Returns the merged
	 * configuration plus a short stable hash that fingerprints the applied
	 * fragment set (filename + content), so callers can fold it into the
	 * import version to force re-import when fragments change.
	 *
	 * @param array $base The parsed monolith configuration.
	 * @param string $fragmentDir Absolute path to the register.d directory.
	 *
	 * @return array{0: array<string,mixed>, 1: string} The merged config and the fragment hash ('' when no fragments).
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function merge(array $base, string $fragmentDir): array {
		if (is_dir($fragmentDir) === false) {
			return [$base, ''];
		}

		$files = glob($fragmentDir . '/*.json');
		if ($files === false || empty($files) === true) {
			return [$base, ''];
		}

		sort($files);

		$merged = $base;
		$hashAccumulator = '';

		foreach ($files as $file) {
			$content = file_get_contents($file);
			if ($content === false) {
				continue;
			}

			$fragment = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE || is_array($fragment) === false) {
				continue;
			}

			$merged = $this->deepMerge(base: $merged, override: $fragment);
			$hashAccumulator .= basename($file) . ':' . $content . "\n";
		}//end foreach

		if ($hashAccumulator === '') {
			return [$merged, ''];
		}

		return [$merged, substr(hash('sha256', $hashAccumulator), 0, 12)];
	}//end merge()

	/**
	 * Recursively deep-merge an override array onto a base array (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`)
	 * are merged key-by-key, recursing on shared keys; list arrays (numeric,
	 * sequential keys) are concatenated; scalar values from the override
	 * overwrite the base. Disjoint fragments therefore union cleanly without
	 * collision.
	 *
	 * The one exception is a `version` both sides give as a version string:
	 * the higher one stays. A schema's version is OpenRegister's per-schema
	 * import gate, so a fragment that sorts later with a lower version would
	 * otherwise lower it and keep an earlier fragment's properties from ever
	 * reaching an instance that has the higher one.
	 *
	 * @param array<int|string,mixed> $base The base array.
	 * @param array<int|string,mixed> $override The override array.
	 *
	 * @return array<int|string,mixed> The merged result.
	 */
	private function deepMerge(array $base, array $override): array {
		foreach ($override as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				if ($this->isList(array: $value) === true && $this->isList(array: $base[$key]) === true) {
					$base[$key] = array_merge($base[$key], $value);
					continue;
				}

				$base[$key] = $this->deepMerge(base: $base[$key], override: $value);
				continue;
			}

			if ($key === 'version' && $this->keepsHigherVersion(current: $base[$key] ?? null, incoming: $value) === true) {
				continue;
			}

			$base[$key] = $value;
		}//end foreach

		return $base;
	}//end deepMerge()

	/**
	 * Whether the version already merged is higher than the incoming one, so
	 * the incoming one must not replace it. Only two version strings
	 * (`1.29.0`) compare; anything else is overwritten as before.
	 *
	 * @param mixed $current The version merged so far, or null.
	 * @param mixed $incoming The fragment's version.
	 *
	 * @return bool True when the current version stays.
	 */
	private function keepsHigherVersion(mixed $current, mixed $incoming): bool {
		$pattern = '/^\d+(\.\d+)*$/';
		if (is_string($current) === false || is_string($incoming) === false
			|| preg_match($pattern, $current) !== 1 || preg_match($pattern, $incoming) !== 1
		) {
			return false;
		}

		return version_compare($current, $incoming, '>');
	}//end keepsHigherVersion()

	/**
	 * Determine whether an array is a sequential list (vs. an associative map).
	 *
	 * Backport of `array_is_list()` for portability across PHP runtimes.
	 *
	 * @param array<int|string,mixed> $array The array to inspect.
	 *
	 * @return bool True when the array has sequential integer keys from zero.
	 */
	private function isList(array $array): bool {
		if (function_exists('array_is_list') === true) {
			return array_is_list($array);
		}

		$expected = 0;
		foreach (array_keys($array) as $key) {
			if ($key !== $expected) {
				return false;
			}

			$expected++;
		}

		return true;
	}//end isList()
}//end class
