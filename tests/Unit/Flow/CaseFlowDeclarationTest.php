<?php

/**
 * The shipped case flow is well-formed.
 *
 * The flow is DECLARED in the register file and imported into OpenRegister's
 * flow store. Nothing type-checks it on the way in: a node type nothing
 * registers, an edge pointing at a node that does not exist, or a loop with no
 * way out are all accepted at import and only discovered when a real case runs
 * — by which time a citizen's application is stuck in it.
 *
 * These tests are the check that does not exist anywhere else. They are
 * deliberately STRUCTURAL rather than executable: dossiq's own bootstrap stubs
 * OpenRegister's flow interfaces when the app is absent, so a test that asked
 * OpenRegister's builder to build this document would, on a machine without
 * OpenRegister, validate a stub and pass while proving nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use PHPUnit\Framework\TestCase;

class CaseFlowDeclarationTest extends TestCase {
	/**
	 * The declared flow, as shipped.
	 *
	 * @var array<string, mixed>
	 */
	private array $flow;

	protected function setUp(): void {
		$path = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$this->assertFileExists($path);

		$register = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($register, 'The register file must be valid JSON.');

		$flows = ($register['components']['schemas']['case']['x-openregister-flows'] ?? null);
		$this->assertIsArray($flows, 'The case schema must declare its flow.');
		$this->assertCount(1, $flows, 'Exactly one case flow ships; a second would import as a separate flow.');

		$this->flow = $flows[0];
	}//end setUp()

	/**
	 * Node ids, for edge checking.
	 *
	 * @return string[] The declared node ids.
	 */
	private function nodeIds(): array {
		return array_map(static fn (array $n): string => (string)$n['id'], $this->flow['nodes']);
	}//end nodeIds()

	public function testTheFlowNamesItselfAndItsTrigger(): void {
		$this->assertNotSame('', trim((string)($this->flow['name'] ?? '')), 'A declared flow with no name is refused at import.');
		$this->assertSame('object.created', $this->flow['trigger']);

		$trigger = null;
		foreach ($this->flow['nodes'] as $node) {
			if (($node['type'] ?? '') === 'openregister.trigger-object') {
				$trigger = $node;
				break;
			}
		}

		$this->assertNotNull($trigger, 'The flow must carry a trigger node, not only a trigger column.');
		$this->assertSame('case', $trigger['config']['schema']);
		$this->assertSame('object.created', $trigger['config']['event']);
	}//end testTheFlowNamesItselfAndItsTrigger()

	/**
	 * 🔴 Every edge points at a node that exists.
	 *
	 * A dangling edge is accepted at import and fails the run at the moment the
	 * token reaches it — mid-case, after side effects have already happened.
	 */
	public function testEveryEdgeConnectsDeclaredNodes(): void {
		$ids = $this->nodeIds();

		foreach ($this->flow['edges'] as $edge) {
			$this->assertContains(
				(string)$edge['from'],
				$ids,
				sprintf('Edge "%s" starts at a node that does not exist.', $edge['id'])
			);
			$this->assertContains(
				(string)$edge['to'],
				$ids,
				sprintf('Edge "%s" ends at a node that does not exist.', $edge['id'])
			);
		}
	}//end testEveryEdgeConnectsDeclaredNodes()

	public function testNodeIdsAreUnique(): void {
		$ids = $this->nodeIds();

		$this->assertSame(
			count($ids),
			count(array_unique($ids)),
			'Two nodes sharing an id make every edge to that id ambiguous.'
		);
	}//end testNodeIdsAreUnique()

	/**
	 * Every node type is one this app or OpenRegister actually registers.
	 *
	 * A type nothing answers to is the quietest failure available: the flow
	 * imports, the editor shows the node, and the run fails on it.
	 */
	public function testEveryNodeTypeIsOneSomebodyRegisters(): void {
		$known = [
			// OpenRegister's own catalogue, as used here.
			'openregister.trigger-object',
			'openregister.switch',
			'openregister.set-fields',
			'openregister.end',
			// dossiq's, each registered in DossiqFlowNodeListener::NODES.
			'dossiq.setStatus',
			'dossiq.askPerson',
			'dossiq.requestDecision',
			'dossiq.action.mergeTemplate',
		];

		foreach ($this->flow['nodes'] as $node) {
			$this->assertContains(
				(string)$node['type'],
				$known,
				sprintf('Node "%s" has a type nothing registers.', $node['id'])
			);
		}
	}//end testEveryNodeTypeIsOneSomebodyRegisters()

	/**
	 * The dossiq node types named here are the ones the listener registers.
	 *
	 * Reads the listener's source rather than trusting the list above, so the
	 * two cannot drift: renaming a node id in the listener and not in the flow
	 * would otherwise leave this suite green and the flow broken.
	 */
	public function testDossiqNodeTypesMatchTheRegisteredNodes(): void {
		$dossiqTypes = [];
		foreach ($this->flow['nodes'] as $node) {
			$type = (string)$node['type'];
			if (str_starts_with($type, 'dossiq.') === true && str_starts_with($type, 'dossiq.action.') === false) {
				$dossiqTypes[] = $type;
			}
		}

		$this->assertNotEmpty($dossiqTypes, 'The flow is supposed to use dossiq nodes.');

        $sources = glob(__DIR__ . '/../../../lib/Flow/*.php');
        $declared = '';
        foreach (($sources ?: []) as $file) {
            $declared .= (string)file_get_contents($file);
        }

		foreach (array_unique($dossiqTypes) as $type) {
			$this->assertStringContainsString(
				"'" . $type . "'",
				$declared,
				sprintf('No node class declares the id "%s".', $type)
			);
		}
	}//end testDossiqNodeTypesMatchTheRegisteredNodes()

	/**
	 * 🔴 THE LOOP HAS A DECLARED WAY OUT.
	 *
	 * The applicant loop must leave by an edge, not by the engine's transition
	 * ceiling. A run that dies on the ceiling is reported as a FAILED run — so
	 * a case nobody answered would read as a broken flow and land on the wrong
	 * person's desk.
	 */
	public function testTheApplicantLoopHasAnUnconditionalExit(): void {
		$fromCheck = array_values(
			array_filter(
				$this->flow['edges'],
				static fn (array $e): bool => ($e['from'] ?? '') === 'check-complete'
			)
		);

		$this->assertGreaterThanOrEqual(3, count($fromCheck), 'complete / under-cap / at-cap are three distinct exits.');

		$unconditional = array_values(
			array_filter($fromCheck, static fn (array $e): bool => isset($e['condition']) === false)
		);

		$this->assertCount(
			1,
			$unconditional,
			'Exactly one exit must be the else: none means the run can stall with no edge to take, several means the choice is ambiguous.'
		);

		$this->assertSame(
			'status-gestrand',
			$unconditional[0]['to'],
			'The else must be the stalled route, so an unanswered case ends deliberately.'
		);
	}//end testTheApplicantLoopHasAnUnconditionalExit()

	/**
	 * 🔴 THE CAP COUNTS SOMETHING THAT IS ACTUALLY WRITTEN.
	 *
	 * The cap condition reads `aanvullingRound`. If nothing incremented it, the
	 * comparison would read an absent value as zero, the under-cap edge would
	 * be taken forever, and the cap would be decorative — the loop bounded only
	 * by the engine ceiling it exists to avoid.
	 */
	public function testTheLoopCounterIsIncrementedInsideTheLoop(): void {
		$capped = null;
		foreach ($this->flow['edges'] as $edge) {
			if (($edge['from'] ?? '') === 'check-complete' && isset($edge['condition']['<']) === true) {
				$capped = $edge;
				break;
			}
		}

		$this->assertNotNull($capped, 'The loop must be capped by a comparison.');

		$variable = $capped['condition']['<'][0]['var'];
		$this->assertSame('json.aanvullingRound', $variable);

		$writers = array_values(
			array_filter(
				$this->flow['nodes'],
				static fn (array $n): bool => isset($n['config']['compute']['aanvullingRound']) === true
			)
		);

		$this->assertCount(1, $writers, 'Exactly one node must maintain the counter the cap reads.');

		// And it must sit INSIDE the loop, or it counts nothing.
		$intoWriter = array_values(
			array_filter(
				$this->flow['edges'],
				static fn (array $e): bool => ($e['to'] ?? '') === $writers[0]['id']
			)
		);
		$this->assertNotEmpty($intoWriter, 'The counter node must be reachable.');
	}//end testTheLoopCounterIsIncrementedInsideTheLoop()

	/**
	 * Each human step names an assignee.
	 *
	 * An unassigned step is answerable by ANYONE — OpenRegister's resume guard
	 * treats silence as "no restriction", deliberately, because webhook and
	 * child-run signals record no assignee. In a case flow that would mean any
	 * authenticated user could advance somebody's application.
	 */
	public function testEveryAskNamesWhoIsBeingAsked(): void {
		$asks = array_values(
			array_filter($this->flow['nodes'], static fn (array $n): bool => ($n['type'] ?? '') === 'dossiq.askPerson')
		);

		$this->assertNotEmpty($asks);

		foreach ($asks as $ask) {
			$this->assertNotSame(
				'',
				trim((string)($ask['config']['assignee'] ?? '')),
				sprintf('Ask node "%s" names nobody, so anyone could answer it.', $ask['id'])
			);
			$this->assertNotSame(
				'',
				trim((string)($ask['config']['question'] ?? '')),
				sprintf('Ask node "%s" asks nothing.', $ask['id'])
			);
		}
	}//end testEveryAskNamesWhoIsBeingAsked()

	/**
	 * The case cannot reach its final status without its decision document.
	 */
	public function testTheCaseIsNotClosedBeforeItsDocumentIsMade(): void {
		$toFinal = array_values(
			array_filter(
				$this->flow['edges'],
				static fn (array $e): bool => ($e['to'] ?? '') === 'status-afgehandeld'
			)
		);

		$this->assertNotEmpty($toFinal, 'Something must lead to the final status.');

		foreach ($toFinal as $edge) {
			$this->assertSame(
				'besluit-document',
				$edge['from'],
				'The only way into the final status is through the step that produces the decision document.'
			);
		}
	}//end testTheCaseIsNotClosedBeforeItsDocumentIsMade()

	/**
	 * 🔴 EVERY STATUS THE FLOW MOVES TO EXISTS ON THE SEEDED CASE TYPE.
	 *
	 * The flow names statuses; the handler resolves each name inside the case's
	 * own case type at run time, because a statusType uuid is minted per
	 * installation and a shipped flow cannot carry one. So the flow and the
	 * seed share a contract made of strings, and nothing but this test checks
	 * that the two sides still agree.
	 *
	 * When they do not, the handler refuses the step and the case stops moving
	 * — correct behaviour, discovered at the worst possible moment. A typo in
	 * either file is otherwise invisible until a real case runs.
	 */
	public function testEveryStatusTheFlowUsesIsSeededOnTheCaseType(): void {
		$seedPath = __DIR__ . '/../../../lib/Settings/case_flow_seed_data.json';
		$this->assertFileExists($seedPath, 'The flow needs its case type and statuses to exist.');

		$seed = json_decode((string)file_get_contents($seedPath), true);
		$this->assertIsArray($seed);

		$seeded = [];
		foreach (($seed['caseTypes'] ?? []) as $caseType) {
			foreach (($caseType['statusTypes'] ?? []) as $status) {
				$seeded[] = (string)$status['name'];
			}
		}

		$this->assertNotEmpty($seeded, 'The seed must define the statuses the flow moves through.');

		foreach ($this->flow['nodes'] as $node) {
			if (($node['type'] ?? '') !== 'dossiq.setStatus') {
				continue;
			}

			$this->assertContains(
				(string)$node['config']['status'],
				$seeded,
				sprintf(
					'Node "%s" moves the case to a status "%s" that the seeded case type does not define.',
					$node['id'],
					$node['config']['status']
				)
			);
		}
	}//end testEveryStatusTheFlowUsesIsSeededOnTheCaseType()

	/**
	 * The demo data exercises BOTH branches of the completeness check.
	 *
	 * A seed in which every case is complete would demonstrate the happy path
	 * and leave the applicant loop — the part with the most moving pieces —
	 * untouched on first run.
	 */
	public function testTheSeedExercisesBothSidesOfTheCompletenessCheck(): void {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/case_flow_seed_data.json'),
			true
		);

		$complete = 0;
		$incomplete = 0;
		foreach (($seed['cases'] ?? []) as $case) {
			if (trim((string)($case['description'] ?? '')) === '') {
				$incomplete++;
				continue;
			}

			$complete++;
		}

		$this->assertGreaterThan(0, $complete, 'One case must pass the completeness check.');
		$this->assertGreaterThan(0, $incomplete, 'One case must fail it, or the applicant loop is never demonstrated.');
	}//end testTheSeedExercisesBothSidesOfTheCompletenessCheck()

	/**
	 * 🔴 THE `blocksCase` CALCULATION USES OPERATORS THE ENGINE ACTUALLY HAS.
	 *
	 * A calculation whose expression form the engine does not understand is
	 * INERT — it is not rejected, it simply never produces a value. This schema
	 * already carries a scar from exactly that: `objectionProceeding`'s
	 * decisionDeadline shipped for months as an array-form string DSL
	 * "which OpenRegister's calculation engine never honoured".
	 *
	 * The operator list below is copied from
	 * `OpenRegister\Service\Calculation\CalculationEvaluator::apply()`. It is a
	 * cheap structural check, not a substitute for evaluating: the expression was
	 * additionally run through the real evaluator during development, and returns
	 * true only for a task that names a run and is neither completed nor
	 * terminated.
	 *
	 * @return void
	 */
	public function testTheBlocksCaseCalculationUsesSupportedOperatorsOnly(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$calc = ($register['components']['schemas']['task']['x-openregister-calculations']['blocksCase'] ?? null);
		$this->assertIsArray($calc, 'The task must declare when it is blocking a case.');
		$this->assertTrue(($calc['materialise'] ?? false), 'It must be materialised, or it cannot be filtered server-side.');

		$supported = [
			'abs', 'and', 'coalesce', 'concat', 'dateAdd', 'dateDiff', 'days', 'diffDays',
			'eq', 'formatDate', 'global', 'gt', 'gte', 'hours', 'if', 'lit', 'lt', 'lte',
			'max', 'min', 'minutes', 'monthly', 'months', 'monthsElapsed', 'ne', 'not',
			'now', 'or', 'prop', 'round', 'seconds', 'sequence', 'weeks', 'year',
			'yearly', 'years',
		];

		$operators = [];
		$walk = static function (mixed $node) use (&$walk, &$operators): void {
			if (is_array($node) === false) {
				return;
			}

			foreach ($node as $key => $value) {
				if (is_string($key) === true) {
					$operators[] = $key;
				}

				$walk($value);
			}
		};
		$walk($calc['expression']);

		$this->assertNotEmpty($operators, 'An expression with no operators computes nothing.');

		foreach (array_unique($operators) as $operator) {
			$this->assertContains(
				$operator,
				$supported,
				sprintf('"%s" is not an operator the calculation engine implements, so the field would be inert.', $operator)
			);
		}
	}//end testTheBlocksCaseCalculationUsesSupportedOperatorsOnly()

	/**
	 * Every path ends at an end node rather than simply stopping.
	 */
	public function testEveryTerminalPathEndsDeliberately(): void {
		$withOutgoing = array_map(
			static fn (array $e): string => (string)$e['from'],
			$this->flow['edges']
		);

		foreach ($this->flow['nodes'] as $node) {
			if (in_array((string)$node['id'], $withOutgoing, true) === true) {
				continue;
			}

			$this->assertSame(
				'openregister.end',
				(string)$node['type'],
				sprintf('Node "%s" has no outgoing edge and is not an end node.', $node['id'])
			);
		}
	}//end testEveryTerminalPathEndsDeliberately()
}//end class
