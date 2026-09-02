<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Flow
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqAskParaafNode;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Flow\DossiqAskParaafNode
 */
class DossiqAskParaafNodeTest extends TestCase {

	/**
	 * Build the node.
	 *
	 * @return DossiqAskParaafNode The node.
	 */
	private function node(): DossiqAskParaafNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskParaafNode($l10n, $this->createMock(LoggerInterface::class));

	}//end node()

	/**
	 * A voorstel to sign off.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private function items(): array {
		return [['json' => ['id' => 'voorstel-1']]];

	}//end items()

	/**
	 * A workable config.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(): array {
		return ['question' => 'Paraaf behandelaar', 'actor' => 'behandelaar', 'actorType' => 'user', 'step' => 2];

	}//end config()

	/**
	 * The node records who is being asked, and suspends.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testItRecordsTheAskAndSuspends(): void {
		$resume = new FlowNodeResumeState('step-1');

		try {
			$this->node()->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
			$this->fail('the node must suspend while the paraaf is outstanding');
		} catch (FlowSuspension $e) {
			$this->assertStringContainsString('Paraaf behandelaar', $e->getMessage());
		}

		$this->assertSame('voorstel-1', $resume->get(key: 'proposal'));
		$this->assertSame(2, $resume->get(key: 'step'));
		$this->assertSame('Paraaf behandelaar', $resume->get(key: 'question'));

	}//end testItRecordsTheAskAndSuspends()

	/**
	 * 🔴 The slot names the assignee, because the resume guard reads it.
	 *
	 * `FlowRunService::signal()` is reachable without going through
	 * OpenRegister's HTTP resume endpoint, so whatever resumes this run has to
	 * consult the assignee itself. An awaiting step that does not say who may
	 * answer it is one anybody can answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testTheSlotNamesTheAssignee(): void {
		$resume = new FlowNodeResumeState('step-1');

		try {
			$this->node()->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
		} catch (FlowSuspension $e) {
			// expected
		}

		$this->assertSame('behandelaar', $resume->get(key: 'assignee'));
		$this->assertSame('user', $resume->get(key: 'actorType'));

	}//end testTheSlotNamesTheAssignee()

	/**
	 * 🔴 A parafeeractie requires an action, which is why the node creates none.
	 *
	 * This test exists to pin the REASON, because the reason lives in a
	 * different file. The node used to create a parafeeractie up front with no
	 * `action`; the schema declares `action` required and OpenRegister runs
	 * hard validation by default, so that could never have been saved. Should
	 * anyone make `action` optional later, this fails and asks them to
	 * reconsider the node rather than discovering it in production.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function testAParafeeractieRequiresAnActionSoTheNodeCreatesNone(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$required = $register['components']['schemas']['parafeeractie']['required'];

		$this->assertContains('action', $required);

		// And the enum carries no value meaning "not yet signed", so there is
		// nothing honest a placeholder could say.
		$actions = $register['components']['schemas']['parafeeractie']['properties']['action']['enum'];
		foreach (['pending', 'awaiting', 'requested', 'open'] as $placeholder) {
			$this->assertNotContains($placeholder, $actions);
		}

	}//end testAParafeeractieRequiresAnActionSoTheNodeCreatesNone()

	/**
	 * A heartbeat re-asks nothing: the question was already recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAHeartbeatDoesNotRestateTheQuestion(): void {
		$resume = new FlowNodeResumeState('step-1');
		$node = $this->node();

		$asked = [];
		foreach ([1, 2, 3] as $ignored) {
			try {
				$node->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
			} catch (FlowSuspension $e) {
				$asked[] = $resume->get(key: 'askedAt');
			}
		}

		$this->assertCount(3, $asked);
		$this->assertSame($asked[0], $asked[2], 'the ask must be recorded once, not restamped on every wake');

	}//end testAHeartbeatDoesNotRestateTheQuestion()

	/**
	 * An answer carries forward onto every item, under the signal key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnAnswerIsCarriedOntoTheItems(): void {
		$signal = ['decision' => 'parafered', 'mandate' => 'MB-2024-07', 'onBehalfOf' => 'wethouder'];

		$out = $this->node()->execute(
			$this->items(),
			($this->config() + ['signalKey' => 'step1']),
			[FlowRunService::SIGNAL_CONTEXT_KEY => $signal]
		);

		$this->assertSame($signal, $out[0]['json']['step1']);

	}//end testAnAnswerIsCarriedOntoTheItems()

	/**
	 * An answer lands under `paraaf` when the step names no signal key.
	 *
	 * @return void
	 */
	public function testTheAnswerLandsUnderParaafByDefault(): void {
		$signal = ['decision' => 'parafered'];

		$config = $this->config();
		unset($config['signalKey']);

		$out = $this->node()->execute(
			$this->items(),
			$config,
			[FlowRunService::SIGNAL_CONTEXT_KEY => $signal]
		);

		$this->assertSame($signal, $out[0]['json']['paraaf']);

	}//end testTheAnswerLandsUnderParaafByDefault()

	/**
	 * A resume with no decision is a nudge, not an answer.
	 *
	 * That is what makes a duplicate or accidental POST harmless.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAResumeWithoutADecisionSuspendsAgain(): void {
		$resume = new FlowNodeResumeState('step-1');

		$this->expectException(FlowSuspension::class);

		$this->node()->execute(
			$this->items(),
			$this->config(),
			[
				FlowNodeResumeState::CONTEXT_KEY => $resume,
				FlowRunService::SIGNAL_CONTEXT_KEY => ['comment' => 'looks fine'],
			]
		);

	}//end testAResumeWithoutADecisionSuspendsAgain()

	/**
	 * A step with no question is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAStepWithNoQuestionIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs a question');

		$this->node()->validateConfig(['actor' => 'behandelaar']);

	}//end testAStepWithNoQuestionIsRefused()

	/**
	 * A step naming no actor is refused.
	 *
	 * A sign-off with nobody to give it is not a step, and a route projecting
	 * into one would wait forever.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAStepWithNoActorIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs an actor');

		$this->node()->execute($this->items(), ['question' => 'Paraaf', 'actor' => '  '], []);

	}//end testAStepWithNoActorIsRefused()

	/**
	 * Without a resume slot the node refuses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testWithoutAResumeSlotItRefuses(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('resume slot');

		$this->node()->execute($this->items(), $this->config(), []);

	}//end testWithoutAResumeSlotItRefuses()

	/**
	 * A run carrying no voorstel is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testARunWithNoVoorstelIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no voorstel');

		$this->node()->execute(
			[['json' => ['title' => 'a voorstel with no id']]],
			$this->config(),
			[FlowNodeResumeState::CONTEXT_KEY => new FlowNodeResumeState('step-1')]
		);

	}//end testARunWithNoVoorstelIsRefused()

	/**
	 * The node identifies itself as the type the projection emits.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testItAnswersToTheIdTheProjectionEmits(): void {
		$this->assertSame('dossiq.askParaaf', $this->node()->getId());

	}//end testItAnswersToTheIdTheProjectionEmits()

	/**
	 * The node describes itself for the flow editor.
	 *
	 * @return void
	 */
	public function testItDescribesItselfForTheEditor(): void {
		$node = $this->node();

		$this->assertNotSame('', trim($node->getDisplayName()));
		$this->assertNotSame('', trim($node->getDescription()));
		$this->assertNotSame('', trim($node->getIcon()));

	}//end testItDescribesItselfForTheEditor()

	/**
	 * A paraaf step is valid in every scope.
	 *
	 * @return void
	 */
	public function testItIsOfferedInEveryScope(): void {
		$node = $this->node();

		foreach ([0, 1, 2] as $scope) {
			$this->assertTrue($node->isAvailableForScope($scope));
		}

	}//end testItIsOfferedInEveryScope()

	/**
	 * A nonsensical heartbeat falls back to the default rather than to now.
	 *
	 * @return void
	 */
	public function testANonsensicalHeartbeatFallsBackToTheDefault(): void {
		$resume = new FlowNodeResumeState('step-1');

		try {
			$this->node()->execute(
				$this->items(),
				($this->config() + ['heartbeatMinutes' => 0]),
				[FlowNodeResumeState::CONTEXT_KEY => $resume]
			);
			$this->fail('the node must suspend while the paraaf is outstanding');
		} catch (FlowSuspension $e) {
			$this->assertGreaterThan(new \DateTime(), $e->getResumeAt());
		}

	}//end testANonsensicalHeartbeatFallsBackToTheDefault()

}//end class
