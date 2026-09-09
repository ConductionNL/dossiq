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
	 * Tries the primary, then the declared fallback. Answers '' when neither
	 * names anybody, which is a state the caller has to handle rather than a
	 * state this class guesses its way out of.
	 *
	 * @param string               $primary  The authored assignee, template or literal.
	 * @param string               $fallback The declared second choice, or ''.
	 * @param array<string, mixed> $case     The case to render against.
	 *
	 * @return string The rendered principal, or '' when neither names anybody.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function resolve(string $primary, string $fallback, array $case): string {
		$json = $this->renderingContext(case: $case);

		$resolved = $this->renderPrincipal(raw: trim($primary), json: $json);
		if ($resolved !== '') {
			return $resolved;
		}

		$fallback = trim($fallback);
		if ($fallback === '') {
			return '';
		}

		$resolved = $this->renderPrincipal(raw: $fallback, json: $json);
		if ($resolved === '') {
			return '';
		}

		$this->logger->info(
			'Dossiq assignee: "' . $primary . '" named nobody on this case, so the work goes to its '
				. 'declared fallback "' . $resolved . '"',
			['case' => $this->caseId(case: $case)]
		);

		return $resolved;
	}//end resolve()

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
