<?php

/**
 * The hash that says whether a shipped object is still what shipped.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Starter
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
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Starter;

/**
 * One hash of one object, ignoring the fields nobody edited.
 *
 * 🔑 THE IGNORE LIST IS WHAT MAKES THE ANSWER USABLE. OpenRegister stamps
 * `@self`, a numeric `version` and an `updated` timestamp on every write,
 * including the write the seed itself performs. Hashing those would make every
 * shipped object read as "changed here" the moment anything re-saved it, which
 * is the same as having no answer at all. The keys below are the ones the
 * platform owns; everything an administrator can type is hashed.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
final class ShippedFingerprint {

	/**
	 * The keys the platform writes, which an administrator never typed.
	 *
	 * @var array<int, string>
	 */
	public const IGNORED = [
		'@self',
		'id',
		'uuid',
		'version',
		'created',
		'updated',
		'published',
		'depublished',
	];

	/**
	 * The fingerprint of one object.
	 *
	 * @param array<string, mixed> $object The object as stored.
	 *
	 * @return string A hex sha256 of the object's authored content.
	 */
	public static function of(array $object): string {
		$canonical = self::canonical(value: $object);
		$encoded = json_encode($canonical, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			$encoded = serialize($canonical);
		}

		return hash('sha256', $encoded);
	}//end of()

	/**
	 * Whether an object still hashes to what shipped.
	 *
	 * @param array<string, mixed> $object      The object as stored.
	 * @param string               $fingerprint The fingerprint recorded at seed time.
	 *
	 * @return boolean True when the object is untouched.
	 */
	public static function matches(array $object, string $fingerprint): bool {
		if ($fingerprint === '') {
			return false;
		}

		return hash_equals($fingerprint, self::of(object: $object));
	}//end matches()

	/**
	 * The object with platform keys dropped and every map key sorted.
	 *
	 * Sorting matters: OpenRegister does not promise property order across a
	 * round trip, and an unsorted encode would hash two identical objects
	 * differently depending on which one came back from the database.
	 *
	 * @param mixed $value The value to canonicalise.
	 *
	 * @return mixed The canonical value.
	 */
	private static function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			if (is_string($key) === true && in_array($key, self::IGNORED, true) === true) {
				continue;
			}

			$out[$key] = self::canonical(value: $item);
		}

		if (array_is_list($out) === false) {
			ksort($out);
		}

		return $out;
	}//end canonical()
}//end class
