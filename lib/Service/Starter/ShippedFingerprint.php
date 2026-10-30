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
class ShippedFingerprint {

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
	 * `hashOf` rather than `of`: a two-letter method name says nothing at the
	 * call site, and the analyser is right to refuse it.
	 *
	 * @param array<string, mixed> $object The object as stored.
	 *
	 * @return string A hex sha256 of the object's authored content.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function hashOf(array $object): string {
		$canonical = $this->canonical(value: $object);
		$encoded = json_encode($canonical, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			$encoded = serialize($canonical);
		}

		return hash('sha256', $encoded);
	}//end hashOf()

	/**
	 * Whether an object still hashes to what shipped.
	 *
	 * @param array<string, mixed> $object      The object as stored.
	 * @param string               $fingerprint The fingerprint recorded at seed time.
	 *
	 * @return boolean True when the object is untouched.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function matches(array $object, string $fingerprint): bool {
		if ($fingerprint === '') {
			return false;
		}

		return hash_equals($fingerprint, $this->hashOf(object: $object));
	}//end matches()

	/**
	 * Whether a shipped object is untouched: the whole object still hashes to
	 * its stamp, or, given the keys a package ships, those keys still hash to
	 * the row as it shipped, or, given when it was stamped, the store has not
	 * saved it since. The last holds when the hash moved only because the
	 * schema gained a property that the store now reads back on every row.
	 *
	 * @param array<string, mixed> $object             The object as stored, with its `@self`.
	 * @param string               $fingerprint        The stamp of the object as stored.
	 * @param string               $shippedFingerprint The stamp of the row as it shipped.
	 * @param array<int, string>   $shippedKeys        The keys the package ships; none skips the second check.
	 * @param string               $stampedAt          When the stamp was written; empty skips the third check.
	 *
	 * @return boolean True when untouched.
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function untouched(
		array $object,
		string $fingerprint,
		string $shippedFingerprint,
		array $shippedKeys = [],
		string $stampedAt = '',
	): bool {
		if ($this->matches(object: $object, fingerprint: $fingerprint) === true) {
			return true;
		}

		if ($shippedKeys !== [] && $this->matchesShipped(object: $object, fingerprint: $shippedFingerprint, shippedKeys: $shippedKeys) === true) {
			return true;
		}

		return $fingerprint !== '' && $this->notSavedSince(object: $object, stampedAt: $stampedAt);
	}//end untouched()

	/**
	 * Whether an object still hashes to what shipped, read only over the keys
	 * a package ships that the object holds a value for.
	 *
	 * A stored row carries more than the package wrote: OpenRegister fills
	 * every declared property, so the whole row never hashes to the shipped
	 * row. Over the shipped keys it does, while nobody edited them. A key a
	 * newer version adds is still empty here, so it drops out; a key somebody
	 * filled in here counts, and reads as changed.
	 *
	 * @param array<string, mixed> $object      The object as stored.
	 * @param string               $fingerprint The fingerprint recorded for the shipped row.
	 * @param array<int, string>   $shippedKeys The keys the package ships.
	 *
	 * @return boolean True when the shipped keys are untouched.
	 *
	 * @spec openspec/changes/package-upgrade-refreshes-untouched-rows/specs/case-types/spec.md
	 */
	public function matchesShipped(array $object, string $fingerprint, array $shippedKeys): bool {
		$projected = [];
		foreach ($shippedKeys as $key) {
			if (array_key_exists($key, $object) === true && $object[$key] !== null && $object[$key] !== '' && $object[$key] !== []) {
				$projected[$key] = $object[$key];
			}
		}

		return $this->matches(object: $projected, fingerprint: $fingerprint);
	}//end matchesShipped()

	/**
	 * Whether the store has not saved an object since a moment, read from the
	 * object's `@self.updated`. An object without that date has no answer, so
	 * it reads as saved.
	 *
	 * @param array<string, mixed> $object    The object as stored, with its `@self`.
	 * @param string               $stampedAt The moment, as an ISO 8601 date.
	 *
	 * @return boolean True when the object's last save is not later than the moment.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function notSavedSince(array $object, string $stampedAt): bool {
		$self = ($object['@self'] ?? []);
		$savedAt = false;
		if (is_array($self) === true) {
			$savedAt = strtotime((string)($self['updated'] ?? ''));
		}

		$since = strtotime($stampedAt);

		return $savedAt !== false && $since !== false && $savedAt <= $since;
	}//end notSavedSince()

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
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			if (is_string($key) === true && in_array($key, self::IGNORED, true) === true) {
				continue;
			}

			$out[$key] = $this->canonical(value: $item);
		}

		if (array_is_list($out) === false) {
			ksort($out);
		}

		return $out;
	}//end canonical()
}//end class
