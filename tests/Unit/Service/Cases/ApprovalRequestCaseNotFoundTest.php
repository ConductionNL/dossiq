<?php

/**
 * Asking for an approval on a case that is not there.
 *
 * Pins that the request is refused as unprocessable before the case type is
 * resolved, decidiq is asked, or anything is saved.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\ApprovalRequest;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;

/**
 * A missing case is refused before anything is asked.
 *
 * @covers \OCA\Dossiq\Service\Cases\ApprovalRequest
 * @uses   \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */
class ApprovalRequestCaseNotFoundTest extends TestCase {
	/**
	 * A case the store does not hold is refused as unprocessable, and nothing else happens.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsRefusedBeforeDecidiqIsAsked(): void {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->expects(self::once())->method('loadCase')->with('case-gone')->willReturn(null);
		$store->expects(self::never())->method('saveCase');

		$caseTypes = $this->createMock(originalClassName: CaseTypeResolver::class);
		$caseTypes->expects(self::never())->method('effectiveCaseType');

		$decisions = $this->createMock(originalClassName: ContractDecisionDelegationService::class);
		$decisions->expects(self::never())->method('raiseDecision');

		$request = new ApprovalRequest(
			store: $store,
			caseTypes: $caseTypes,
			decisions: $decisions,
			settings: $this->createMock(originalClassName: SettingsService::class),
		);

		try {
			$request->raise(caseId: 'case-gone', act: 'send-besluit', userId: 'behandelaar');
			self::fail('A missing case must be refused.');
		} catch (RefusedException $refused) {
			self::assertSame('case-not-found', $refused->getRule());
			self::assertSame('This case could not be found.', $refused->getSentence());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $refused->getStatus());
		}
	}//end testAMissingCaseIsRefusedBeforeDecidiqIsAsked()
}//end class
