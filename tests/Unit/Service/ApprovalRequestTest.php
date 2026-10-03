<?php

/**
 * Asking decidiq for the approval a gated act waits on.
 *
 * 🔴 WITHOUT THIS HALF THE GATE CAN NEVER OPEN. A gated act refuses while no
 * approval has been asked for, so a case type that declares a gate and offers
 * no way to ask is a case type whose besluit can never go out. These tests
 * drive the REAL delegation service over decidiq's event class, so the link
 * recorded on the case is the id decidiq answered and not one this file made up.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\ApprovalGateDeclaration;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Cases\ApprovalRequest;
use OCA\Dossiq\Service\ContractDecisionDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ApprovalRequestTest extends TestCase {

	/**
	 * The case as it was saved.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	/**
	 * The decision type decidiq was asked to walk.
	 *
	 * @var string
	 */
	private string $askedType = '';

	/**
	 * The service over a decidiq that answers with an id, or not at all.
	 *
	 * @param string|null $answer The decision id decidiq answers, null for no listener.
	 *
	 * @return ApprovalRequest The service.
	 */
	private function requestOver(?string $answer): ApprovalRequest {
		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn(['id' => 'case-1', 'title' => 'Dakkapel', 'caseType' => 'ct-omgeving']);
		$store->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->saved = $case;

				return $case;
			}
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn([
			ApprovalGateDeclaration::DECLARATION => [
				['act' => 'send-besluit', 'decisionType' => 'besluit-approval', 'label' => 'Approval by the teamleider'],
			],
		]);

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($answer): void {
				if ($answer === null || ($event instanceof DecisionRequestedEvent) === false) {
					return;
				}

				$this->askedType = $event->getDecisionType();
				$event->setHandled(true);
				$event->setDecisionId($answer);
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturn('x');

		return new ApprovalRequest(
			store: $store,
			caseTypes: $resolver,
			decisions: new ContractDecisionDelegationService(eventDispatcher: $dispatcher, logger: new NullLogger()),
			settings: $settings,
		);
	}//end requestOver()

	/**
	 * Asking records the id decidiq answered against the act.
	 *
	 * @return void
	 */
	public function testAskingLinksTheDecidiqDecisionToTheAct(): void {
		$link = $this->requestOver(answer: 'dec-7f3c')->raise(caseId: 'case-1', act: 'send-besluit', userId: 'behandelaar');

		self::assertSame(expected: 'dec-7f3c', actual: $link['decisionRef']);
		self::assertSame(expected: 'besluit-approval', actual: $this->askedType);
		self::assertSame(
			expected: 'dec-7f3c',
			actual: ApprovalGateDeclaration::referenceFor(case: $this->saved, act: 'send-besluit'),
		);
	}//end testAskingLinksTheDecidiqDecisionToTheAct()

	/**
	 * An act that is not gated has nothing to ask for.
	 *
	 * @return void
	 */
	public function testAnUngatedActIsRefused(): void {
		try {
			$this->requestOver(answer: 'dec-7f3c')->raise(caseId: 'case-1', act: 'assign', userId: 'behandelaar');
			self::fail(message: 'Asking decidiq for an approval nothing waits on starts a walk nobody reads.');
		} catch (RefusedException $refusal) {
			self::assertSame(expected: 'approval-not-declared', actual: $refusal->getRule());
		}

		self::assertSame(expected: [], actual: $this->saved);
	}//end testAnUngatedActIsRefused()

	/**
	 * A decidiq nobody can reach is a refusal with a status, and nothing is linked.
	 *
	 * @return void
	 */
	public function testAnUnreachableDecidiqIsRefusedWithAStatus(): void {
		try {
			$this->requestOver(answer: null)->raise(caseId: 'case-1', act: 'send-besluit', userId: 'behandelaar');
			self::fail(message: 'An approval nobody started must not read as asked for.');
		} catch (RefusedException $refusal) {
			self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $refusal->getStatus());
			self::assertStringContainsString(needle: 'approval service', haystack: $refusal->getSentence());
		}

		self::assertSame(expected: [], actual: $this->saved);
	}//end testAnUnreachableDecidiqIsRefusedWithAStatus()
}//end class
