<?php

/**
 * Decision document unit tests, on a neutral fixture case type.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decision-document/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionDocument;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DecisionDocument\DecisionAuthority;
use OCA\Dossiq\Service\DecisionDocument\DecisionDocumentDefinition;
use OCA\Dossiq\Service\DecisionDocument\DecisionDocumentLifecycle;
use OCA\Dossiq\Service\DecisionDocument\DecisionDocumentValidator;
use OCA\Dossiq\Service\DocumentSeries\DocumentSeriesNumberer;
use OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A stand-in for OpenRegister's named-condition evaluator with a tiny dialect:
 * `{"filled": field}` holds when the field is filled; `{"$condition": name}`
 * resolves a named condition; anything else is unresolvable and throws, as
 * the real evaluator does.
 */
class FixtureConditionEvaluator {

	/**
	 * Whether a condition holds.
	 *
	 * @param mixed $node The condition node.
	 * @param array<string, mixed> $document The document.
	 * @param array<string, mixed> $library The named conditions.
	 *
	 * @return bool True when it holds.
	 *
	 * @throws RuntimeException When the node cannot be resolved.
	 */
	public function holds(mixed $node, array $document, array $library): bool {
		if (is_array($node) === true && isset($node['filled']) === true) {
			return empty($document[$node['filled']]) === false;
		}

		if (is_array($node) === true && isset($node['$condition']) === true && isset($library[$node['$condition']]) === true) {
			return $this->holds(node: $library[$node['$condition']], document: $document, library: $library);
		}

		throw new RuntimeException('unresolvable condition');
	}//end holds()
}//end class

