<?php

/**
 * The notices a case act answers with: their variant, role and wording.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Notice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notice;

use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Each notice, exactly, with the translation seen as the call it makes.
 *
 * @covers \OCA\Dossiq\Service\Notice\CaseActNotices
 */
class CaseActNoticesTest extends TestCase {

	private function notices(): CaseActNotices {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new CaseActNotices($l10n);
	}

	public function testACompletedTaskIsASuccessStatus(): void {
		$this->assertSame(
			['variant' => 'success', 'role' => 'status', 'title' => 'Task Beoordelen completed.', 'text' => 'You stay on the case; the list is updated.'],
			$this->notices()->taskCompleted('Beoordelen')
		);
	}

	public function testARefusedCancelIsAnAlertCarryingTheReason(): void {
		$this->assertSame(
			['variant' => 'error', 'role' => 'alert', 'title' => 'Cancelling refused for Beoordelen:', 'text' => 'the task is already closed.'],
			$this->notices()->cancelRefused('Beoordelen', 'the task is already closed.')
		);
	}

	public function testARefusedLinkNamesTheTakenSubject(): void {
		$this->assertSame(
			['variant' => 'error', 'role' => 'alert', 'title' => 'Not linked:', 'text' => 'Z-2 already hangs as the subject under Z-9. A case can hang as the subject under one case only.'],
			$this->notices()->linkRefused('subject_taken', 'Z-2', 'Z-9')
		);
	}

	public function testARefusedLinkNamesTheCycle(): void {
		$this->assertSame(
			'Z-9 already hangs, directly or further up, under Z-2. A subject cannot hang under its own subject.',
			$this->notices()->linkRefused('subject_cycle', 'Z-2', 'Z-9')['text']
		);
	}

	public function testARefusedRevokeSaysWhoMayRevoke(): void {
		$this->assertSame(
			['variant' => 'error', 'role' => 'alert', 'title' => 'Revoking refused by OpenRegister:', 'text' => 'a link is revoked by the colleague who created it.'],
			$this->notices()->revokeRefused()
		);
	}
}
