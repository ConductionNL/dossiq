<?php

/**
 * The injected term kind classifier.
 *
 * Which kind a term instance is (statutory, planned, internal, phase, ...)
 * decides whether the case deadline follows it and whether a Woo extension
 * may move it. The rule lives in TermKindClassifier, which is injected, so a
 * caller needs no static call; TermKind::ofInstance() answers through it. These
 * cases pin the rule, pin that the static and the injected answer agree, and
 * pin that the two callers this change added really ask the injected one.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineFollower;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermKindClassifier;
use OCA\Dossiq\Service\Termijn\WooTermExtension;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The term kind rule, and the two callers that inject it.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermKindClassifier
 * @covers \OCA\Dossiq\Service\Termijn\CaseDeadlineFollower
 * @covers \OCA\Dossiq\Service\Termijn\WooTermExtension
 */
class TermKindClassifierTest extends TestCase {

	/**
	 * An absent kind and an unknown kind read as statutory; a known kind reads as itself.
	 *
	 * @return void
	 */
	public function testTheRule(): void {
		$kinds = new TermKindClassifier();

		self::assertSame(TermKind::STATUTORY, $kinds->ofInstance(instance: ['case' => 'c1']));
		self::assertSame(TermKind::STATUTORY, $kinds->ofInstance(instance: ['kind' => 'something-else']));
		foreach (TermKind::ALL as $kind) {
			self::assertSame($kind, $kinds->ofInstance(instance: ['kind' => $kind]));
		}
	}//end testTheRule()

	/**
	 * The static entry point answers through the same rule, so there is one rule.
	 *
	 * @return void
	 */
	public function testTheStaticAnswerIsTheInjectedAnswer(): void {
		$kinds = new TermKindClassifier();
		$instances = [[], ['kind' => ''], ['kind' => 'something-else']];
		foreach (TermKind::ALL as $kind) {
			$instances[] = ['kind' => $kind];
		}

		foreach ($instances as $instance) {
			self::assertSame($kinds->ofInstance(instance: $instance), TermKind::ofInstance(instance: $instance));
		}
	}//end testTheStaticAnswerIsTheInjectedAnswer()

	/**
	 * The follower asks the injected classifier: told the term is planned, it leaves the case alone.
	 *
	 * @return void
	 */
	public function testTheFollowerAsksTheInjectedClassifier(): void {
		$kinds = $this->createMock(TermKindClassifier::class);
		$kinds->expects($this->once())->method('ofInstance')->willReturn(TermKind::PLANNED);

		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('getObjectService');

		$follower = new CaseDeadlineFollower(
			settingsService: $settings,
			mirror: new CaseDeadlineMirror(),
			logger: $this->createMock(LoggerInterface::class),
			kinds: $kinds,
		);

		self::assertFalse($follower->follow(instance: ['case' => 'case-1', 'endDateCurrent' => '2026-12-28']));
	}//end testTheFollowerAsksTheInjectedClassifier()

	/**
	 * The Woo extension asks the injected classifier: told the only term is internal, it refuses.
	 *
	 * @return void
	 */
	public function testTheWooExtensionAsksTheInjectedClassifier(): void {
		$kinds = $this->createMock(TermKindClassifier::class);
		$kinds->expects($this->once())->method('ofInstance')->willReturn(TermKind::INTERNAL);

		$terms = $this->createMock(TermijnService::class);
		$terms->method('instancesForCase')->willReturn([['id' => 'ti-1', 'endDateCurrent' => '2026-11-02']]);
		$extension = $this->createMock(DeadlineExtensionService::class);
		$extension->expects($this->never())->method('requestExtensionByDays');

		$woo = new WooTermExtension(
			termService: $terms,
			extension: $extension,
			logger: $this->createMock(LoggerInterface::class),
			kinds: $kinds,
		);

		try {
			$woo->extend(caseId: 'case-woo', reason: 'Zienswijzen van derden');
			$this->fail('A case whose only term is not statutory has no Woo term to extend.');
		} catch (RefusedException $refusal) {
			self::assertSame('woo-term-missing', $refusal->getRule());
		}
	}//end testTheWooExtensionAsksTheInjectedClassifier()
}//end class
