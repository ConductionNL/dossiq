<?php

/**
 * A submitted Nextcloud Forms form, offered to the case-type binding.
 *
 * 🔴 IT NAMES NO FORMS CLASS AND TYPE HINTS NOTHING FROM `OCA\Forms`. The
 * `forms` app is optional. A type hint on a class the instance does not have
 * is a fatal when the container builds the listener, not a feature that is
 * quietly missing, so the event is taken as the framework's own `Event` and
 * read with duck typing. The registration is guarded on `class_exists`, and a
 * `::class` string does not autoload, so an instance without Forms boots
 * exactly as it does today and this listener is never constructed.
 *
 * 🔴 THE EVENT CLASS NAME IS THE ONE THING NOT VERIFIED IN THIS LANE. No
 * Forms app is installed in this checkout, so {@see EVENT} is written from the
 * app's published API and confirmed on a live instance, not here. It is a
 * single constant on purpose: if the name is wrong, `class_exists` answers
 * false, nothing registers, no submission opens a case, and the correction is
 * one line rather than a rewrite. It fails towards doing nothing, which for an
 * intake path that creates work is the right direction.
 *
 * 🔴 IT NEVER DECIDES WHETHER A CASE IS OPENED. That is
 * {@see FormsIntakeService}, which answers null for a form no case type bound.
 * This class reads a form hash, a date and the answers off whatever shape the
 * event carries, and hands them over.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\FormsIntakeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Hands a Forms submission to the case-type binding.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
 */
class FormSubmittedListener implements IEventListener {

	/**
	 * The Forms event this listener is registered for.
	 *
	 * A plain string, never imported: see the class docblock.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\\Forms\\Events\\FormSubmittedEvent';

	/**
	 * Constructor.
	 *
	 * @param FormsIntakeService $intake Decides whether a submission opens a case.
	 */
	public function __construct(
		private readonly FormsIntakeService $intake,
	) {
	}//end __construct()

	/**
	 * Offer one submission to the binding.
	 *
	 * @param Event $event The Forms submission event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
	 */
	public function handle(Event $event): void {
		$form = $this->call(subject: $event, method: 'getForm');
		$hash = trim((string)$this->read(subject: $form, key: 'hash'));
		if ($hash === '') {
			return;
		}

		$submission = $this->call(subject: $event, method: 'getSubmission');

		$this->intake->caseFor(
			formHash: $hash,
			answers: $this->answers(submission: $submission),
			submitted: (string)$this->read(subject: $submission, key: 'timestamp'),
		);
	}//end handle()

	/**
	 * The answers, question text to value.
	 *
	 * @param mixed $submission The submission the event carries.
	 *
	 * @return array<string, mixed> The answers.
	 */
	private function answers(mixed $submission): array {
		$answers = $this->call(subject: $submission, method: 'getAnswers');
		if (is_array($answers) === false) {
			return [];
		}

		$rows = [];
		foreach ($answers as $answer) {
			$question = trim((string)$this->read(subject: $answer, key: 'questionText'));
			if ($question === '') {
				$question = trim((string)$this->read(subject: $answer, key: 'questionId'));
			}

			if ($question === '') {
				continue;
			}

			$rows[$question] = $this->read(subject: $answer, key: 'text');
		}

		return $rows;
	}//end answers()

	/**
	 * Call a method on the event's payload, when it has one.
	 *
	 * @param mixed  $subject The object to ask.
	 * @param string $method  The method name.
	 *
	 * @return mixed The answer, or null.
	 */
	private function call(mixed $subject, string $method): mixed {
		if (is_object($subject) === false || method_exists($subject, $method) === false) {
			return null;
		}

		return $subject->$method();
	}//end call()

	/**
	 * Read one field off an entity, array or getter.
	 *
	 * Forms' entities are Nextcloud `Entity` objects, so a field is reachable
	 * as a property and as `getField()`. Both are tried, and an array is
	 * accepted too, because an event that hands over plain rows is the shape
	 * a test uses.
	 *
	 * @param mixed  $subject The thing to read.
	 * @param string $key     The field name.
	 *
	 * @return mixed The value, or null.
	 */
	private function read(mixed $subject, string $key): mixed {
		if (is_array($subject) === true) {
			return ($subject[$key] ?? null);
		}

		if (is_object($subject) === false) {
			return null;
		}

		$getter = 'get' . ucfirst($key);
		if (method_exists($subject, $getter) === true) {
			return $subject->$getter();
		}

		if (property_exists($subject, $key) === true) {
			return $subject->$key;
		}

		return null;
	}//end read()
}//end class
