<?php

/**
 * Why a case's team reference could not be turned into a group.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Team
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

namespace OCA\Dossiq\Tests\Unit\Service\Team;

use OCA\Dossiq\Service\Team\CaseTeamMigration;
use OCA\Dossiq\Service\Team\CaseTeamReference;
use PHPUnit\Framework\TestCase;

/**
 * The group a resolved role names, and the reason an unconverted reference gives.
 *
 * @covers \OCA\Dossiq\Service\Team\CaseTeamReference
 */
class CaseTeamReferenceTest extends TestCase {

	private function reference(?array $role): CaseTeamReference {
		return new CaseTeamReference(caseId: 'c-1', case: ['id' => 'c-1'], value: 'role-7', role: $role);
	}

	public function testAnUnknownRoleHasNoGroupAndIsUnknown(): void {
		$reference = $this->reference(null);

		$this->assertSame('', $reference->group());
		$this->assertSame(CaseTeamMigration::REASON_UNKNOWN, $reference->reason());
		$this->assertSame('unknown-team', $reference->reason());
	}

	public function testARoleWithoutAGroupSaysSo(): void {
		foreach ([[], ['ncGroupId' => '   '], ['ncGroupId' => null]] as $role) {
			$reference = $this->reference($role);

			$this->assertSame('', $reference->group());
			$this->assertSame('role-has-no-group', $reference->reason());
		}
	}

	public function testARoleWithAGroupThatWasNotFoundSaysTheGroupIsMissing(): void {
		$reference = $this->reference(['ncGroupId' => ' vergunningen ']);

		$this->assertSame('vergunningen', $reference->group());
		$this->assertSame('role-group-missing', $reference->reason());
	}
}
