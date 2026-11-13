<?php

/**
 * Compound File Builder
 *
 * Writes a small Compound File Binary file the way Outlook lays out a `.msg`:
 * a 512-byte-sector file with one allocation sector, a directory, a mini
 * allocation table and a mini stream holding every stream below 4096 bytes.
 * Streams at the top go under the root; a nested storage can be added with
 * streams of its own, to prove a reader never takes those for the root's.
 *
 * Test support only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

/**
 * Builds a compound file from named streams.
 */
final class CompoundFileBuilder {

	private const END = 0xFFFFFFFE;
	private const FREE = 0xFFFFFFFF;
	private const FAT_SECTOR = 0xFFFFFFFD;
	private const NO_STREAM = 0xFFFFFFFF;

	/**
	 * A `.msg` holding the given message properties as Unicode string streams.
	 *
	 * @param array<string, string> $properties Four hex digits of the tag to the value.
	 * @param array<string, string> $nested     Streams of a nested storage (an attachment).
	 *
	 * @return string The file's bytes.
	 */
	public static function msg(array $properties, array $nested = []): string {
		$streams = [];
		foreach ($properties as $tag => $value) {
			$streams['__substg1.0_' . $tag . '001F'] = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
		}

		return self::build(rootStreams: $streams, nestedStreams: $nested);
	}//end msg()

	/**
	 * A compound file with the given root streams and, optionally, one nested storage.
	 *
	 * @param array<string, string> $rootStreams   Root stream name to bytes, each below 4096 bytes.
	 * @param array<string, string> $nestedStreams Streams of the nested storage `__attach_version1.0_#00000000`.
	 *
	 * @return string The file's bytes.
	 */
	public static function build(array $rootStreams, array $nestedStreams = []): string {
		$entries = [['name' => 'Root Entry', 'type' => 5, 'left' => self::NO_STREAM, 'right' => self::NO_STREAM, 'child' => self::NO_STREAM, 'start' => self::END, 'size' => 0]];
		$miniStream = '';
		$miniFat = [];
		$addStream = static function (string $name, string $bytes) use (&$entries, &$miniStream, &$miniFat): int {
			$first = intdiv(strlen($miniStream), 64);
			$count = max(1, (int)ceil(strlen($bytes) / 64));
			for ($i = 0; $i < $count; $i++) {
				$miniFat[] = ($i === ($count - 1)) ? self::END : ($first + $i + 1);
			}

			$miniStream .= str_pad($bytes, ($count * 64), "\0");
			$entries[] = ['name' => $name, 'type' => 2, 'left' => self::NO_STREAM, 'right' => self::NO_STREAM, 'child' => self::NO_STREAM, 'start' => $first, 'size' => strlen($bytes)];
			return (count($entries) - 1);
		};

		$rootChildren = [];
		foreach ($rootStreams as $name => $bytes) {
			$rootChildren[] = $addStream($name, $bytes);
		}

		if ($nestedStreams !== []) {
			$entries[] = ['name' => '__attach_version1.0_#00000000', 'type' => 1, 'left' => self::NO_STREAM, 'right' => self::NO_STREAM, 'child' => self::NO_STREAM, 'start' => 0, 'size' => 0];
			$storage = (count($entries) - 1);
			$rootChildren[] = $storage;
			$nestedChildren = [];
			foreach ($nestedStreams as $name => $bytes) {
				$nestedChildren[] = $addStream($name, $bytes);
			}

			$entries[$storage]['child'] = self::siblings(entries: $entries, children: $nestedChildren);
		}

		$entries[0]['child'] = self::siblings(entries: $entries, children: $rootChildren);

		// Sector 0 is the allocation table, then the directory, the mini table and the mini stream.
		$directory = '';
		foreach ($entries as $entry) {
			$directory .= self::entry(entry: $entry);
		}

		$directory = self::pad(data: $directory);
		$miniFatBytes = self::pad(data: pack('V*', ...($miniFat === [] ? [self::END] : $miniFat)));
		$miniStreamBytes = self::pad(data: $miniStream);

		$dirStart = 1;
		$dirCount = intdiv(strlen($directory), 512);
		$miniFatStart = ($dirStart + $dirCount);
		$miniFatCount = intdiv(strlen($miniFatBytes), 512);
		$miniStart = ($miniFatStart + $miniFatCount);
		$miniCount = intdiv(strlen($miniStreamBytes), 512);

		$fat = [self::FAT_SECTOR];
		foreach ([[$dirStart, $dirCount], [$miniFatStart, $miniFatCount], [$miniStart, $miniCount]] as [$start, $count]) {
			for ($i = 0; $i < $count; $i++) {
				$fat[] = ($i === ($count - 1)) ? self::END : ($start + $i + 1);
			}
		}

		$fat = array_pad($fat, 128, self::FREE);

		// The root entry carries the mini stream.
		$entries[0]['start'] = ($miniCount > 0) ? $miniStart : self::END;
		$entries[0]['size'] = strlen($miniStream);
		$directory = substr_replace($directory, self::entry(entry: $entries[0]), 0, 128);

		$header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16)
			. pack('v', 0x3E) . pack('v', 3) . pack('v', 0xFFFE) . pack('v', 9) . pack('v', 6)
			. str_repeat("\0", 6) . pack('V', 0) . pack('V', 1) . pack('V', $dirStart) . pack('V', 0)
			. pack('V', 4096) . pack('V', $miniFatStart) . pack('V', $miniFatCount)
			. pack('V', self::END) . pack('V', 0) . pack('V', 0) . str_repeat(pack('V', self::FREE), 108);

		return $header . pack('V*', ...$fat) . $directory . $miniFatBytes . $miniStreamBytes;
	}//end build()

	/**
	 * Hang children off the middle one: the earlier ones down its left side, the later ones down its right.
	 *
	 * A reader that walked only one side would miss half of them.
	 *
	 * @param array<int, array<string, mixed>> $entries  The entries, changed in place.
	 * @param list<int>                        $children The child indexes.
	 *
	 * @return int The middle child, or the no-stream marker.
	 */
	private static function siblings(array &$entries, array $children): int {
		if ($children === []) {
			return self::NO_STREAM;
		}

		$middle = intdiv(count($children), 2);
		foreach ($children as $position => $index) {
			if ($position <= $middle) {
				$entries[$index]['left'] = ($children[($position - 1)] ?? self::NO_STREAM);
			}

			if ($position >= $middle) {
				$entries[$index]['right'] = ($children[($position + 1)] ?? self::NO_STREAM);
			}
		}

		return $children[$middle];
	}//end siblings()

	/**
	 * One 128-byte directory entry.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string
	 */
	private static function entry(array $entry): string {
		$name = mb_convert_encoding((string)$entry['name'], 'UTF-16LE', 'UTF-8') . "\0\0";
		return str_pad($name, 64, "\0")
			. pack('v', strlen($name)) . chr((int)$entry['type']) . chr(1)
			. pack('V', $entry['left']) . pack('V', $entry['right']) . pack('V', $entry['child'])
			. str_repeat("\0", 36)
			. pack('V', $entry['start']) . pack('V', $entry['size']) . pack('V', 0);
	}//end entry()

	/**
	 * Pad to whole 512-byte sectors.
	 *
	 * @param string $data The bytes.
	 *
	 * @return string
	 */
	private static function pad(string $data): string {
		$size = max(512, (int)(ceil(strlen($data) / 512) * 512));
		return str_pad($data, $size, "\0");
	}//end pad()
}//end class
