<?php

/**
 * Dossiq FormSubmittedListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\FormSubmittedListener;
use OCA\Dossiq\Service\FormsIntakeService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * A Forms submission opens a case for its form, with its answers keyed by
 * question; a submission without a form hash opens nothing.
 *
 * @covers \OCA\Dossiq\Listener\FormSubmittedListener
 */
class FormSubmittedListenerTest extends TestCase {

	/**
	 * The answers are keyed by question text, falling back to the question id,
	 * and read from arrays, getters and public properties alike.
	 *
	 * @return void
	 */
	public function testTheAnswersAreKeyedByQuestion(): void {
		$byProperty = new \stdClass();
		$byProperty->questionText = 'Street';
		$byProperty->text = 'Main 1';

		$byGetter = new class {
			/**
			 * The question.
			 *
			 * @return string
			 */
			public function getQuestionText(): string {
				return 'Phone';
			}

			/**
			 * The answer.
			 *
			 * @return string
			 */
			public function getText(): string {
				return '0612345678';
			}
		};

		$intake = $this->createMock(FormsIntakeService::class);
		$intake->expects($this->once())->method('caseFor')->with(
			'abc123',
			[
				'Name' => 'Ada',
				'q-7' => 'yes',
				'Street' => 'Main 1',
				'Phone' => '0612345678',
			]
		)->willReturn('case-1');

		(new FormSubmittedListener(intake: $intake))->handle(
			$this->submitted(
				form: ['hash' => ' abc123 '],
				answers: [
					['questionText' => 'Name', 'text' => 'Ada'],
					['questionText' => '  ', 'questionId' => 'q-7', 'text' => 'yes'],
					['text' => 'orphan'],
					'not an answer',
					$byProperty,
					$byGetter,
				]
			)
		);
	}//end testTheAnswersAreKeyedByQuestion()

	/**
	 * A form object is read through its getter.
	 *
	 * @return void
	 */
	public function testAFormObjectIsReadThroughItsGetter(): void {
		$form = new class {
			/**
			 * The hash.
			 *
			 * @return string
			 */
			public function getHash(): string {
				return 'h-1';
			}
		};

		$intake = $this->createMock(FormsIntakeService::class);
		$intake->expects($this->once())->method('caseFor')->with('h-1', []);

		(new FormSubmittedListener(intake: $intake))->handle($this->submitted(form: $form, answers: null));
	}//end testAFormObjectIsReadThroughItsGetter()

	/**
	 * Events that open nothing.
	 *
	 * @return array<string, array{0: Event}>
	 */
	public static function nothing(): array {
		return [
			'no form' => [self::event(form: null, answers: [])],
			'an empty hash' => [self::event(form: ['hash' => '  '], answers: [])],
			'a form without a hash' => [self::event(form: new \stdClass(), answers: [])],
			'not a forms event' => [new Event()],
		];
	}//end nothing()

	/**
	 * Without a hash there is no form to look up, so no case is opened.
	 *
	 * @param Event $event The event.
	 *
	 * @dataProvider nothing
	 *
	 * @return void
	 */
	public function testNoHashOpensNothing(Event $event): void {
		$intake = $this->createMock(FormsIntakeService::class);
		$intake->expects($this->never())->method('caseFor');

		(new FormSubmittedListener(intake: $intake))->handle($event);
	}//end testNoHashOpensNothing()

	/**
	 * The event a submission fires.
	 *
	 * @param mixed      $form    The form.
	 * @param array|null $answers The answers, or null for no getter result.
	 *
	 * @return Event
	 */
	private function submitted(mixed $form, ?array $answers): Event {
		return self::event(form: $form, answers: $answers);
	}//end submitted()

	/**
	 * Build the event.
	 *
	 * @param mixed      $form    The form.
	 * @param array|null $answers The answers.
	 *
	 * @return Event
	 */
	private static function event(mixed $form, ?array $answers): Event {
		$submission = new class ($answers) {
			/**
			 * Constructor.
			 *
			 * @param array|null $answers The answers.
			 */
			public function __construct(private readonly ?array $answers) {
			}

			/**
			 * The answers.
			 *
			 * @return array|null
			 */
			public function getAnswers(): ?array {
				return $this->answers;
			}
		};

		return new class ($form, $submission) extends Event {
			/**
			 * Constructor.
			 *
			 * @param mixed  $form       The form.
			 * @param object $submission The submission.
			 */
			public function __construct(private readonly mixed $form, private readonly object $submission) {
				parent::__construct();
			}

			/**
			 * The form.
			 *
			 * @return mixed
			 */
			public function getForm(): mixed {
				return $this->form;
			}

			/**
			 * The submission.
			 *
			 * @return object
			 */
			public function getSubmission(): object {
				return $this->submission;
			}
		};
	}//end event()
}//end class
