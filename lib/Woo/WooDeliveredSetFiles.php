<?php

/**
 * Dossiq Woo delivered set files
 *
 * The two files of one item of a delivered set, for the compare dialog
 * (woo-delivered-set-is-a-record REQ-WDS-004): the original as it came in and
 * the file that went out. Both are read through the same document loader the
 * publish and the re-verification use, so the compare shows the bytes the
 * hashes are about.
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
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * Reads the original and the delivered file of one set item.
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
 */
class WooDeliveredSetFiles {

	/**
	 * The two sides of an item, in reading order.
	 *
	 * @var array<int, string>
	 */
	public const SIDES = ['original', 'delivered'];

	/**
	 * MIME types by extension, for a document that names no format.
	 *
	 * @var array<string, string>
	 */
	private const MIME_BY_EXTENSION = [
		'pdf'  => 'application/pdf',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'txt'  => 'text/plain',
		'eml'  => 'message/rfc822',
	];

	/**
	 * Constructor.
	 *
	 * @param WooCaseDocuments $documents Reads a document with its file bytes.
	 */
	public function __construct(
		private readonly WooCaseDocuments $documents,
	) {
	}//end __construct()

	/**
	 * Name and type of both files of one item, or null when the set has no such item.
	 *
	 * @param array<string, mixed> $set   The stored set.
	 * @param int                  $index The item's position in `items`.
	 *
	 * @return array<string, array{fileName: string, mimeType: string, readable: bool}>|null
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
	 */
	public function describe(array $set, int $index): ?array {
		$item = $this->item(set: $set, index: $index);
		if ($item === null) {
			return null;
		}

		$sides = [];
		foreach (self::SIDES as $side) {
			$file = $this->load(item: $item, side: $side);
			$sides[$side] = [
				'fileName' => $file['fileName'],
				'mimeType' => $file['mimeType'],
				'readable' => $file['bytes'] !== null,
			];
		}

		return $sides;
	}//end describe()

	/**
	 * The bytes, name and type of one side of one item, or null when there is nothing to read.
	 *
	 * @param array<string, mixed> $set   The stored set.
	 * @param int                  $index The item's position in `items`.
	 * @param string               $side  `original` or `delivered`.
	 *
	 * @return array{bytes: string, fileName: string, mimeType: string}|null
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-the-delivered-rendition-is-compared-with-its-original-req-wds-004
	 */
	public function read(array $set, int $index, string $side): ?array {
		$item = $this->item(set: $set, index: $index);
		if ($item === null || in_array($side, self::SIDES, true) === false) {
			return null;
		}

		$file = $this->load(item: $item, side: $side);
		if ($file['bytes'] === null) {
			return null;
		}

		return ['bytes' => $file['bytes'], 'fileName' => $file['fileName'], 'mimeType' => $file['mimeType']];
	}//end read()

	/**
	 * One item of the set, or null.
	 *
	 * @param array<string, mixed> $set   The stored set.
	 * @param int                  $index The position.
	 *
	 * @return array<string, mixed>|null
	 */
	private function item(array $set, int $index): ?array {
		$items = $set['items'] ?? [];
		if (is_array($items) === false || $index < 0 || isset($items[$index]) === false || is_array($items[$index]) === false) {
			return null;
		}

		return $items[$index];
	}//end item()

	/**
	 * Load one side of an item through the document loader.
	 *
	 * @param array<string, mixed> $item The item.
	 * @param string               $side `original` or `delivered`.
	 *
	 * @return array{bytes: ?string, fileName: string, mimeType: string}
	 */
	private function load(array $item, string $side): array {
		$ref = (string)($item[$side . 'Ref'] ?? '');
		$document = null;
		if ($ref !== '') {
			$document = $this->documents->load(documentId: $ref);
		}

		$fileName = (string)($document['fileName'] ?? ($document['title'] ?? ''));
		if ($fileName === '' && $side === 'delivered') {
			$fileName = (string)($item['fileName'] ?? '');
		}

		if ($fileName === '') {
			$fileName = $side;
		}

		$bytes = null;
		if (is_array($document) === true && empty($document['content']) === false) {
			$decoded = base64_decode((string)$document['content'], true);
			if ($decoded !== false) {
				$bytes = $decoded;
			}
		}

		return ['bytes' => $bytes, 'fileName' => $fileName, 'mimeType' => $this->mimeType(document: $document, fileName: $fileName)];
	}//end load()

	/**
	 * The document's own format, else a type from the file name's extension.
	 *
	 * @param array<string, mixed>|null $document The document row.
	 * @param string                    $fileName The file name.
	 *
	 * @return string
	 */
	private function mimeType(?array $document, string $fileName): string {
		$format = (string)($document['format'] ?? '');
		if ($format !== '') {
			return $format;
		}

		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		return self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream';
	}//end mimeType()
}//end class
