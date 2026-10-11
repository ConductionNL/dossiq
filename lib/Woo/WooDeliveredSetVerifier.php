<?php

/**
 * Dossiq Woo delivered set verifier
 *
 * A delivered set is re-verifiable (woo-delivered-set-is-a-record
 * REQ-WDS-003): the hash of every delivered file is computed again from its
 * bytes, and the set hash from those. A file that cannot be read is
 * `missing`, never `match`.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Recomputes the hashes of a delivered set.
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 */
class WooDeliveredSetVerifier {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settings  Bridge to OpenRegister.
	 * @param WooDeliveredSetWriter $sets      The hash rule and the set schema.
	 * @param WooCaseDocuments      $documents Reads a document with its file bytes.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly WooDeliveredSetWriter $sets,
		private readonly WooCaseDocuments $documents,
	) {
	}//end __construct()

	/**
	 * The stored set, or null when it does not exist.
	 *
	 * @param string $setId The set.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	public function find(string $setId): ?array {
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		if ($objectService === null || $register === '' || $setId === '') {
			return null;
		}

		$schema = (string)$this->settings->getConfigValue(WooDeliveredSetWriter::CONFIG_KEY);
		if ($schema === '') {
			$schema = WooDeliveredSetWriter::SCHEMA_SLUG;
		}

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $setId);
	}//end find()

	/**
	 * Verify a set against the files as they are now.
	 *
	 * @param array<string, mixed> $set The stored set.
	 *
	 * @return array{verified: bool, setHash: array{expected: string, actual: string}, items: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	public function verify(array $set): array {
		$items = [];
		$actualItems = [];
		$verified = true;
		foreach ((array)($set['items'] ?? []) as $item) {
			$ref = (string)($item['deliveredRef'] ?? '');
			$expected = (string)($item['sha256'] ?? '');
			$actual = $this->hashOf(documentRef: $ref);
			$status = 'missing';
			if ($actual !== '') {
				$status = 'changed';
				if (hash_equals($expected, $actual) === true) {
					$status = 'match';
				}
			}

			$verified = ($verified === true && $status === 'match');
			$items[] = ['deliveredRef' => $ref, 'expected' => $expected, 'actual' => $actual, 'status' => $status];
			$actualItems[] = ['deliveredRef' => $ref, 'sha256' => $actual];
		}

		$expectedSetHash = (string)($set['setHash'] ?? '');
		$actualSetHash = $this->sets->setHash(items: $actualItems);

		return [
			'verified' => ($verified === true && hash_equals($expectedSetHash, $actualSetHash) === true),
			'setHash' => ['expected' => $expectedSetHash, 'actual' => $actualSetHash],
			'items' => $items,
		];
	}//end verify()

	/**
	 * The SHA-256 of a document's bytes, or '' when they cannot be read.
	 *
	 * @param string $documentRef The document.
	 *
	 * @return string
	 */
	private function hashOf(string $documentRef): string {
		if ($documentRef === '') {
			return '';
		}

		$document = $this->documents->load(documentId: $documentRef);
		$content = (string)($document['content'] ?? '');
		if ($content === '') {
			return '';
		}

		$bytes = base64_decode($content, true);
		if ($bytes === false) {
			return '';
		}

		return hash('sha256', $bytes);
	}//end hashOf()
}//end class
