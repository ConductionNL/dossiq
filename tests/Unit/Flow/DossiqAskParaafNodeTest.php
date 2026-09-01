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
use OCA\Dossiq\Service\SettingsService;
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
	 * Parafeeracties the node wrote.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Build the node over a fake object service.
	 *
	 * @return DossiqAskParaafNode The node.
	 */
	private function node(): DossiqAskParaafNode {
		$written = &$this->written;

		$objectService = new class($written) {
			/**
			 * @param array<int, array<string, mixed>> $written Writes.
			 */
			public function __construct(private array &$written) {
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->written[] = ($object + ['_schema' => $schema]);

				return ($object + ['id' => 'pa-' . count($this->written)]);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'parafeeractie_schema' => 'parafeeractie'];

				return ($map[$key] ?? $default);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskParaafNode($settings, $l10n, $this->createMock(LoggerInterface::class));

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
	 * The node raises a parafeeractie and suspends.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testItRaisesAParafeeractieAndSuspends(): void {
		$resume = new FlowNodeResumeState('step-1');

		try {
			$this->node()->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
			$this->fail('the node must suspend while the paraaf is outstanding');
		} catch (FlowSuspension $e) {
			$this->assertStringContainsString('Paraaf behandelaar', $e->getMessage());
		}

		$this->assertCount(1, $this->written);
		$this->assertSame('parafeeractie', $this->written[0]['_schema']);

	}//end testItRaisesAParafeeractieAndSuspends()

	/**
	 * 🔴 The paraaf carries the domain fields a generic task cannot.
	 *
	 * This is the whole reason the node exists. `onBehalfOf` and `mandate` are
	 * administrative-law record; `step`, `actor` and `actorType` are what the
	 * parafering screens read. A projection built on `dossiq.askPerson` wrote a
	 * generic task instead and lost all of it.
	 *
	 * @return void
	 */
	public function testTheParaafCarriesTheDomainFields(): void {
		$resume = new FlowNodeResumeState('step-1');

		try {
			$this->node()->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
		} catch (FlowSuspension $e) {
			// expected
		}

		$paraaf = $this->written[0];
		$this->assertSame('voorstel-1', $paraaf['proposal']);
		$this->assertSame('behandelaar', $paraaf['actor']);
		$this->assertSame('user', $paraaf['actorType']);
		$this->assertSame(2, $paraaf['step']);
		// Without these two the run cannot be resumed: it holds one awaiting
		// slot PER NODE and cannot say which of them a paraaf answers.
		$this->assertSame('step-1', $paraaf['flowNode']);
		$this->assertArrayHasKey('flowRun', $paraaf);

	}//end testTheParaafCarriesTheDomainFields()

	/**
	 * A heartbeat does not raise a second paraaf against the same person.
	 *
	 * @return void
	 */
	public function testAHeartbeatDoesNotRaiseASecondParaaf(): void {
		$resume = new FlowNodeResumeState('step-1');
		$node = $this->node();

		foreach ([1, 2, 3] as $ignored) {
			try {
				$node->execute($this->items(), $this->config(), [FlowNodeResumeState::CONTEXT_KEY => $resume]);
			} catch (FlowSuspension $e) {
				// expected on every pass
			}
		}

		$this->assertCount(1, $this->written, 'each heartbeat must re-ask, never re-raise');

	}//end testAHeartbeatDoesNotRaiseASecondParaaf()

	/**
	 * An answer carries forward onto every item, under the signal key.
	 *
	 * @return void
	 */
	public function testAnAnswerIsCarriedOntoTheItems(): void {
		$signal = ['decision' => 'approved', 'mandate' => 'MB-2024-07', 'onBehalfOf' => 'wethouder'];

		$out = $this->node()->execute(
			$this->items(),
			($this->config() + ['signalKey' => 'step1']),
			[FlowRunService::SIGNAL_CONTEXT_KEY => $signal]
		);

		$this->assertSame($signal, $out[0]['json']['step1']);
		$this->assertSame([], $this->written, 'answering must not raise another paraaf');

	}//end testAnAnswerIsCarriedOntoTheItems()

	/**
	 * A resume with no decision is a nudge, not an answer.
	 *
	 * That is what makes a duplicate or accidental POST harmless.
	 *
	 * @return void
	 */
	public function testAResumeWithoutADecisionSuspendsAgain(): void {
		$resume = new FlowNodeResumeState('step-1');

		$this->expectException(FlowSuspension::class);

		$this->node()->execute(
			$this->items(),
			$this->config(),
			[
				FlowNodeResumeState::CONTEXT_KEY => $resume,
				FlowRunService::SIGNAL_CONTEXT_KEY => ['comment' => 'just looking'],
			]
		);

	}//end testAResumeWithoutADecisionSuspendsAgain()

	/**
	 * A step naming no actor is refused, not asked of nobody.
	 *
	 * @return void
	 */
	public function testAStepWithNoActorIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs an actor');

		$this->node()->execute($this->items(), ['question' => 'Paraaf', 'actor' => '  '], []);

	}//end testAStepWithNoActorIsRefused()

	/**
	 * Without a resume slot the node refuses rather than raising duplicates.
	 *
	 * @return void
	 */
	public function testWithoutAResumeSlotItRefuses(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('resume slot');

		$this->node()->execute($this->items(), $this->config(), []);

	}//end testWithoutAResumeSlotItRefuses()

}//end class
