<?php

/**
 * Procest register fragment merger (ADR-037).
 *
 * Deep-merges the modular register fragments in `lib/Settings/register.d/*.json`
 * onto the `procest_register.json` monolith, so concurrent same-app builds can
 * add registers and schemas via isolated fragment files instead of all editing
 * one file and conflicting.
 *
 * Also fingerprints the applied fragment set, so adding, changing or removing a
 * fragment changes the import version — which is OpenRegister's idempotency key,
 * and therefore the only thing that forces a re-import.
 *
 * Split out of {@see \OCA\Procest\Service\SettingsService}: merging is a pure
 * data transformation with no config, no container and no logging, and both of
 * its callers only need the merged result.
 *
 * @category Service
 * @package  OCA\Procest\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Settings;

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

			$base[$key] = $value;
		}//end foreach

		return $base;
	}//end deepMerge()

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
