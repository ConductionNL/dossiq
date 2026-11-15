<?php

/**
 * Reading the root-level streams of a Compound File Binary file directly:
 * the header checks, both chain kinds, the extra allocation list and the
 * bounds that end a walk over a damaged file.
 *
 * Every file starts from the builder's well-formed `.msg` layout and changes
 * the header or a table entry in place, so each test names the one field it
 * damages.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CompoundFileStreams;
use OCA\Dossiq\Tests\Support\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\CompoundFileStreams
 */
class CompoundFileStreamsTest extends TestCase {

	/**
	 * The end-of-chain marker.
	 */
	private const END = 0xFFFFFFFE;

	/**
	 * The free-sector marker.
	 */
	private const FREE = 0xFFFFFFFF;

	/**
	 * Where the builder's directory starts: the header, then the allocation sector.
	 */
	private const DIRECTORY = 1024;

	/**
	 * A 32-bit little-endian value from the bytes.
	 *
	 * @param string  $bytes  The file.
	 * @param integer $offset The offset.
	 *
	 * @return integer
	 */
	private static function u32(string $bytes, int $offset): int {
		return unpack('V', substr($bytes, $offset, 4))[1];
	}//end u32()

	/**
	 * The bytes with a 32-bit little-endian value written at the offset.
	 *
	 * @param string  $bytes  The file.
	 * @param integer $offset The offset.
	 * @param integer $value  The value.
	 *
	 * @return string
	 */
	private static function put32(string $bytes, int $offset, int $value): string {
		return substr_replace($bytes, pack('V', $value), $offset, 4);
	}//end put32()

	/**
	 * The file offset of mini allocation table entry $index.
	 *
	 * @param string  $bytes The file.
	 * @param integer $index The mini sector.
	 *
	 * @return integer
	 */
	private static function miniFatEntry(string $bytes, int $index): int {
		return (((self::u32(bytes: $bytes, offset: 0x3C) + 1) * 512) + ($index * 4));
	}//end miniFatEntry()

	/**
	 * The streams, sorted by name, so the walk order does not matter.
	 *
	 * @param array<string, string> $streams The streams.
	 *
	 * @return array<string, string>
	 */
	private static function sorted(array $streams): array {
		ksort($streams);
		return $streams;
	}//end sorted()

	/**
	 * Every root stream comes back under its name with its exact bytes, from both sides of the sibling tree.
	 *
	 * @return void
	 */
	public function testEveryRootStreamIsReadByName(): void {
		$streams = [
			'Alpha' => 'aaa',
			'Beta' => str_repeat('b', 100),
			'Gamma' => 'g',
			'Delta' => str_repeat('d', 64),
		];

		$read = (new CompoundFileStreams())->rootStreams(bytes: CompoundFileBuilder::build(rootStreams: $streams));

		self::assertSame(self::sorted(streams: $streams), self::sorted(streams: $read));
	}//end testEveryRootStreamIsReadByName()

	/**
	 * A nested storage is listed under the root but never entered.
	 *
	 * @return void
	 */
	public function testANestedStoragesStreamsAreNotTheRoots(): void {
		$bytes = CompoundFileBuilder::build(rootStreams: ['Subject' => 'root'], nestedStreams: ['Subject2' => 'inner', 'Other' => 'x']);

		self::assertSame(['Subject' => 'root'], (new CompoundFileStreams())->rootStreams(bytes: $bytes));
	}//end testANestedStoragesStreamsAreNotTheRoots()

	/**
	 * Bytes that are too short or lack the signature are no compound file.
	 *
	 * @return void
	 */
	public function testBytesThatAreNoCompoundFileHaveNoStreams(): void {
		$reader = new CompoundFileStreams();
		$valid = CompoundFileBuilder::build(rootStreams: ['A' => 'a']);

		self::assertSame([], $reader->rootStreams(bytes: ''));
		self::assertSame([], $reader->rootStreams(bytes: substr($valid, 0, 511)));
		self::assertSame([], $reader->rootStreams(bytes: 'X' . substr($valid, 1)));
	}//end testBytesThatAreNoCompoundFileHaveNoStreams()

	/**
	 * A sector size other than 512 or 4096, or a mini sector other than 64, is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownSectorSizeIsRefused(): void {
		$reader = new CompoundFileStreams();
		$valid = CompoundFileBuilder::build(rootStreams: ['A' => 'a']);

		self::assertSame([], $reader->rootStreams(bytes: substr_replace($valid, pack('v', 10), 0x1E, 2)));
		self::assertSame([], $reader->rootStreams(bytes: substr_replace($valid, pack('v', 7), 0x20, 2)));
	}//end testAnUnknownSectorSizeIsRefused()

	/**
	 * A directory chain that starts nowhere leaves nothing to read.
	 *
	 * @return void
	 */
	public function testAnEmptyDirectoryHasNoStreams(): void {
		$bytes = self::put32(bytes: CompoundFileBuilder::build(rootStreams: ['A' => 'a']), offset: 0x30, value: self::END);

		self::assertSame([], (new CompoundFileStreams())->rootStreams(bytes: $bytes));
	}//end testAnEmptyDirectoryHasNoStreams()

