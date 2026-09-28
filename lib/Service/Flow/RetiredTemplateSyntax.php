<?php

/**
 * Rewrites dossiq's `{{case.path}}` templates into the syntax of the node that replaces them.
 *
 * dossiq's retired actions rendered `{{case.x}}` against a context whose one
 * root was the case: a dotted path walked into it, anything else (including a
 * path without the `case.` root) rendered as the empty string, and nothing else
 * in the text was syntax.
 *
 * The replacements read templates differently, so each target gets its own
 * rewrite and each refuses what it cannot carry faithfully:
 *
 * - OpenRegister's messaging nodes interpolate `{{ key }}` against the item's
 *   TOP-LEVEL keys only. `{{case.title}}` becomes `{{ title }}`; a deeper path
 *   such as `{{case.indiener.naam}}` has no equivalent and is refused.
 * - Filinq renders Twig, with the item's fields at the top level and the whole
 *   item under `item`. `{{case.a.b}}` becomes `{{ item.a.b }}`, which Twig
 *   walks the same way. Text dossiq printed literally but Twig would execute
 *   (`{%`, `{#`) is refused.
 *
 * A placeholder without the `case.` root rendered empty in dossiq, so it is
 * rewritten to nothing rather than to a lookup that would suddenly find a value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * Template rewrites for the retired-node translation.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class RetiredTemplateSyntax {

	/**
	 * The placeholder shape dossiq's renderer matched.
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

	/**
	 * The root every meaningful dossiq placeholder started with.
	 *
	 * @var string
	 */
	private const CASE_ROOT = 'case.';

	/**
	 * Rewrite a template for OpenRegister's send-email and send-notification steps.
	 *
	 * @param string $template The dossiq template.
	 * @param string $what     What the template is, for the refusal message.
	 *
	 * @return string The template in OpenRegister's messaging syntax.
	 *
	 * @throws UnmappableStep When a placeholder walks deeper than one field.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function forMessaging(string $template, string $what): string {
		return (string)preg_replace_callback(
			self::PLACEHOLDER,
			static function (array $match) use ($what): string {
				$path = $match[1];
				if (str_starts_with($path, self::CASE_ROOT) === false) {
					return '';
				}

				$field = substr($path, strlen(self::CASE_ROOT));
				if ($field === '' || str_contains($field, '.') === true) {
					throw new UnmappableStep(
						'the ' . $what . ' uses {{' . $path . '}}, and the OpenRegister message step reads only top-level fields'
					);
				}

				return '{{ ' . $field . ' }}';
			},
			$template
		);
	}//end forMessaging()

	/**
	 * Rewrite a template for Filinq's generate-document step (Twig).
	 *
	 * @param string $template The dossiq template.
	 * @param string $what     What the template is, for the refusal message.
	 *
	 * @return string The template in Filinq's syntax.
	 *
	 * @throws UnmappableStep When the text holds Twig syntax dossiq printed literally.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function forDocument(string $template, string $what): string {
		if (str_contains($template, '{%') === true || str_contains($template, '{#') === true) {
			throw new UnmappableStep(
				'the ' . $what . ' contains "{%" or "{#", which dossiq printed as text and Filinq would run as template code'
			);
		}

		return (string)preg_replace_callback(
			self::PLACEHOLDER,
			static function (array $match): string {
				$path = $match[1];
				if (str_starts_with($path, self::CASE_ROOT) === false || $path === self::CASE_ROOT) {
					return '';
				}

				return '{{ item.' . substr($path, strlen(self::CASE_ROOT)) . ' }}';
			},
			$template
		);
	}//end forDocument()

	/**
	 * Put the merge fields a createDocument step computed into its template.
	 *
	 * dossiq rendered each merge field first and exposed the result as
	 * `{{case.mergeFields.<name>}}`. Filinq has no merge fields, so the field's
	 * own template is placed where the reference was; rendering it in place
	 * gives the same text.
	 *
	 * @param string               $template    The dossiq template.
	 * @param array<string, mixed> $mergeFields Name => template.
	 *
	 * @return string The template with every merge-field reference replaced.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function inlineMergeFields(string $template, array $mergeFields): string {
		return (string)preg_replace_callback(
			'/\{\{\s*case\.mergeFields\.([a-zA-Z0-9_]+)\s*\}\}/',
			static function (array $match) use ($mergeFields): string {
				$value = ($mergeFields[$match[1]] ?? '');
				if (is_scalar($value) === false) {
					return '';
				}

				return (string)$value;
			},
			$template
		);
	}//end inlineMergeFields()
}//end class
