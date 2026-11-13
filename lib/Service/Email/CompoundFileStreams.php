<?php

/**
 * Dossiq Compound File Streams
 *
 * Reads the streams at the top of a Compound File Binary file, the container
 * Outlook saves a `.msg` in: a small file system of 512- or 4096-byte sectors,
 * chained through an allocation table, with a mini stream of 64-byte sectors
 * for every stream below the cutoff. Only the root storage's own streams are
 * read; a nested storage (an attachment, a recipient) is never entered, so a
 * stream name that also occurs inside one cannot be mistaken for the
 * message's own.
 *
 * Every chain walk is bounded by the number of sectors the file holds, so a
 * damaged or hostile file ends the read instead of looping.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * The root-level streams of a Compound File Binary file, by name.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */
final class CompoundFileStreams {

	/**
	 * The eight bytes every compound file starts with.
	 */
	private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

	/**
	 * Sector ids at or above this mark the end of a chain or a free sector.
	 */
	private const LAST_REGULAR_SECTOR = 0xFFFFFFFA;

	/**
	 * The no-entry marker in a directory sibling or child field.
	 */
	private const NO_STREAM = 0xFFFFFFFF;

	/**
	 * Directory entry type of a stream.
	 */
	private const TYPE_STREAM = 2;

	/**
	 * The file's bytes.
	 *
	 * @var string
	 */
	private string $bytes = '';

	/**
	 * The regular sector size in bytes.
	 *
	 * @var integer
	 */
	private int $sectorSize = 512;

	/**
	 * The mini sector size in bytes.
	 *
	 * @var integer
	 */
	private int $miniSectorSize = 64;

	/**
	 * Streams smaller than this live in the mini stream.
	 *
	 * @var integer
	 */
	private int $miniCutoff = 4096;

	/**
	 * The sector allocation table: the next sector of each sector.
	 *
	 * @var list<int>
	 */
	private array $fat = [];

	/**
	 * The mini sector allocation table.
	 *
	 * @var list<int>
	 */
	private array $miniFat = [];

	/**
	 * The mini stream's bytes, read from the root entry's chain.
	 *
	 * @var string
	 */
	private string $miniStream = '';

	/**
	 * The directory entries, each `{name, type, left, right, child, start, size}`.
	 *
	 * @var list<array{name: string, type: int, left: int, right: int, child: int, start: int, size: int}>
	 */
	private array $entries = [];

	/**
	 * Read the root-level streams of a compound file.
	 *
	 * @param string $bytes The file's bytes.
	 *
	 * @return array<string, string> Stream name to its bytes; empty when the bytes are no compound file or cannot be read.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function rootStreams(string $bytes): array {
		if (strlen($bytes) < 512 || str_starts_with($bytes, self::SIGNATURE) === false) {
			return [];
		}

		$this->bytes = $bytes;
		$this->fat = [];
		$this->miniFat = [];
		$this->miniStream = '';
		$this->entries = [];

		return $this->read();
	}//end rootStreams()

	/**
	 * Read the header, the tables and the directory, then the root's streams.
	 *
	 * @return array<string, string>
	 */
	private function read(): array {
		$this->sectorSize = (1 << $this->u16(offset: 0x1E));
		$this->miniSectorSize = (1 << $this->u16(offset: 0x20));
		if (in_array($this->sectorSize, [512, 4096], true) === false || $this->miniSectorSize !== 64) {
			return [];
		}

		$this->miniCutoff = $this->u32(offset: 0x38);
		$this->fat = $this->readFat();
		$this->entries = $this->readDirectory(firstSector: $this->u32(offset: 0x30));
		if ($this->entries === []) {
			return [];
		}

		$this->miniFat = $this->words(data: $this->chain(start: $this->u32(offset: 0x3C)));
		$root = $this->entries[0];
		$this->miniStream = substr($this->chain(start: $root['start']), 0, $root['size']);

		$streams = [];
		foreach ($this->rootChildren() as $index) {
			$entry = $this->entries[$index];
			if ($entry['type'] === self::TYPE_STREAM) {
				$streams[$entry['name']] = $this->streamOf(entry: $entry);
			}
		}

		return $streams;
	}//end read()

	/**
	 * The sector allocation table, from the header's list and the extra list sectors.
	 *
	 * @return list<int>
	 */
	private function readFat(): array {
		$fatSectors = [];
		for ($slot = 0; $slot < 109; $slot++) {
			$fatSectors[] = $this->u32(offset: (0x4C + ($slot * 4)));
		}

		$next = $this->u32(offset: 0x44);
		$perSector = (intdiv($this->sectorSize, 4) - 1);
		$guard = $this->sectorCount();
		while ($next < self::LAST_REGULAR_SECTOR && $guard-- > 0) {
			$sector = $this->sector(id: $next);
			$words = $this->words(data: $sector);
			$fatSectors = array_merge($fatSectors, array_slice($words, 0, $perSector));
			$next = ($words[$perSector] ?? self::NO_STREAM);
		}

		$fat = '';
		foreach ($fatSectors as $id) {
			if ($id < self::LAST_REGULAR_SECTOR) {
				$fat .= $this->sector(id: $id);
			}
		}

		return $this->words(data: $fat);
	}//end readFat()

