<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OnboardingStepGuide: the note and the action for each step, wherever the next step falls.
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
 * Notes, actions and order per step.
 *
 * @covers \OCA\Dossiq\Service\Tenant\OnboardingStepGuide
 */
class OnboardingStepGuideStepsTest extends TestCase {

	private const STEPS = ['contract', 'mandate_import', 'sso_setup', 'branding', 'zaaktype_selection', 'first_user', 'go_live'];

	/**
	 * Routes asked for, as route + section.
	 *
	 * @var array<int, string>
	 */
	private array $routes = [];

	/**
	 * @return OnboardingStepGuide
	 */
	private function guide(): OnboardingStepGuide {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('l')->willReturnCallback(static fn (string $type, mixed $date, array $options = []): string => $type.':'.$date->format('Y-m-d').':'.$options['width']);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(function (string $route, array $parameters): string {
			$this->routes[] = $route.'#'.$parameters['section'];
			return '/settings/admin/'.$parameters['section'];
		});

		return new OnboardingStepGuide(l10n: $l10n, urls: $urls);
	}//end guide()

	/**
	 * Every step done except the given one.
	 *
	 * @param string $open The open step.
	 *
	 * @return array<string, mixed>
	 */
	private static function allDoneBut(string $open): array {
		return ['steps' => array_map(
			static fn (string $step): array => ['step' => $step, 'status' => ($step === $open ? 'pending' : 'skipped')],
			self::STEPS
		)];
	}//end allDoneBut()

	/**
	 * With nothing reported, every step is pending: the first is next, the rest wait, other keys survive.
	 *
	 * @return void
	 */
	public function testAnEmptyProgressListsEveryStepInOrder(): void {
		$guided = $this->guide()->withGuidance(progress: ['steps' => [], 'total' => 7]);

		$this->assertSame(7, $guided['total']);
		$this->assertSame(self::STEPS, array_column($guided['steps'], 'step'));
		$this->assertSame([true, false, false, false, false, false, false], array_column($guided['steps'], 'isNext'));
		$this->assertSame('Not done yet; sign the contract', $guided['steps'][0]['note']);
		$this->assertSame('pending', $guided['steps'][0]['status']);
		foreach (array_slice($guided['steps'], 1) as $row) {
			$this->assertSame('Waits for the steps before this one', $row['note']);
			$this->assertNull($row['action']);
		}
	}//end testAnEmptyProgressListsEveryStepInOrder()

	/**
	 * A next step without an admin section gets a note but no action.
	 *
	 * @return void
	 */
	public function testStepsWithoutASectionGetNoAction(): void {
		$expected = ['contract' => 'sign the contract', 'first_user' => 'add the first user', 'go_live' => 'go live once every step is done'];
		foreach ($expected as $step => $hint) {
			$row = $this->nextRow(step: $step);
			$this->assertSame('Not done yet; '.$hint, $row['note'], $step);
			$this->assertNull($row['action'], $step);
		}

		$this->assertSame([], $this->routes);
	}//end testStepsWithoutASectionGetNoAction()

	/**
	 * Each step with a section links to its admin section with its own label.
	 *
	 * @return void
	 */
	public function testStepsWithASectionLinkToIt(): void {
		$expected = [
			'mandate_import' => ['Import mandates', 'dossiq', 'import the mandate table'],
			'sso_setup' => ['Set up sign-in', 'user_saml', 'link the organisation\'s sign-in (SSO)'],
			'branding' => ['Set the house style', 'theming', 'set the house style'],
			'zaaktype_selection' => ['Choose case types', 'dossiq', 'choose the case types'],
		];
		foreach ($expected as $step => [$label, $section, $hint]) {
			$row = $this->nextRow(step: $step);
			$this->assertSame(['label' => $label, 'href' => '/settings/admin/'.$section], $row['action'], $step);
			$this->assertSame('Not done yet; '.$hint, $row['note'], $step);
		}

		$this->assertSame([
			'settings.AdminSettings.index#dossiq',
			'settings.AdminSettings.index#user_saml',
			'settings.AdminSettings.index#theming',
			'settings.AdminSettings.index#dossiq',
		], $this->routes);
	}//end testStepsWithASectionLinkToIt()

	/**
	 * A completion date is formatted as a medium date; a missing or unreadable one reads Done.
	 *
	 * @return void
	 */
	public function testTheCompletionNote(): void {
		$steps = $this->guide()->withGuidance(progress: ['steps' => [
			['step' => 'contract', 'status' => 'completed', 'completedAt' => '2026-09-02T10:00:00+02:00'],
			['step' => 'mandate_import', 'status' => 'completed', 'completedAt' => 'not a date'],
			['step' => 'sso_setup', 'status' => 'completed'],
			['step' => 'branding', 'status' => 'skipped', 'completedAt' => '2026-09-02'],
		]])['steps'];

		$this->assertSame('Done on date:2026-09-02:medium', $steps[0]['note']);
		$this->assertSame('Done', $steps[1]['note']);
		$this->assertSame('Done', $steps[2]['note']);
		$this->assertSame('Skipped', $steps[3]['note']);
		$this->assertTrue($steps[4]['isNext']);
		$this->assertSame('Waits for the steps before this one', $steps[5]['note']);
	}//end testTheCompletionNote()

	/**
	 * A status other than completed or skipped still counts as open.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusIsOpen(): void {
		$steps = $this->guide()->withGuidance(progress: ['steps' => [
			['step' => 'contract', 'status' => 'in_progress'],
		]])['steps'];

		$this->assertTrue($steps[0]['isNext']);
		$this->assertSame('in_progress', $steps[0]['status']);
	}//end testAnUnknownStatusIsOpen()

	/**
	 * The row for a step when it is the only open one.
	 *
	 * @param string $step The step.
	 *
	 * @return array<string, mixed>
	 */
	private function nextRow(string $step): array {
		$steps = $this->guide()->withGuidance(progress: self::allDoneBut(open: $step))['steps'];
		$rows = array_values(array_filter($steps, static fn (array $row): bool => $row['isNext'] === true));
		$this->assertCount(1, $rows);
		$this->assertSame($step, $rows[0]['step']);
		return $rows[0];
	}//end nextRow()
}//end class
