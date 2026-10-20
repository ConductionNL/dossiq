<?php

/**
 * The Zuiddrecht example set's Woo case "Verlichting fietspad Lindelaan" is
 * the case the DqZaak boards draw: in behandeling, with its contact moments.
 *
 * The boards measure every case-page screen against one in-progress case, and
 * DqZaakContact draws four contact moments on it (telephone, Mijn Zuiddrecht
 * twice, e-mail). The set seeds that case, so the screens have a reproducible
 * case to measure against.
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
 * @spec openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-example-woo-case-is-in-progress-with-its-contact-moments
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The seeded Lindelaan case: in-progress status, contact moments.
 *
 * @spec openspec/changes/example-set-lindelaan-in-progress/specs/example-set-lindelaan-in-progress/spec.md#requirement-the-example-woo-case-is-in-progress-with-its-contact-moments
 */
class ZuiddrechtWooLindelaanInProgressTest extends TestCase {

	/**
	 * The set's objects.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return (array)$set['objects'];
	}//end objects()

	/**
	 * The object with this key.
	 *
	 * @param string $key The object key.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function object(string $key): array {
		foreach ($this->objects() as $object) {
			if (($object['key'] ?? '') === $key) {
				return $object;
			}
		}

		$this->fail(message: "No object $key in the set");
	}//end object()

	/**
	 * The status names of the Woo case type with their role.
	 *
	 * @return array<string, string> Role by status name.
	 */
	private function wooStatusRoles(): array {
		$fragment = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/81-woo-verzoek.json'), true);
		$roles    = [];
		foreach ((array)$fragment['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'statusType') {
				$roles[(string)$object['name']] = (string)($object['role'] ?? '');
			}
		}

		return $roles;
	}//end wooStatusRoles()

	/**
	 * The case sits in a Woo status whose role is in-progress, and carries
	 * no publication state of a later stage.
	 *
	 * @return void
	 */
	public function testTheCaseIsInProgress(): void {
		$case   = $this->object(key: 'woo-lindelaan');
		$status = (string)$case['lookups']['status']['where']['name'];

		$this->assertSame(expected: 'in-progress', actual: $this->wooStatusRoles()[$status] ?? null, message: "$status is not an in-progress Woo status");
		$this->assertArrayNotHasKey(key: 'wooPublicationStatus', array: $case['data']);
		$this->assertSame(expected: '{{lookup:status}}', actual: $case['data']['statusHistory'][1]['status']);
	}//end testTheCaseIsInProgress()

	/**
	 * The four contact moments of DqZaakContact are on the case, after it,
	 * with the board's channels and directions.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheBoardsContactMoments(): void {
		$keys    = array_column($this->objects(), 'key');
		$caseAt  = array_search('woo-lindelaan', $keys, true);
		$moments = [];
		foreach ($this->objects() as $index => $object) {
			if (($object['schema'] ?? '') === 'contactmoment' && ($object['data']['case'] ?? '') === '{{object:woo-lindelaan.id}}') {
				$this->assertGreaterThan(expected: $caseAt, actual: $index, message: $object['key'].' comes before the case it points at');
				$moments[] = [$object['data']['notificationChannel'], $object['data']['direction']];
			}
		}

		$this->assertSame(
			expected: [['phone', 'inbound'], ['webformulier', 'outbound'], ['webformulier', 'inbound'], ['email', 'inbound']],
			actual: $moments
		);
	}//end testTheCaseCarriesTheBoardsContactMoments()

	/**
	 * Each contact moment uses values the contact moment schema allows.
	 *
	 * @return void
	 */
	public function testTheContactMomentsFitTheSchema(): void {
		$fragment   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/40-kcc-werkplek.json'), true);
		$properties = $fragment['components']['schemas']['contactmoment']['properties'];
		foreach ($this->objects() as $object) {
			if (($object['schema'] ?? '') !== 'contactmoment' || ($object['data']['case'] ?? '') !== '{{object:woo-lindelaan.id}}') {
				continue;
			}

			foreach (['notificationChannel', 'direction', 'nature'] as $field) {
				$this->assertContains(needle: $object['data'][$field], haystack: $properties[$field]['enum'], message: $object['key'].".$field");
			}
		}
	}//end testTheContactMomentsFitTheSchema()
	/**
	 * Seven documents are on the case through zaakinformatieobject rows, and
	 * four of them carry a verdict: "4 van 7 beoordeeld", with the board's
	 * tally (two public, one in part, one not).
	 *
	 * @return void
	 */
	public function testFourOfSevenDocumentsAreAssessed(): void {
		$documents   = [];
		$assessments = [];
		foreach ($this->objects() as $object) {
			$data = (array)($object['data'] ?? []);
			if (($object['schema'] ?? '') === 'zaakinformatieobject' && ($data['case'] ?? '') === '{{object:woo-lindelaan.id}}') {
				$documents[] = (string)$data['informatieobject'];
			}

			if (($object['schema'] ?? '') === 'wooDocumentAssessment' && ($data['caseRef'] ?? '') === '{{object:woo-lindelaan.id}}') {
				$assessments[(string)$data['documentRef']] = (string)$data['classification'];
			}
		}

		$this->assertCount(expectedCount: 7, haystack: array_unique($documents));
		$this->assertCount(expectedCount: 4, haystack: $assessments);
		$this->assertSame(expected: [], actual: array_diff(array_keys($assessments), $documents));
		$tally = array_count_values($assessments);
		ksort($tally);
		$this->assertSame(expected: ['deels_openbaar' => 1, 'niet_openbaar' => 1, 'openbaar' => 2], actual: $tally);

		foreach ($documents as $reference) {
			$key      = substr($reference, strlen('{{object:'), -strlen('.id}}'));
			$document = $this->object(key: $key);
			$this->assertSame(expected: 'informatieobject', actual: $document['schema']);
			$file = __DIR__.'/../../../lib/Settings/example-sets/'.$document['files'][0]['path'];
			$this->assertFileExists(filename: $file);
		}
	}//end testFourOfSevenDocumentsAreAssessed()

	/**
	 * No board draws a recorded decision or a notice of default on this case:
	 * DqZaakBesluiten draws the decision as a concept still to sign, and
	 * DqTermijnen draws its notices on other cases.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesNoDecisionOrNoticeOfDefault(): void {
		$keys = array_column($this->objects(), 'key');
		$this->assertNotContains(needle: 'woo-lindelaan-besluit', haystack: $keys);
		$this->assertNotContains(needle: 'ingebrekestelling-woo-lindelaan', haystack: $keys);
	}//end testTheCaseCarriesNoDecisionOrNoticeOfDefault()
}//end class
