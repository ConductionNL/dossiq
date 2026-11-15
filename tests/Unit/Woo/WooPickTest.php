<?php

/**
 * Unit tests for WooPickRun and WooPickedFile: a pick reads its source, key,
 * custodian and location from the picked entry, and a picked file is
 * fingerprinted by the SHA-256 of its content.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Woo\WooPickedFile;
use OCA\Dossiq\Woo\WooPickRun;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Woo\WooPickRun
 * @covers \OCA\Dossiq\Woo\WooPickedFile
 */
class WooPickTest extends TestCase {

	private function pickRun(array $pick): WooPickRun {
		return new WooPickRun(caseId: 'case-1', terms: 'subsidie', user: $this->createMock(IUser::class), custodians: ['team-a'], pick: $pick);
	}//end run()

	public function testThePickedEntryIsReadWithKeyAndCustodianTrimmed(): void {
		$run = $this->pickRun(['source' => 'files', 'key' => ' /Woo/brief.pdf ', 'custodian' => ' alice ', 'location' => ' Map 3 ']);

		$this->assertSame('files', $run->source());
		$this->assertSame('/Woo/brief.pdf', $run->key());
		$this->assertSame('alice', $run->custodian());
		$this->assertSame(' Map 3 ', $run->location());
		$this->assertSame([], $run->provenance);
		$this->assertSame('', $run->duplicateOf);
	}//end testThePickedEntryIsReadWithKeyAndCustodianTrimmed()

	public function testAMissingFieldReadsAsEmptyAndScalarsAreCast(): void {
		$run = $this->pickRun(['key' => 42]);

		$this->assertSame('', $run->source());
		$this->assertSame('42', $run->key());
		$this->assertSame('', $run->custodian());
		$this->assertSame('', $run->location());
	}//end testAMissingFieldReadsAsEmptyAndScalarsAreCast()

	public function testAPickedFileIsFingerprintedByItsContent(): void {
		$file = new WooPickedFile(fileName: 'brief.pdf', content: 'abc');

		$this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $file->sha256());
		$this->assertSame('', $file->mimeType);
		$this->assertSame(
			hash('sha256', ''),
			(new WooPickedFile(fileName: 'empty.txt', content: '', mimeType: 'text/plain'))->sha256()
		);
	}//end testAPickedFileIsFingerprintedByItsContent()
}//end class
