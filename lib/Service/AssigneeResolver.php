<?php

/**
 * Who a piece of work goes to.
 *
 * 🔴 THIS IS THE ONLY PLACE THAT ANSWERS THAT QUESTION, AND IT IS ONE PLACE ON
 * PURPOSE. The rule was written once, inline, in `DossiqAskPersonNode`, and it
 * is not a simple rule: a declaration cannot name a real person because the uid
 * differs per case, so it writes `{{ case.assignee }}`, and somebody has to
 * render it. The engine does not; it templates only inside its own set-fields
 * and object-read nodes. Storing the literal is what orphaned every applicant
 * task live, because the resume guard compared real uids against an unrendered
 * placeholder and refused all of them.
 *
 * And an empty rendering is not the same as no assignee. `assignee` is NOT in
 * the case schema's `required`: a case filed from the New case dialog with only
 * a title and a case type has none, `{{ case.assignee }}` resolves to nothing,
 * and refusing outright killed runs twice on clean installs. So a caller may
 * declare a FALLBACK: a second choice, written down, not silently chosen.
 *
 * Three implementations of one rule is how the archival defect happened. This
 * class exists so there is one, and so the two non-flow paths that used to
 * write `$config['assignee'] ?? ''` straight onto a task stop creating work
 * nobody is assigned and nobody is notified about.
 *
 * And a case that DOES name a handler is not an empty answer. A task created
 * on a case belongs to whoever is handling that case, so when nothing authored
 * names anybody the work goes to `case.assignee`, and the caller that used to
 * do that step for itself no longer has to. An action that genuinely wants a
 * task nobody owns says so: `assignee: "none"`.
 *
 * What the caller does with an empty answer is genuinely different per caller,
 * so this class does not decide it. The flow node refuses, because an
 * unassigned flow task can be resumed by anyone. A transition side effect
 * creates the task anyway and says so in the log, because refusing there aborts
 * a status change over a task that has a team to fall back on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use Psr\Log\LoggerInterface;

/**
 * Resolves an authored assignee, and its declared fallback, against a case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class AssigneeResolver {

	/**
	 * The authored value that means "leave this task unclaimed".
	 *
	 * A reserved word, not a uid. Without it there is no way to author a queue
	 * task any more: once an unauthored assignee defaults to the case handler,
	 * every action that deliberately names nobody would land on one person.
	 *
	 * @var string
	 */
	public const UNASSIGNED = 'none';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The principal an authored assignee resolves to on this case.
	 *
	 * Four steps, each one a declared field rather than a guess: the authored
	 * assignee, its declared fallback, the case's own handler, and then
	 * nobody. The third step is what this class was missing: an action that
	 * named no assignee at all created a task addressed to nobody, so the task
	 * schema's `taskAssigned` notification went nowhere and the work sat on a
	 * case whose handler never heard about it.
	 *
	 * The team is NOT a step here, because the answer is a string and a team
	 * is a different field on the task. See `resolveTeam()`.
	 *
	 * `assignee: "none"` short-circuits everything and answers '', which is
	 * how an action keeps a queue task unclaimed.
	 *
	 * @param string               $primary  The authored assignee, template or literal.
	 * @param string               $fallback The declared second choice, or ''.
	 * @param array<string, mixed> $case     The case to render against.
	 *
	 * @return string The rendered principal, or '' when nothing names anybody.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 * @spec openspec/changes/task-defaults-to-case-handler/specs/task-management/spec.md
	 */
	public function resolve(string $primary, string $fallback, array $case): string {
		$primary = trim($primary);
		if (strcasecmp($primary, self::UNASSIGNED) === 0) {
			return '';
		}

		$json = $this->renderingContext(case: $case);

		$resolved = $this->renderPrincipal(raw: $primary, json: $json);
		if ($resolved !== '') {
			return $resolved;
		}

		$fallback = trim($fallback);
		if ($fallback !== '') {
			$resolved = $this->renderPrincipal(raw: $fallback, json: $json);
			if ($resolved !== '') {
				$this->logger->info(
					'Dossiq assignee: "' . $primary . '" named nobody on this case, so the work goes to its '
						. 'declared fallback "' . $resolved . '"',
					['case' => $this->caseId(case: $case)]
				);

				return $resolved;
			}
		}

		$handler = $this->referenceId(value: ($case['assignee'] ?? ''));
		if ($handler !== '') {
			$this->logger->info(
				'Dossiq assignee: nothing authored named anybody, so the work goes to the case handler "'
					. $handler . '"',
				['case' => $this->caseId(case: $case)]
			);

			return $handler;
		}

		return '';
	}//end resolve()

	/**
	 * The team a piece of work falls to when no person resolves.
	 *
	 * The last declared step, and a separate answer on purpose: `resolve()`
	 * returns a person, the task schema keeps the team in its own field, and
	 * writing a group id into the person field is how you get a task addressed
	 * to a principal nothing answers to.
	 *
	 * Read through `referenceId`, never a `(string)` cast: `assignedGroup` is a
	 * `$ref`, so an expanded read casts to the literal "Array".
	 *
	 * @param array<string, mixed> $case The case to read.
	 *
	 * @return string The team id, or '' when the case names no team.
	 *
	 * @spec openspec/changes/task-defaults-to-case-handler/specs/task-management/spec.md
	 */
	public function resolveTeam(array $case): string {
		return $this->referenceId(value: ($case['assignedGroup'] ?? ''));
	}//end resolveTeam()

	/**
	 * Why a resolution came back empty, as a clause a person can read.
	 *
	 * Callers that refuse put this after "could not resolve the assignee X, and".
	 *
	 * @param string $fallback The declared second choice, or ''.
	 *
	 * @return string The clause.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function refusalReason(string $fallback): string {
		$fallback = trim($fallback);
		if ($fallback === '') {
			return 'the step declares no assigneeFallback to send the ask to instead';
		}

		return sprintf('its fallback "%s" resolved to nobody either', $fallback);
	}//end refusalReason()

	/**
	 * The id of a case, however the store shaped it.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function caseId(array $case): string {
		return $this->referenceId(value: ($case['id'] ?? ($case['uuid'] ?? '')));
	}//end caseId()

	/**
	 * The id a reference holds, whether it is an id or an expanded object.
	 *
	 * 🔴 A `(string)` CAST IS NOT SAFE HERE AND FAILS LOUDLY IN THE WRONG PLACE.
	 * A `$ref` reaches PHP as a uuid string on a plain read and as the expanded
	 * object when the caller asked for it, and casting the expanded form yields
	 * the literal `"Array"` plus a warning. `failOnWarning` is off in this
	 * suite, so the warning is invisible and what survives is a task whose team
	 * is the four characters A-r-r-a-y: a reference that resolves to nothing,
	 * in a column a list has to render.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string The id, or '' when there is none.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['uuid'] ?? '')));
		}

		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		return '';
	}//end referenceId()

	/**
	 * The case, offered under both its own keys and a `case.` prefix.
	 *
	 * The declarations write `{{ case.assignee }}`, the same spelling dossiq's
	 * template nodes already use, while the flow item's json IS the case.
	 * Offering both is what makes one authored spelling work everywhere.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The rendering context.
	 */
	private function renderingContext(array $case): array {
		return array_merge($case, ['case' => $case]);
	}//end renderingContext()

	/**
	 * Render one authored principal against the case, or return nothing.
	 *
	 * "Nothing" covers all three ways an authored value fails to name somebody:
	 * an empty rendering, one the engine could not resolve, and one that came
	 * back as a structure rather than a name.
	 *
	 * @param string               $raw  The authored value, template or literal.
	 * @param array<string, mixed> $json The case, under its own keys and a `case.` prefix.
	 *
	 * @return string The rendered principal, or '' when it names nobody.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowValueTemplate is the engine's
	 *     canonical rendering API and is published as a static, final class:
	 *     there is no instance to inject.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	private function renderPrincipal(string $raw, array $json): string {
		if ($raw === '') {
			return '';
		}

		if (class_exists(FlowValueTemplate::class) === false) {
			// An instance without OpenRegister cannot render a template, and a
			// raw `{{ … }}` written onto a task is worse than none: it is a
			// principal nothing answers to. A literal still resolves.
			if (str_contains($raw, '{{') === true) {
				return '';
			}

			return trim($raw);
		}

		$rendered = FlowValueTemplate::renderTracked(value: $raw, json: $json);
		$value = $rendered['value'];
		if (is_array($value) === true || $rendered['unresolved'] !== []) {
			return '';
		}

		return trim((string)$value);
	}//end renderPrincipal()
}//end class
