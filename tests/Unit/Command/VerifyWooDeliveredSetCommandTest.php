<?php

/**
 * The delivered Woo set verification command: what it prints and the exit code it answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\VerifyWooDeliveredSetCommand;
use OCA\Dossiq\Woo\WooDeliveredSetVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\VerifyWooDeliveredSetCommand
 */
class VerifyWooDeliveredSetCommandTest extends TestCase {

	/**
	 * Run the command for set 'set-1' over a verifier answering $result.
	 *
	 * @param array<string, mixed> $result What verify() answers.
	 *
	 * @return array{0: int, 1: string} Exit code and display.
	 */
	private function runVerify(array $result): array {
		$set      = ['id' => 'set-1'];
		$verifier = $this->createMock(WooDeliveredSetVerifier::class);
		$verifier->expects($this->once())->method('find')->with('set-1')->willReturn($set);
		$verifier->expects($this->once())->method('verify')->with($set)->willReturn($result);

		$tester = new CommandTester(new VerifyWooDeliveredSetCommand(verifier: $verifier));
		$code   = $tester->execute(['setId' => 'set-1']);

		return [$code, $tester->getDisplay()];
	}//end runVerify()

	/**
	 * The command is registered under its occ name with a required set id.
	 *
	 * @return void
	 */
	public function testItIsNamedWithARequiredArgument(): void {
		$command = new VerifyWooDeliveredSetCommand(verifier: $this->createMock(WooDeliveredSetVerifier::class));

		self::assertSame('dossiq:woo:verify-delivered-set', $command->getName());
		self::assertTrue($command->getDefinition()->getArgument('setId')->isRequired());
	}//end testItIsNamedWithARequiredArgument()

	/**
	 * An unknown set fails without verifying anything.
	 *
	 * @return void
	 */
	public function testAnUnknownSetFails(): void {
		$verifier = $this->createMock(WooDeliveredSetVerifier::class);
		$verifier->expects($this->once())->method('find')->with('nope')->willReturn(null);
		$verifier->expects($this->never())->method('verify');

		$tester = new CommandTester(new VerifyWooDeliveredSetCommand(verifier: $verifier));
		$code   = $tester->execute(['setId' => 'nope']);

		self::assertSame(Command::FAILURE, $code);
		self::assertStringContainsString('No delivered set with that id.', $tester->getDisplay());
	}//end testAnUnknownSetFails()

	/**
	 * A verifying set prints every item and the set hash, and succeeds.
	 *
	 * @return void
	 */
	public function testAVerifyingSetSucceeds(): void {
		[$code, $out] = $this->runVerify(
			[
				'verified' => true,
				'items' => [
					['status' => 'ok', 'deliveredRef' => 'doc-1', 'expected' => 'aa', 'actual' => 'aa'],
					['status' => 'ok', 'deliveredRef' => 'doc-2', 'expected' => 'bb', 'actual' => 'bb'],
				],
				'setHash' => ['expected' => 'ss', 'actual' => 'ss'],
			]
		);

		self::assertSame(Command::SUCCESS, $code);
		self::assertStringContainsString('ok  doc-1  expected aa  actual aa', $out);
		self::assertStringContainsString('ok  doc-2  expected bb  actual bb', $out);
		self::assertStringContainsString('set hash expected ss  actual ss', $out);
		self::assertStringContainsString('The set verifies', $out);
		self::assertStringNotContainsString('does not verify', $out);
	}//end testAVerifyingSetSucceeds()

	/**
	 * A set that no longer verifies prints the mismatch and fails.
	 *
	 * @return void
	 */
	public function testANonVerifyingSetFails(): void {
		[$code, $out] = $this->runVerify(
			[
				'verified' => false,
				'items' => [
					['status' => 'changed', 'deliveredRef' => 'doc-1', 'expected' => 'aa', 'actual' => 'zz'],
				],
				'setHash' => ['expected' => 'ss', 'actual' => 'tt'],
			]
		);

		self::assertSame(Command::FAILURE, $code);
		self::assertStringContainsString('changed  doc-1  expected aa  actual zz', $out);
		self::assertStringContainsString('set hash expected ss  actual tt', $out);
		self::assertStringContainsString('The set does not verify.', $out);
		self::assertStringNotContainsString('The set verifies', $out);
	}//end testANonVerifyingSetFails()
}//end class