/**
 * The decision document capability on the fixture kind `notice` of case type `fixture-case`.
 *
 * @covers \OCA\Dossiq\Service\DecisionDocument\DecisionDocumentDefinition
 * @covers \OCA\Dossiq\Service\DecisionDocument\DecisionDocumentValidator
 * @covers \OCA\Dossiq\Service\DecisionDocument\DecisionDocumentLifecycle
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FrozenRecordGuard
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class DecisionDocumentTest extends TestCase {

	/**
	 * The neutral fixture case type. It stands for no procedure.
	 *
	 * @var array<string, mixed>
	 */
	private const FIXTURE_CASE_TYPE = [
		'id' => 'fixture-case',
		'decisionTypes' => ['https://ztc.test/besluittypen/1'],
		'decisionDocuments' => [
			'notice' => [
				'template' => 'fixture-template',
				'requiredFields' => ['decision', 'addressee'],
				'rules' => [
					['id' => 'has-rationale', 'when' => ['$condition' => 'reasoned'], 'message' => 'Give the reasons.'],
				],
				'conditions' => ['reasoned' => ['filled' => 'rationale']],
				'remedyClause' => 'You can object within six weeks.',
				'authorityAct' => 'fixture-act',
				'decisionKind' => 'fixture-kind',
				'series' => ['name' => 'notice', 'defaultPrefix' => 'N'],
			],
		],
	];

	/**
	 * A valid approved document of the fixture kind.
	 *
	 * @var array<string, mixed>
	 */
	private const VALID = [
		'kind' => 'notice',
		'caseId' => 'case-1',
		'currentStatus' => 'draft',
		'decision' => 'granted',
		'addressee' => ['name' => 'A. Person'],
		'rationale' => 'Because.',
		'remedyClause' => 'You can object within six weeks.',
	];

	/**
	 * The fixture definition.
	 *
	 * @return DecisionDocumentDefinition The definition.
	 */
	private function definition(): DecisionDocumentDefinition {
		$definition = DecisionDocumentDefinition::fromCaseType(caseType: self::FIXTURE_CASE_TYPE, kind: 'notice');
		self::assertNotNull($definition);

		return $definition;
	}//end definition()

	/**
	 * A container serving the fixture evaluator and, optionally, an authority.
	 *
	 * @param DecisionAuthority|null $authority The bound authority, or none.
	 * @param bool $evaluator Whether OpenRegister's evaluator is there.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(?DecisionAuthority $authority = null, bool $evaluator = true): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === DecisionAuthority::class && $authority !== null);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($authority, $evaluator): object {
				if ($id === DecisionAuthority::class && $authority !== null) {
					return $authority;
				}

				if ($id === 'OCA\OpenRegister\Service\Rules\NamedConditionEvaluator' && $evaluator === true) {
					return new FixtureConditionEvaluator();
				}

				throw new RuntimeException('not in the container: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * The lifecycle with the given container and numberer.
	 *
	 * @param ContainerInterface $container The container.
	 * @param DocumentSeriesNumberer|null $numberer The numberer.
	 *
	 * @return DecisionDocumentLifecycle The lifecycle.
	 */
	private function lifecycle(ContainerInterface $container, ?DocumentSeriesNumberer $numberer = null): DecisionDocumentLifecycle {
		return new DecisionDocumentLifecycle(
			validator: new DecisionDocumentValidator(container: $container),
			numberer: ($numberer ?? $this->createMock(DocumentSeriesNumberer::class)),
			container: $container,
		);
	}//end lifecycle()

	/**
	 * Assert a refusal's rule and status.
	 *
	 * @param callable $call The call.
	 * @param string $rule The rule.
	 * @param int $status The status.
	 *
	 * @return RefusedException The refusal.
	 */
	private function refused(callable $call, string $rule, int $status): RefusedException {
		try {
			$call();
		} catch (RefusedException $refusal) {
			self::assertSame($rule, $refusal->getRule());
			self::assertSame($status, $refusal->getStatus());
			return $refusal;
		}

		self::fail('Not refused: ' . $rule);
	}//end refused()

	/**
	 * The case type's block is read; a kind it does not configure is null.
	 *
	 * @return void
	 */
	public function testDefinitionFromCaseType(): void {
		$definition = $this->definition();

		self::assertSame('fixture-template', $definition->template);
		self::assertSame(['decision', 'addressee'], $definition->requiredFields);
		self::assertSame('notice', $definition->series);
		self::assertSame(DecisionDocumentDefinition::DEFAULT_FROZEN_STATUSES, $definition->frozenStatuses);
		self::assertNull(DecisionDocumentDefinition::fromCaseType(caseType: self::FIXTURE_CASE_TYPE, kind: 'other'));
	}//end testDefinitionFromCaseType()

	/**
	 * A kind that requires a remedy clause must configure one.
	 *
	 * @return void
	 */
	public function testRemedyClauseMustBeConfigured(): void {
		$this->expectException(InvalidArgumentException::class);
		DecisionDocumentDefinition::fromArray(kind: 'notice', block: ['remedyClause' => '']);
	}//end testRemedyClauseMustBeConfigured()

	/**
	 * A rule without a condition is refused.
	 *
	 * @return void
	 */
	public function testRuleWithoutConditionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		DecisionDocumentDefinition::fromArray(kind: 'notice', block: ['remedyRequired' => false, 'rules' => [['id' => 'x']]]);
	}//end testRuleWithoutConditionIsRefused()

	/**
	 * A draft carries the kind's template, remedy clause and decision kind; the drafter's fields win.
	 *
	 * @return void
	 */
	public function testDraft(): void {
		$draft = $this->lifecycle(container: $this->container())->draft(
			definition: $this->definition(),
			caseId: 'case-1',
			fields: ['template' => 'other-template', 'currentStatus' => 'signed'],
		);

		self::assertSame('draft', $draft['currentStatus']);
		self::assertSame('other-template', $draft['template']);
		self::assertSame('You can object within six weeks.', $draft['remedyClause']);
		self::assertSame('fixture-kind', $draft['decisionKind']);
		self::assertSame('case-1', $draft['caseId']);
	}//end testDraft()

	/**
	 * Missing fields, a missing remedy clause and a failing rule are all listed.
	 *
	 * @return void
	 */
	public function testValidatorListsEveryProblem(): void {
		$problems = (new DecisionDocumentValidator(container: $this->container()))->problems(
			definition: $this->definition(),
			document: ['decision' => ' ', 'remedyClause' => ''],
		);

		self::assertSame(['required', 'required', 'remedy-clause-required', 'has-rationale'], array_column($problems, 'rule'));
		self::assertSame('Give the reasons.', $problems[3]['message']);
	}//end testValidatorListsEveryProblem()

	/**
	 * A rule that cannot be evaluated is a problem, never a pass.
	 *
	 * @return void
	 */
	public function testUnevaluableRuleFailsClosed(): void {
		$problems = (new DecisionDocumentValidator(container: $this->container(evaluator: false)))->problems(
			definition: $this->definition(),
			document: self::VALID,
		);

		self::assertSame(['has-rationale'], array_column($problems, 'rule'));
		self::assertStringContainsString('could not be checked', $problems[0]['message']);
	}//end testUnevaluableRuleFailsClosed()

	/**
	 * An invalid document is not approved.
	 *
	 * @return void
	 */
	public function testInvalidDocumentIsNotApproved(): void {
		$this->refused(
			fn () => $this->lifecycle(container: $this->container())->transition(
				definition: $this->definition(),
				document: array_merge(self::VALID, ['rationale' => '']),
				to: 'approved',
				actor: 'alice',
			),
			'decision-document-invalid',
			RefusedException::STATUS_UNPROCESSABLE
		);
	}//end testInvalidDocumentIsNotApproved()

	/**
	 * Without a bound authority check, a kind that names an act is not approved.
	 *
	 * @return void
	 */
	public function testApprovalWithoutAuthorityCheckFailsClosed(): void {
		$this->refused(
			fn () => $this->lifecycle(container: $this->container())->transition(
				definition: $this->definition(),
				document: self::VALID,
				to: 'approved',
				actor: 'alice',
			),
			'decision-authority-unavailable',
			RefusedException::STATUS_INDETERMINATE
		);
	}//end testApprovalWithoutAuthorityCheckFailsClosed()

	/**
	 * The authority is asked for the configured act, and its refusal stands.
	 *
	 * @return void
	 */
	public function testAuthorityDecides(): void {
		$authority = $this->createMock(DecisionAuthority::class);
		$authority->expects(self::exactly(2))->method('assertMayDecide')
			->with('case-1', 'fixture-act', self::anything(), self::anything())
			->willReturnCallback(
				static function (string $caseId, string $act, string $actor): void {
					if ($actor === 'mallory') {
						throw new RefusedException(rule: 'no-authority', sentence: 'No.', status: RefusedException::STATUS_FORBIDDEN);
					}
				}
			);
		$lifecycle = $this->lifecycle(container: $this->container(authority: $authority));
		$moment = new DateTimeImmutable('2026-10-10T09:00:00+00:00');

		$changes = $lifecycle->transition(definition: $this->definition(), document: self::VALID, to: 'approved', actor: 'alice', moment: $moment);
		self::assertSame(['currentStatus' => 'approved', 'approvedBy' => 'alice', 'approvedAt' => '2026-10-10T09:00:00+00:00'], $changes);

		$this->refused(
			fn () => $lifecycle->transition(definition: $this->definition(), document: self::VALID, to: 'approved', actor: 'mallory'),
			'no-authority',
			RefusedException::STATUS_FORBIDDEN
		);
	}//end testAuthorityDecides()

	/**
	 * Signing needs the signature and gives the document its number, once.
	 *
	 * @return void
	 */
	public function testSigningNumbersTheDocument(): void {
		$numberer = $this->createMock(DocumentSeriesNumberer::class);
		$numberer->expects(self::once())->method('issue')->with('case-1', 'notice', 'N', self::anything())->willReturn('N-2026-000001');
		$lifecycle = $this->lifecycle(container: $this->container(), numberer: $numberer);
		$approved = array_merge(self::VALID, ['currentStatus' => 'approved']);

		$this->refused(
			fn () => $lifecycle->transition(definition: $this->definition(), document: $approved, to: 'signed', actor: 'alice'),
			'decision-document-unsigned',
			RefusedException::STATUS_UNPROCESSABLE
		);

		$changes = $lifecycle->transition(definition: $this->definition(), document: array_merge($approved, ['signature' => ['by' => 'alice']]), to: 'signed', actor: 'alice');
		self::assertSame('signed', $changes['currentStatus']);
		self::assertSame('N-2026-000001', $changes['reference']);

		$renumbered = $lifecycle->transition(
			definition: $this->definition(),
			document: array_merge($approved, ['signature' => ['by' => 'alice'], 'reference' => 'N-2026-000001']),
			to: 'signed',
			actor: 'alice',
		);
		self::assertArrayNotHasKey('reference', $renumbered);
	}//end testSigningNumbersTheDocument()

	/**
	 * Only the declared transitions exist.
	 *
	 * @return void
	 */
	public function testUndeclaredTransitionIsRefused(): void {
		$this->refused(
			fn () => $this->lifecycle(container: $this->container())->transition(
				definition: $this->definition(),
				document: array_merge(self::VALID, ['currentStatus' => 'signed']),
				to: 'draft',
				actor: 'alice',
			),
			'decision-document-transition',
			RefusedException::STATUS_REFUSED
		);

		$sent = $this->lifecycle(container: $this->container())->transition(
			definition: $this->definition(),
			document: array_merge(self::VALID, ['currentStatus' => 'signed']),
			to: 'sent',
			actor: 'alice',
			moment: new DateTimeImmutable('2026-10-11T08:00:00+00:00'),
		);
		self::assertSame(['currentStatus' => 'sent', 'sentAt' => '2026-10-11T08:00:00+00:00'], $sent);
	}//end testUndeclaredTransitionIsRefused()

	/**
	 * The kind's freeze entry holds a signed document's content still (C04).
	 *
	 * @return void
	 */
	public function testSignedDocumentIsFrozen(): void {
		$freeze = $this->definition()->freezeDefinition(schema: 'fixtureDecision');
		$guard = new FrozenRecordGuard();
		$signed = array_merge(self::VALID, ['currentStatus' => 'signed', 'reference' => 'N-2026-000001']);

		self::assertSame('decision-document-frozen', $freeze->rule);
		self::assertTrue($guard->isFrozen(definition: $freeze, stored: $signed));
		$this->refused(
			fn () => $guard->assertUpdatable(definition: $freeze, stored: $signed, incoming: array_merge($signed, ['decision' => 'refused'])),
			'decision-document-frozen',
			RefusedException::STATUS_REFUSED
		);
		$guard->assertUpdatable(definition: $freeze, stored: $signed, incoming: array_merge($signed, ['currentStatus' => 'sent', 'sentAt' => '2026-10-11']));
	}//end testSignedDocumentIsFrozen()
}//end class
