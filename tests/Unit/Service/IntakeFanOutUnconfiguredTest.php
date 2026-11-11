<?php

/**
 * IntakeFanOut without a configured register.
 *
 * Pins that every destination of a submission fails, each reported with its
 * destination, its case type and the same reason, and that nothing is created.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\IntakeFanOut;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for IntakeFanOut without a register.
 */
class IntakeFanOutUnconfiguredTest extends TestCase {
	/**
	 * Without a configured register every destination fails with the same reason, in full.
	 *
	 * @return void
	 */
	public function testWithoutARegisterEveryDestinationFails(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('dossiq');
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn(
			[
				'intakeDestinations' => [
					['title' => 'Handhaving', 'caseType' => 'ct-handhaving', 'department' => 'handhaving'],
					['title' => 'Onderhoud', 'caseType' => 'ct-onderhoud', 'department' => 'wijkbeheer'],
				],
			]
		);
		$fanOut = new IntakeFanOut(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			relations: $this->createMock(originalClassName: CaseRelationService::class),
			logger: new NullLogger(),
		);

		$result = $fanOut->submit(formCaseTypeId: 'ct-melding', submission: ['title' => 'Kapotte lantaarnpaal'], submissionId: 'sub-1');

		$reason = $result['failed'][0]['reason'];
		$this->assertNotSame('', $reason);
		$this->assertSame(
			expected: [
				'created' => [],
				'failed' => [
					['destination' => 'Handhaving', 'caseType' => 'ct-handhaving', 'reason' => $reason],
					['destination' => 'Onderhoud', 'caseType' => 'ct-onderhoud', 'reason' => $reason],
				],
			],
			actual: $result
		);
	}//end testWithoutARegisterEveryDestinationFails()
}//end class
