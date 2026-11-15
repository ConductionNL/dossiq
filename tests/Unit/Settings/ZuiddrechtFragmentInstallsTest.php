<?php

/**
 * The Zuiddrecht fragment installs as written: OpenRegister's example-set install refuses the whole set when one
 * object refers to an object written after it, or holds a value its schema's type does not allow.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/example-set-installs-as-written/specs/example-set-installs-as-written/spec.md#requirement-the-zuiddrecht-fragment-passes-the-install-check
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Order and types of the Zuiddrecht fragment's objects.
 *
 * @spec openspec/changes/example-set-installs-as-written/specs/example-set-installs-as-written/spec.md#requirement-the-zuiddrecht-fragment-passes-the-install-check
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class ZuiddrechtFragmentInstallsTest extends TestCase {

	/**
	 * The fragment, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
	}//end fragment()

	/**
	 * An `{{object:key...}}` reference to an object of this fragment names one written before it: the installer
	 * resolves references in file order and refuses one it cannot resolve yet.
	 *
	 * @return void
	 */
	public function testAnObjectRefersOnlyToObjectsWrittenBeforeIt(): void {
		$objects = $this->fragment()['objects'];
		$own     = array_flip(array_column($objects, 'key'));
		$written = [];
		$late    = [];
		foreach ($objects as $object) {
			preg_match_all('/\{\{object:([a-z0-9-]+)[.}]/', (string)json_encode($object['data'] ?? []), $refs);
			foreach (array_unique($refs[1]) as $ref) {
				if (isset($own[$ref]) === true && isset($written[$ref]) === false) {
					$late[] = $object['key'].' -> '.$ref;
				}
			}

			$written[$object['key']] = true;
		}

		$this->assertSame(expected: [], actual: $late);
	}//end testAnObjectRefersOnlyToObjectsWrittenBeforeIt()

	/**
	 * A value the schema types as number, integer, string or boolean is of that type; null stays out unless the
	 * property is nullable (an absent property is how a record says "none").
	 *
	 * @return void
	 */
	public function testEveryValueHasTheTypeItsSchemaDeclares(): void {
		$schemas = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/dossiq_register.json'), true)['components']['schemas'];
		$wrong   = [];
		foreach ($this->fragment()['objects'] as $object) {
			if (($object['register'] ?? '') !== 'dossiq' || isset($schemas[$object['schema']]) === false) {
				continue;
			}

			$this->collectTypeErrors(data: $object['data'], properties: $schemas[$object['schema']]['properties'] ?? [], where: $object['key'], wrong: $wrong);
		}

		$this->assertSame(expected: [], actual: $wrong);
	}//end testEveryValueHasTheTypeItsSchemaDeclares()

	/**
	 * Every field a dossiq object writes is a property its schema declares, with register.d merged: OpenRegister
	 * drops an undeclared key on save, and the install reports it as written but not kept (:8080, 24 Oct: 18
	 * supplier descriptions).
	 *
	 * @return void
	 */
	public function testEveryFieldIsAPropertyItsSchemaDeclares(): void {
		$settings = __DIR__.'/../../../lib/Settings';
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: json_decode((string)file_get_contents($settings.'/dossiq_register.json'), true),
			fragmentDir: $settings.'/register.d'
		);
		$schemas    = $merged['components']['schemas'];
		$undeclared = [];
		foreach ($this->fragment()['objects'] as $object) {
			if (($object['register'] ?? '') !== 'dossiq' || isset($schemas[$object['schema']]) === false) {
				continue;
			}

			foreach (array_keys($object['data'] ?? []) as $field) {
				if (isset($schemas[$object['schema']]['properties'][$field]) === false) {
					$undeclared[] = $object['key'].'.'.$field;
				}
			}
		}

		$this->assertSame(expected: [], actual: $undeclared);
	}//end testEveryFieldIsAPropertyItsSchemaDeclares()

	/**
	 * The social-domain group has a member who is not in the sensitive-data group, so a walkthrough can show what a
	 * social-domain worker sees without the sensitive grant.
	 *
	 * @return void
	 */
	public function testTheSocialDomainGroupHasAWorkerWithoutTheSensitiveGrant(): void {
		$groups = array_column($this->fragment()['groups'], 'members', 'group');
		$this->assertNotSame(
			expected: [],
			actual: array_values(array_diff($groups['dossiq-sociaal-domein'] ?? [], $groups['dossiq-sensitive'] ?? []))
		);
	}//end testTheSocialDomainGroupHasAWorkerWithoutTheSensitiveGrant()

	/**
	 * Collect the fields whose value does not have the declared scalar type, walking nested objects.
	 *
	 * @param array<string, mixed> $data       The record's fields.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $where      The record key and path, for messages.
	 * @param list<string>         $wrong      The mismatches found so far.
	 *
	 * @return void
	 */
	private function collectTypeErrors(array $data, array $properties, string $where, array &$wrong): void {
		$checks = [
			'number'  => static fn (mixed $v): bool => is_int($v) === true || is_float($v) === true,
			'integer' => static fn (mixed $v): bool => is_int($v) === true,
			'boolean' => static fn (mixed $v): bool => is_bool($v) === true,
		];
		foreach ($data as $field => $value) {
			$property = $properties[$field] ?? null;
			if (is_array($property) === false) {
				continue;
			}

			$type = $property['type'] ?? null;
			if (is_string($type) === true && isset($checks[$type]) === true) {
				if ($value === null && ($property['nullable'] ?? false) !== true) {
					$wrong[] = $where.'.'.$field.' is null, the schema wants '.$type;
				} else if ($value !== null && is_string($value) === false && $checks[$type]($value) === false) {
					$wrong[] = $where.'.'.$field.' is not a '.$type;
				}
			}

			if (is_array($value) === true && isset($property['properties']) === true && array_is_list($value) === false) {
				$this->collectTypeErrors(data: $value, properties: $property['properties'], where: $where.'.'.$field, wrong: $wrong);
			}
		}
	}//end collectTypeErrors()
}//end class