	/**
	 * A stream at the mini cutoff is read from the regular sectors, not the mini stream.
	 *
	 * The mini stream keeps a same-length decoy under the stream's name; the
	 * real bytes sit in one regular sector appended to the file, which the
	 * entry is pointed at once the cutoff is lowered to the stream's size.
	 *
	 * @return void
	 */
	public function testAStreamAtTheCutoffIsReadFromRegularSectors(): void {
		$payload = 'Message-ID: <a@b>';
		$bytes = CompoundFileBuilder::build(rootStreams: ['Headers' => str_repeat('x', strlen($payload))]);
		$appended = (intdiv(strlen($bytes), 512) - 1);

		$bytes = self::put32(bytes: $bytes, offset: 0x38, value: strlen($payload));
		$bytes = self::put32(bytes: $bytes, offset: (512 + ($appended * 4)), value: self::END);
		$bytes = self::put32(bytes: $bytes, offset: (self::DIRECTORY + 128 + 0x74), value: $appended);
		$bytes .= str_pad($payload, 512, "\0");

		self::assertSame(['Headers' => $payload], (new CompoundFileStreams())->rootStreams(bytes: $bytes));
	}//end testAStreamAtTheCutoffIsReadFromRegularSectors()

	/**
	 * An allocation sector listed only in the extra list sectors is still found.
	 *
	 * The header's own list is emptied and the allocation sector moved into
	 * one extra list sector appended to the file.
	 *
	 * @return void
	 */
	public function testAnAllocationSectorFromTheExtraListIsUsed(): void {
		$streams = ['Alpha' => 'one', 'Beta' => str_repeat('two', 30)];
		$bytes = CompoundFileBuilder::build(rootStreams: $streams);
		$extraSector = (intdiv(strlen($bytes), 512) - 1);

		$bytes = self::put32(bytes: $bytes, offset: 0x4C, value: self::FREE);
		$bytes = self::put32(bytes: $bytes, offset: 0x44, value: $extraSector);
		$bytes = self::put32(bytes: $bytes, offset: 0x48, value: 1);
		$bytes .= pack('V', 0) . str_repeat(pack('V', self::FREE), 126) . pack('V', self::END);

		self::assertSame(self::sorted(streams: $streams), self::sorted(streams: (new CompoundFileStreams())->rootStreams(bytes: $bytes)));
	}//end testAnAllocationSectorFromTheExtraListIsUsed()

	/**
	 * Without a mini allocation table each small stream reads its first mini sector only.
	 *
	 * @return void
	 */
	public function testAMissingMiniTableReadsOneMiniSectorPerStream(): void {
		$long = str_repeat('0123456789', 10);
		$bytes = CompoundFileBuilder::build(rootStreams: ['Short' => 'short', 'Long' => $long]);
		$bytes = self::put32(bytes: $bytes, offset: 0x3C, value: self::END);

		self::assertSame(
			['Long' => substr($long, 0, 64), 'Short' => 'short'],
			self::sorted(streams: (new CompoundFileStreams())->rootStreams(bytes: $bytes))
		);
	}//end testAMissingMiniTableReadsOneMiniSectorPerStream()

	/**
	 * A mini chain that points back at itself ends at the table's size instead of looping.
	 *
	 * @return void
	 */
	public function testALoopingMiniChainEnds(): void {
		$long = str_repeat('0123456789', 10);
		$bytes = CompoundFileBuilder::build(rootStreams: ['Long' => $long]);
		// Long holds mini sectors 0 and 1; make sector 0 point at itself.
		$bytes = self::put32(bytes: $bytes, offset: self::miniFatEntry(bytes: $bytes, index: 0), value: 0);

		$first = substr($long, 0, 64);
		self::assertSame(['Long' => substr($first . $first, 0, 100)], (new CompoundFileStreams())->rootStreams(bytes: $bytes));
	}//end testALoopingMiniChainEnds()

	/**
	 * A directory chain that points back at itself ends at the file's sector count.
	 *
	 * @return void
	 */
	public function testALoopingDirectoryChainEnds(): void {
		$streams = ['Alpha' => 'a', 'Beta' => 'b'];
		$bytes = CompoundFileBuilder::build(rootStreams: $streams);
		// The allocation table is sector 0, at offset 512; entry 1 is the directory's next sector.
		$bytes = self::put32(bytes: $bytes, offset: (512 + 4), value: 1);

		self::assertSame(self::sorted(streams: $streams), self::sorted(streams: (new CompoundFileStreams())->rootStreams(bytes: $bytes)));
	}//end testALoopingDirectoryChainEnds()
}//end class
