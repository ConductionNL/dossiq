<?php

/**
 * Whether a transfer crosses an organisation, and what a consent's scope reaches.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Custody
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Custody;

use OCA\Dossiq\Service\Custody\CaseTransferConsentGate;
use OCA\Dossiq\Service\Custody\ConsentRefusal;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Custody\CaseTransferConsentGate
 * @uses   \OCA\Dossiq\Service\Custody\ConsentRefusal
 * @uses   \OCA\Dossiq\Service\Custody\ConsentStanding
 */
class ConsentScopeAndCrossingTest extends TestCase {

	/**
	 * The gate under test.
	 *
	 * @return CaseTransferConsentGate
	 */
	private function gate(): CaseTransferConsentGate {
		return new CaseTransferConsentGate(
			settingsService: $this->createMock(SettingsService::class),
			logger: $this->createMock(LoggerInterface::class),
			refusals: new ConsentRefusal(l10n: new SourceL10n()),
		);
	}//end gate()

	public function testTheSameOrganisationInAnyCaseOrPaddingDoesNotCross(): void {
		self::assertFalse($this->gate()->crossesOrganisation(sourceOrganisation: ' Gemeente-A ', receivingOrg: 'gemeente-a'));
	}//end testTheSameOrganisationInAnyCaseOrPaddingDoesNotCross()

	public function testADifferentOrganisationCrosses(): void {
		self::assertTrue($this->gate()->crossesOrganisation(sourceOrganisation: 'gemeente-a', receivingOrg: 'gemeente-b'));
	}//end testADifferentOrganisationCrosses()

	public function testAnUnknownSideIsTreatedAsCrossing(): void {
		self::assertTrue($this->gate()->crossesOrganisation(sourceOrganisation: '', receivingOrg: 'gemeente-a'));
		self::assertTrue($this->gate()->crossesOrganisation(sourceOrganisation: 'gemeente-a', receivingOrg: '  '));
	}//end testAnUnknownSideIsTreatedAsCrossing()

	public function testTheScopeIsTheListedFieldsFirst(): void {
		self::assertSame(
			['bsn', 'inkomen'],
			$this->gate()->scopeOf(consent: ['tegegevens' => [' bsn ', '', 'inkomen'], 'scope' => 'alles'])
		);
	}//end testTheScopeIsTheListedFieldsFirst()

	public function testWithoutFieldsTheScopeIsTheStatedReach(): void {
		self::assertSame(['dossier'], $this->gate()->scopeOf(consent: ['tegegevens' => ['  '], 'scope' => ' dossier ']));
	}//end testWithoutFieldsTheScopeIsTheStatedReach()

	public function testNoConsentOrNoScopeReachesNothing(): void {
		self::assertSame([], $this->gate()->scopeOf(consent: null));
		self::assertSame([], $this->gate()->scopeOf(consent: ['scope' => '']));
	}//end testNoConsentOrNoScopeReachesNothing()
}//end class
