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

	/**
	 * Build the node over a caller-supplied object service and settings.
	 *
	 * The default `node()` fake returns a plain array from `saveObject()`.
	 * OpenRegister returns an ObjectEntity, so that path is the one production
	 * always takes and the one the array fake never exercises. This builder
	 * exists so a test can supply either.
	 *
	 * @param object|null           $objectService The object service, or null.
	 * @param array<string, string> $config        Settings overrides.
	 *
	 * @return DossiqAskParaafNode The node.
	 */
	private function nodeOver(?object $objectService, array $config=[]): DossiqAskParaafNode {
		$map = ($config + ['register' => 'dossiq', 'parafeeractie_schema' => 'parafeeractie']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default='') use ($map): string {
				return ($map[$key] ?? $default);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DossiqAskParaafNode($settings, $l10n, $this->createMock(LoggerInterface::class));

	}//end nodeOver()

	/**
	 * The node identifies itself as the type the projection emits.
	 *
	 * EndorsementRouteFlowMigrator writes this literal into every projected
	 * route. If the two ever disagree the projection produces a flow the
	 * engine cannot run, so the string is pinned on both sides.
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
		$this->assertStringContainsString('parafeeractie', $node->getDescription());
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
	 * A step with no question is refused.
	 *
	 * A parafeeractie with nothing to answer is not a step. Refusing at
	 * validate time is what stops a route projecting into a flow that asks
	 * somebody an empty question and waits for the reply.
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
	 * An answer lands under `paraaf` when the step names no signal key.
	 *
	 * The projection sets signalKey per step. A hand-built flow need not, and
	 * the steps after this one still have to find the answer somewhere.
	 *
	 * @return void
	 */
	public function testTheAnswerLandsUnderParaafByDefault(): void {
		$signal = ['decision' => 'akkoord'];

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
	 * A run carrying no voorstel is refused.
	 *
	 * A parafeeractie is a sign-off ON something. Writing one with an empty
	 * proposal would create an orphan record that the parafering screens
	 * cannot show and nobody can act on.
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
	 * Without OpenRegister the node refuses rather than dropping the paraaf.
	 *
	 * Suspending without having written the parafeeractie would leave a run
	 * waiting for an answer to a question nobody was ever asked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItRefuses(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('openregister_unavailable');

		$this->nodeOver(null)->execute(
			$this->items(),
			$this->config(),
			[FlowNodeResumeState::CONTEXT_KEY => new FlowNodeResumeState('step-1')]
		);

	}//end testWithoutOpenRegisterItRefuses()

	/**
	 * An unconfigured parafeeractie schema is refused.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('parafeeractie_schema_not_configured');

		$service = new class {

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				return ($object + ['id' => 'pa-1']);
			}

		};

		$this->nodeOver($service, ['parafeeractie_schema' => ''])->execute(
			$this->items(),
			$this->config(),
			[FlowNodeResumeState::CONTEXT_KEY => new FlowNodeResumeState('step-1')]
		);

	}//end testAnUnconfiguredSchemaIsRefused()

	/**
	 * 🔴 An ObjectEntity return is unwrapped, because that is what OpenRegister
	 * actually returns.
	 *
	 * `ObjectService::saveObject()` returns an ObjectEntity, not an array. The
	 * array-returning fake the other tests use is convenient and is NOT what
	 * production hands back, so without this test the unwrap branch is the one
	 * path that never runs under test and always runs in production.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnObjectEntityReturnIsUnwrapped(): void {
		$service = new class {

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return object The stored entity.
			 */
			public function saveObject(array $object, string $register, string $schema): object {
				return new class($object) {

					/**
					 * @param array<string, mixed> $object The object.
					 */
					public function __construct(private array $object) {
					}

					/**
					 * @return array<string, mixed> The stored object.
					 */
					public function getObject(): array {
						return ($this->object + ['id' => 'pa-entity-1']);
					}

				};
			}

		};

		$resume = new FlowNodeResumeState('step-1');

		try {
			$this->nodeOver($service)->execute(
				$this->items(),
				$this->config(),
				[FlowNodeResumeState::CONTEXT_KEY => $resume]
			);
			$this->fail('the node must suspend while the paraaf is outstanding');
		} catch (FlowSuspension $e) {
			// expected: the paraaf was raised and the run waits for it.
		}

		// The id has to come from INSIDE the entity. Reading the entity itself
		// would store something no resume can match.
		$this->assertSame('pa-entity-1', $resume->get(key: 'parafeeractieId'));

	}//end testAnObjectEntityReturnIsUnwrapped()

	/**
	 * A paraaf that comes back without an id is refused.
	 *
	 * The id is what a resume matches on. Suspending without one would leave a
	 * run that no answer can ever wake.
	 *
	 * @return void
	 */
	public function testAnUnidentifiableParaafIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('parafeeractie_not_identifiable');

		$service = new class {

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The stored object, carrying no id.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				return $object;
			}

		};

		$this->nodeOver($service)->execute(
			$this->items(),
			$this->config(),
			[FlowNodeResumeState::CONTEXT_KEY => new FlowNodeResumeState('step-1')]
		);

	}//end testAnUnidentifiableParaafIsRefused()

	/**
	 * A nonsensical heartbeat falls back to the default rather than to now.
	 *
	 * A zero or negative interval would ask the worker to wake immediately and
	 * forever, turning the safety net into a spin.
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