	/**
	 * The directory entries along the directory chain.
	 *
	 * @param int $firstSector The first directory sector.
	 *
	 * @return list<array{name: string, type: int, left: int, right: int, child: int, start: int, size: int}>
	 */
	private function readDirectory(int $firstSector): array {
		$data = $this->chain(start: $firstSector);
		$entries = [];
		for ($offset = 0; ($offset + 128) <= strlen($data); $offset += 128) {
			$nameLength = min(64, unpack('v', substr($data, ($offset + 0x40), 2))[1]);
			$name = substr($data, $offset, max(0, ($nameLength - 2)));
			$entries[] = [
				'name' => (string)mb_convert_encoding($name, 'UTF-8', 'UTF-16LE'),
				'type' => ord($data[($offset + 0x42)]),
				'left' => unpack('V', substr($data, ($offset + 0x44), 4))[1],
				'right' => unpack('V', substr($data, ($offset + 0x48), 4))[1],
				'child' => unpack('V', substr($data, ($offset + 0x4C), 4))[1],
				'start' => unpack('V', substr($data, ($offset + 0x74), 4))[1],
				'size' => unpack('V', substr($data, ($offset + 0x78), 4))[1],
			];
		}

		return $entries;
	}//end readDirectory()

	/**
	 * The indexes of the root storage's direct children, walking its sibling tree.
	 *
	 * @return list<int>
	 */
	private function rootChildren(): array {
		$found = [];
		$pending = [$this->entries[0]['child']];
		$guard = count($this->entries);
		while ($pending !== [] && $guard-- >= 0) {
			$index = array_pop($pending);
			if ($index === self::NO_STREAM || isset($this->entries[$index]) === false || isset($found[$index]) === true) {
				continue;
			}

			$found[$index] = true;
			$pending[] = $this->entries[$index]['left'];
			$pending[] = $this->entries[$index]['right'];
		}

		return array_keys($found);
	}//end rootChildren()

	/**
	 * A stream's bytes, from the mini stream when it is small, else from the sectors.
	 *
	 * @param array{name: string, type: int, left: int, right: int, child: int, start: int, size: int} $entry The stream entry.
	 *
	 * @return string
	 */
	private function streamOf(array $entry): string {
		if ($entry['size'] < $this->miniCutoff) {
			return substr($this->miniChain(start: $entry['start']), 0, $entry['size']);
		}

		return substr($this->chain(start: $entry['start']), 0, $entry['size']);
	}//end streamOf()

	/**
	 * The bytes of a regular sector chain.
	 *
	 * @param int $start The first sector.
	 *
	 * @return string
	 */
	private function chain(int $start): string {
		$data = '';
		$guard = $this->sectorCount();
		for ($id = $start; $id < self::LAST_REGULAR_SECTOR && $guard-- > 0; $id = ($this->fat[$id] ?? self::NO_STREAM)) {
			$data .= $this->sector(id: $id);
		}

		return $data;
	}//end chain()

	/**
	 * The bytes of a mini sector chain.
	 *
	 * @param int $start The first mini sector.
	 *
	 * @return string
	 */
	private function miniChain(int $start): string {
		$data = '';
		$guard = count($this->miniFat);
		for ($id = $start; $id < self::LAST_REGULAR_SECTOR && $guard-- >= 0; $id = ($this->miniFat[$id] ?? self::NO_STREAM)) {
			$data .= substr($this->miniStream, ($id * $this->miniSectorSize), $this->miniSectorSize);
		}

		return $data;
	}//end miniChain()

	/**
	 * One regular sector's bytes; '' past the end of the file.
	 *
	 * @param int $id The sector id.
	 *
	 * @return string
	 */
	private function sector(int $id): string {
		return substr($this->bytes, (($id + 1) * $this->sectorSize), $this->sectorSize);
	}//end sector()

	/**
	 * How many regular sectors the file holds, the bound of every chain walk.
	 *
	 * @return int
	 */
	private function sectorCount(): int {
		return intdiv(strlen($this->bytes), $this->sectorSize);
	}//end sectorCount()

	/**
	 * Little-endian 32-bit words.
	 *
	 * @param string $data The bytes.
	 *
	 * @return list<int>
	 */
	private function words(string $data): array {
		$usable = (strlen($data) - (strlen($data) % 4));
		if ($usable === 0) {
			return [];
		}

		return array_values(unpack('V*', substr($data, 0, $usable)));
	}//end words()

	/**
	 * A little-endian 16-bit value from the header.
	 *
	 * @param int $offset The offset.
	 *
	 * @return int
	 */
	private function u16(int $offset): int {
		return unpack('v', substr($this->bytes, $offset, 2))[1];
	}//end u16()

	/**
	 * A little-endian 32-bit value from the header.
	 *
	 * @param int $offset The offset.
	 *
	 * @return int
	 */
	private function u32(int $offset): int {
		return unpack('V', substr($this->bytes, $offset, 4))[1];
	}//end u32()
}//end class
