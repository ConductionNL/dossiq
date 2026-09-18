<?php

/**
 * An ask step may ask for fields, and a declaration nobody could fill is
 * refused while the author is still there to fix it.
 *
 * 🔴 WHAT IS DOSSIQ'S HALF, AND WHAT IS NOT. The rules about which kinds
 * exist, which field lists are coherent, and which properties of a live schema
 * a form could render belong to
 * `OCA\OpenRegister\Service\Task\TaskFormReader` and are asserted there, in
 * `tests/Unit/Service/Task/TaskFormReaderTest.php`. Re-asserting them here
 * would need a fake reader, and a fake of somebody else's rules can only ever
 * pass. So what is driven below is exactly dossiq's half:
 *
 *  - a step with no form key does not reach the reader at all, so a step
 *    authored before this change behaves as it did;
 *  - a step with ANY form key is handed to `fromConfig()` WHOLE, and what
 *    comes back is handed to `validate()`;
 *  - a refusal travels unchanged, so the author reads openregister's wording
 *    rather than a dossiq paraphrase of it they could not search for;
 *  - an instance with no reader REFUSES a declared form rather than accepting
 *    it unchecked.
 *
 * 🔴 THE KEYS ARE FLAT, AND THAT IS WHAT THE LAST CASE PINS. A task raised by
 * a status transition carries a nested `task.form` block, which
 * `OCA\Dossiq\Service\Task\TaskDeclaration` writes to `metadata.form`. A task
 * raised by a FLOW NODE carries no such block: `TaskFormResolver` matches the
 * task's node id in the run's pinned graph and reads `node['config']` through
 * `TaskFormReader::fromConfig()`, which reads `formKind`, `formSchema`,
 * `formAction`, `formFields`, `formId` and `formRequireChecklist`. A nested
 * `form` block on an ask step would leave `formKind` absent, produce a
 * declaration whose `hasForm()` is false, and hand the assignee a task with no
 * fields and no error anywhere.
 *
 * SEAM CHECKED RATHER THAN ASSUMED (task 3.1). Read against openregister
 * `parity/round2`, `lib/Service/Task/TaskFormResolver.php:197-201`: the
 * resolver matches on `$node['id'] === $task->getNodeId()` and then reads
 * `$node['config']`. It never asks what TYPE the node is, so an ask step's
 * config resolves exactly as `openregister.user-task`'s does, and the form
 * comes from the version the run is pinned to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-ask-step-asks-for-fields/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqAskPersonNode;
use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use UnexpectedValueException;

class AskPersonFormDeclarationTest extends TestCase {

	/**
	 * A reader double.
	 *
	 * `onlyMethods` and not `addMethods`: a double that could invent a method
	 * the real reader lacks would pass here and 500 in production, which is
	 * exactly the shape of defect this app has shipped before.
	 *
	 * @return TaskFormReader&MockObject The double.
	 */
	private function reader(): TaskFormReader {
		return $this->getMockBuilder(TaskFormReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['fromConfig', 'validate'])
			->getMock();
	}//end reader()

	/**
	 * A localisation double that SUBSTITUTES its placeholders, so a refusal
	 * still names what it is about.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		return $l10n;
	}//end l10n()

	/**
	 * The node under test.
	 *
	 * @param TaskFormReader|null $reader The form reader, or null for an
	 *                                    instance that has no task engine.
	 *
	 * @return DossiqAskPersonNode The node.
	 */
	private function node(?TaskFormReader $reader): DossiqAskPersonNode {
		// Nothing below reaches storage: every case refuses or returns inside
		// validateConfig, and one that did not would fail on the missing run.
		$engineTasks = new class extends EngineTaskGateway {
			/**
			 * No dependencies: nothing here is called.
			 */
			public function __construct() {
			}
		};

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('author');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DossiqAskPersonNode(
			new AssigneeResolver(new NullLogger()),
			$this->l10n(),
			new NullLogger(),
			$engineTasks,
			$session,
			$reader
		);
	}//end node()

	/**
	 * A step that asks a question of somebody, plus whatever form keys.
	 *
	 * @param array<string, mixed> $form The form keys to add.
	 *
	 * @return array<string, mixed> The step configuration.
	 */
	private function step(array $form = []): array {
		return array_merge(
			[
				'question' => 'Hoor de belanghebbende',
				'assignee' => 'jurist',
			],
			$form
		);
	}//end step()

	/**
	 * A step with no form never reaches the reader, so it behaves exactly as
	 * it did before this change.
	 *
	 * @return void
	 */
	public function testAStepWithNoFormNeverReachesTheReader(): void {
		$reader = $this->reader();
		$reader->expects($this->never())->method('fromConfig');
		$reader->expects($this->never())->method('validate');

		$this->node($reader)->validateConfig($this->step());
	}//end testAStepWithNoFormNeverReachesTheReader()

	/**
	 * A declared form is handed to the reader WHOLE, and what comes back is
	 * handed to the validator.
	 *
	 * The config is asserted whole rather than key by key because
	 * `fromConfig()` reads six keys off it: a node that forwarded a filtered
	 * copy would drop whichever one it had not been told about, and the form
	 * would resolve as something the author did not write.
	 *
	 * @return void
	 */
	public function testADeclaredFormIsReadAndThenValidated(): void {
		$config = $this->step(
			[
				'formKind' => 'fields',
				'formSchema' => 'case',
				'formFields' => [['field' => 'verslag', 'required' => true]],
			]
		);
		$declaration = new TaskForm(
			kind: TaskForm::KIND_FIELDS,
			schema: 'case',
			fields: [['field' => 'verslag', 'required' => true]]
		);

		$reader = $this->reader();
		$reader->expects($this->once())
			->method('fromConfig')
			->with($config)
			->willReturn($declaration);
		$reader->expects($this->once())
			->method('validate')
			->with($declaration);

		$this->node($reader)->validateConfig($config);
	}//end testADeclaredFormIsReadAndThenValidated()

	/**
	 * Each form key on its own is enough to reach the reader.
	 *
	 * `formFields` without `formKind` is the orphan the reader refuses, and it
	 * is the shape an author lands in by copying the transition path's nested
	 * block. A node that only looked at `formKind` would let it through.
	 *
	 * @return void
	 */
	public function testEveryFormKeyOnItsOwnReachesTheReader(): void {
		$keys = [
			'formKind' => 'fields',
			'formSchema' => 'case',
			'formAction' => 'hoor',
			'formFields' => [['field' => 'verslag']],
			'formId' => '7',
			'formRequireChecklist' => true,
		];

		foreach ($keys as $key => $value) {
			$reader = $this->reader();
			$reader->expects($this->once())
				->method('fromConfig')
				->willReturn(new TaskForm(kind: TaskForm::KIND_FIELDS, schema: 'case'));
			$reader->expects($this->once())->method('validate');

			$this->node($reader)->validateConfig($this->step([$key => $value]));
		}
	}//end testEveryFormKeyOnItsOwnReachesTheReader()

	/**
	 * A key present but empty is not a declaration: an author who cleared the
	 * field is back to a step that asks nothing, not one that is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyFormKeyIsNotADeclaration(): void {
		$reader = $this->reader();
		$reader->expects($this->never())->method('fromConfig');

		$this->node($reader)->validateConfig(
			$this->step(['formKind' => '', 'formFields' => []])
		);
	}//end testAnEmptyFormKeyIsNotADeclaration()

	/**
	 * A refusal from the reader travels unchanged, wording and all.
	 *
	 * An author who reads one wording in openregister and a dossiq paraphrase
	 * of it here cannot search for either.
	 *
	 * @return void
	 */
	public function testTheReadersRefusalTravelsUnchanged(): void {
		$reader = $this->reader();
		$reader->method('fromConfig')->willThrowException(
			new UnexpectedValueException('Field "verslagje" of schema "case" cannot be asked for: the schema has no such property.')
		);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Field "verslagje" of schema "case" cannot be asked for: the schema has no such property.');

		$this->node($reader)->validateConfig(
			$this->step(
				[
					'formKind' => 'fields',
					'formSchema' => 'case',
					'formFields' => [['field' => 'verslagje']],
				]
			)
		);
	}//end testTheReadersRefusalTravelsUnchanged()

	/**
	 * A field-level refusal from `validate()` travels too.
	 *
	 * The two calls refuse different things — the shape and the fields — and a
	 * node that forwarded only the first would accept every read-only field an
	 * author could name.
	 *
	 * @return void
	 */
	public function testTheValidatorsRefusalTravelsToo(): void {
		$reader = $this->reader();
		$reader->method('fromConfig')->willReturn(
			new TaskForm(kind: TaskForm::KIND_FIELDS, schema: 'case', fields: [['field' => 'identifier', 'required' => true]])
		);
		$reader->method('validate')->willThrowException(
			new UnexpectedValueException('Field "identifier" of schema "case" cannot be asked for: the schema marks it read-only, so a submitted value would be refused.')
		);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/identifier/');
		$this->expectExceptionMessageMatches('/read-only/');

		$this->node($reader)->validateConfig(
			$this->step(
				[
					'formKind' => 'fields',
					'formSchema' => 'case',
					'formFields' => [['field' => 'identifier', 'required' => true]],
				]
			)
		);
	}//end testTheValidatorsRefusalTravelsToo()

	/**
	 * An instance with no reader REFUSES a step declaring a form.
	 *
	 * Accepting it unchecked lands the broken declaration on the performer,
	 * who can neither fill the field nor skip it, and who is not the person
	 * who can fix it.
	 *
	 * @return void
	 */
	public function testAFormWithNoReaderIsRefusedRatherThanSkipped(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/cannot be checked/');

		$this->node(null)->validateConfig(
			$this->step(
				[
					'formKind' => 'fields',
					'formSchema' => 'case',
					'formFields' => [['field' => 'verslag']],
				]
			)
		);
	}//end testAFormWithNoReaderIsRefusedRatherThanSkipped()

	/**
	 * A step with no form still validates on an instance with no reader: the
	 * refusal above is about declarations, not about the engine's absence.
	 *
	 * @return void
	 */
	public function testNoFormAndNoReaderStillValidates(): void {
		$this->node(null)->validateConfig($this->step());

		$this->addToAssertionCount(1);
	}//end testNoFormAndNoReaderStillValidates()

	/**
	 * The two refusals this node owned before are unchanged.
	 *
	 * @return void
	 */
	public function testTheQuestionAndTheAssigneeAreStillRequired(): void {
		$node = $this->node($this->reader());

		try {
			$node->validateConfig(['assignee' => 'jurist']);
			$this->fail('a step with no question was accepted');
		} catch (UnexpectedValueException) {
			$this->addToAssertionCount(1);
		}

		try {
			$node->validateConfig(['question' => 'Hoor de belanghebbende']);
			$this->fail('a step with no assignee was accepted');
		} catch (UnexpectedValueException) {
			$this->addToAssertionCount(1);
		}
	}//end testTheQuestionAndTheAssigneeAreStillRequired()
}//end class
