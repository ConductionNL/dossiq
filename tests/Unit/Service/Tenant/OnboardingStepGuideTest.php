<?php

/**
 * OnboardingStepGuide tests: the first step not done is next and links to
 * where it is done; a done step says when; a later step waits.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Tenant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Tenant;

use OCA\Dossiq\Service\Tenant\OnboardingStepGuide;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OnboardingStepGuide.
 *
 * @spec openspec/specs/tenant-onboarding/spec.md#requirement-onboarding-checklist-and-progress-dashboard-req-003-a-req-003-d
 */
class OnboardingStepGuideTest extends TestCase {

	/**
	 * Two steps done, the third is next with its link, the rest wait; the
	 * checklist order holds whatever order the engine answered in.
	 *
	 * @return void
	 */
	public function testTheFirstOpenStepIsNext(): void {
		$progress = [
			'steps' => [
				['step' => 'sso_setup', 'status' => 'pending'],
				['step' => 'contract', 'status' => 'completed', 'completedAt' => '2026-09-02T10:00:00+02:00'],
				['step' => 'mandate_import', 'status' => 'skipped'],
			],
			'completed' => 1,
			'total' => 7,
			'fraction' => 0.14,
		];

		$steps = $this->guide()->withGuidance(progress: $progress)['steps'];

		$this->assertSame(
			expected: ['contract', 'mandate_import', 'sso_setup', 'branding', 'zaaktype_selection', 'first_user', 'go_live'],
			actual: array_column($steps, 'step')
		);
		$this->assertSame(expected: [false, false, true, false, false, false, false], actual: array_column($steps, 'isNext'));
		$this->assertSame(expected: 'Done on Sep 2, 2026', actual: $steps[0]['note']);
		$this->assertSame(expected: 'Skipped', actual: $steps[1]['note']);
		$this->assertSame(expected: 'Not done yet; link the organisation\'s sign-in (SSO)', actual: $steps[2]['note']);
		$this->assertSame(expected: ['label' => 'Set up sign-in', 'href' => '/settings/admin/user_saml'], actual: $steps[2]['action']);
		$this->assertSame(expected: 'Waits for the steps before this one', actual: $steps[3]['note']);
		$this->assertNull(actual: $steps[3]['action']);
	}//end testTheFirstOpenStepIsNext()

	/**
	 * With every step done nothing is next.
	 *
	 * @return void
	 */
	public function testNothingIsNextWhenAllIsDone(): void {
		$rows = array_map(
			static fn (string $step): array => ['step' => $step, 'status' => 'completed'],
			['contract', 'mandate_import', 'sso_setup', 'branding', 'zaaktype_selection', 'first_user', 'go_live']
		);

		$steps = $this->guide()->withGuidance(progress: ['steps' => $rows])['steps'];

		$this->assertSame(expected: [], actual: array_filter(array_column($steps, 'isNext')));
		$this->assertSame(expected: 'Done', actual: $steps[6]['note']);
	}//end testNothingIsNextWhenAllIsDone()

	/**
	 * The guide over a translator that formats the source text.
	 *
	 * @return OnboardingStepGuide
	 */
	private function guide(): OnboardingStepGuide {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('l')->willReturnCallback(static fn (string $type, mixed $date): string => $date->format('M j, Y'));
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (
			string $route,
			array $parameters
		): string => '/settings/admin/'.$parameters['section']);

		return new OnboardingStepGuide(l10n: $l10n, urls: $urls);
	}//end guide()
}//end class
