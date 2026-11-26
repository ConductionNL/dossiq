<?php
/**
 * Example data for DqZaakBetrokkenen: the share the Toegang card lists on the Lindelaan Woo case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-access-card-lists-team-and-group-grants/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Lindelaan Woo case is shared with Juridische zaken by Pieter Jansen, with an end date.
 */
class ZuiddrechtCaseGrantsTest extends TestCase {

	/**
	 * The fields OpenRegister lets an example set write on a grant (ExampleSetGrants::FIELDS, plus the row key).
	 */
	private const GRANT_FIELDS = ['object', 'type', 'shareWith', 'sharedBy', 'permissions', 'verbs', 'sharedAt', 'expiresAt'];

	/**
	 * The fragment with its parts' lists appended, as the loader reads it.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		$folder   = __DIR__.'/../../../lib/Settings/example-sets';
		$fragment = json_decode((string)file_get_contents($folder.'/zuiddrecht.json'), true);
		$parts    = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		sort($parts);
		foreach ($parts as $file) {
			foreach (json_decode((string)file_get_contents($file), true) as $key => $value) {
				if (is_array($value) === true && array_is_list($value) === true) {
					$fragment[$key] = array_merge(($fragment[$key] ?? []), $value);
				}
			}
		}

		return $fragment;

	}//end fragment()

	/**
	 * The grant with the given key.
	 *
	 * @param string $key The grant key.
	 *
	 * @return array<string, mixed> The grant row.
	 */
	private function grant(string $key): array {
		foreach (($this->fragment()['grants'] ?? []) as $grant) {
			if (($grant['key'] ?? '') === $key) {
				return $grant;
			}
		}

		$this->fail(message: 'No grant with key '.$key);

	}//end grant()

	/**
	 * Pieter shares the open Lindelaan Woo case with the group Juridische zaken, read only, 11 days ago, ending in 14 days.
	 *
	 * @return void
	 */
	public function testTheLindelaanCaseIsSharedWithJuridischeZakenByPieter(): void {
		$grant = $this->grant(key: 'woo-fietspad-juridische-zaken')['data'];

		$this->assertSame(expected: '{{object:woo-fietspad-besluitklaar.id}}', actual: $grant['object']);
		$this->assertSame(expected: 'group', actual: $grant['type']);
		$this->assertSame(expected: '{{cast:juridische-zaken.gid}}', actual: $grant['shareWith']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $grant['sharedBy']);
		$this->assertSame(expected: 1, actual: $grant['permissions']);
		$this->assertSame(expected: '{{datetime:-11 10:00}}', actual: $grant['sharedAt']);
		$this->assertSame(expected: '{{date:14}}', actual: $grant['expiresAt']);

	}//end testTheLindelaanCaseIsSharedWithJuridischeZakenByPieter()

	/**
	 * The shared case is the open Lindelaan Woo case Pieter handles, an object of this set.
	 *
	 * @return void
	 */
	public function testTheSharedObjectIsPietersOpenLindelaanWooCase(): void {
		$case = null;
		foreach ($this->fragment()['objects'] as $object) {
			if (($object['key'] ?? '') === 'woo-fietspad-besluitklaar') {
				$case = $object;
			}
		}

		$this->assertNotNull(actual: $case);
		$this->assertSame(expected: 'case', actual: $case['schema']);
		$this->assertStringContainsString(needle: 'Lindelaan', haystack: $case['data']['title']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $case['data']['assignee']);

	}//end testTheSharedObjectIsPietersOpenLindelaanWooCase()

	/**
	 * Every grant of the set names its required fields and only fields a set may write.
	 *
	 * @return void
	 */
	public function testEveryGrantHasOnlyFieldsASetMayWrite(): void {
		$grants = ($this->fragment()['grants'] ?? []);
		$this->assertNotSame(expected: [], actual: $grants);

		foreach ($grants as $grant) {
			// OpenRegister reads a grant as a keyed row, {key, data: {...}}
			// (ExampleSetFragmentShape::keyedRow), and refuses any other shape.
			$this->assertSame(expected: ['key', 'data'], actual: array_keys($grant));
			$this->assertNotSame(expected: '', actual: (string)$grant['key']);
			$this->assertIsArray(actual: $grant['data']);

			$data = $grant['data'];
			$this->assertSame(expected: [], actual: array_values(array_diff(array_keys($data), self::GRANT_FIELDS)), message: (string)$grant['key']);
			foreach (['object', 'type', 'shareWith', 'sharedBy'] as $required) {
				$this->assertNotSame(expected: '', actual: (string)($data[$required] ?? ''), message: $required);
			}

			$this->assertContains(needle: $data['type'], haystack: ['user', 'group']);
		}

	}//end testEveryGrantHasOnlyFieldsASetMayWrite()

	/**
	 * Every grant's object is owned by the account the grant names as its sharer.
	 *
	 * OpenRegister writes a grant while the sharer is the active user, and its
	 * sharing service lets only the owner or an administrator share. An object
	 * the set does not hand to the sharer is owned by whoever runs the install,
	 * so the grant was refused ("Only the owner or an administrator may change
	 * sharing on this object", live 25 and 26 Nov 2026).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zuiddrecht-example-set/tasks.md
	 */
	public function testEveryGrantedObjectIsOwnedByItsSharer(): void {
		$owners = [];
		foreach ($this->fragment()['objects'] as $object) {
			$owners[(string)($object['key'] ?? '')] = ($object['owner'] ?? null);
		}

		foreach (($this->fragment()['grants'] ?? []) as $grant) {
			$data = $grant['data'];
			$this->assertSame(expected: 1, actual: preg_match('/^\{\{object:([^.}]+)\.id\}\}$/', (string)$data['object'], $match), message: (string)$grant['key']);
			$this->assertArrayHasKey(key: $match[1], array: $owners, message: (string)$grant['key'].' names an object of this set');
			$this->assertSame(expected: $data['sharedBy'], actual: $owners[$match[1]], message: (string)$grant['key'].': the shared object must be owned by its sharer');
		}

	}//end testEveryGrantedObjectIsOwnedByItsSharer()
}//end class
