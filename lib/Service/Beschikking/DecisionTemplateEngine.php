<?php

/**
 * Dossiq Decision Template Engine.
 *
 * Answers which engine renders a decision template: `filinq` (filinq's own
 * template engine, the default) or `vendor:<sourceSlug>` (an external document
 * vendor such as SmartDocuments or Xential, reached through integriq's source
 * of that slug, rendered by filinq). A case type names the engine next to the
 * template, per kind of decision, in `decisionDocuments.<kind>.engine`.
 *
 * The engine is read from the case type, not taken from the caller, so a
 * request cannot move a decision to another renderer than the one the case
 * type configures. An engine value that is not one of the two shapes is
 * refused: rendering it with filinq instead would put a document on the case
 * from a renderer nobody chose.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
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
 * @spec openspec/changes/decision-templates-render-through-a-vendor/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves the engine a decision template renders with.
 *
 * @spec openspec/changes/decision-templates-render-through-a-vendor/specs/beschikking-generatie/spec.md
 */
class DecisionTemplateEngine {

	/**
	 * The default engine: filinq's own template engine.
	 *
	 * @var string
	 */
	public const DEFAULT_ENGINE = 'filinq';

	/**
	 * The prefix of a vendor engine.
	 *
	 * @var string
	 */
	public const VENDOR_PREFIX = 'vendor:';

	/**
	 * The shape of a valid engine value.
	 *
	 * @var string
	 */
	public const ENGINE_PATTERN = '/^(filinq|vendor:[a-z0-9][a-z0-9_-]*)$/';

	/**
	 * The case type key that holds the decision kinds.
	 *
	 * @var string
	 */
	private const CASE_TYPE_KEY = 'decisionDocuments';

	/**
	 * Display names of the vendor sources the fleet knows by slug.
	 *
	 * @var array<string, string>
	 */
	private const VENDOR_NAMES = [
		'smartdocuments' => 'SmartDocuments',
		'xential' => 'Xential',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store Loads the case, to learn its case type.
	 * @param CaseTypeResolver $caseTypes The effective case type, parents included.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseTypeResolver $caseTypes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The engine the case type configures for a template.
	 *
	 * Looks through the case type's decision kinds for the one whose template
	 * is the given one. No case, no case type, no kind on that template or no
	 * engine on it: filinq.
	 *
	 * @param string $caseId The case UUID, or ''.
	 * @param string $templateId The template the decision renders from.
	 *
	 * @return string `filinq` or `vendor:<sourceSlug>`.
	 *
	 * @throws RuntimeException When the case type names an engine of another shape.
	 *
	 * @spec openspec/changes/decision-templates-render-through-a-vendor/tasks.md#2.2
	 */
	public function engineFor(string $caseId, string $templateId): string {
		$caseId = trim($caseId);
		$templateId = trim($templateId);
		if ($caseId === '' || $templateId === '') {
			return self::DEFAULT_ENGINE;
		}

		$case = $this->store->loadCase(caseId: $caseId);
		$caseTypeId = trim((string)(($case ?? [])['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return self::DEFAULT_ENGINE;
		}

		$kinds = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId)[self::CASE_TYPE_KEY] ?? [];
		if (is_array($kinds) === false) {
			return self::DEFAULT_ENGINE;
		}

		foreach ($kinds as $kind => $block) {
			if (is_array($block) === false || trim((string)($block['template'] ?? '')) !== $templateId) {
				continue;
			}

			return $this->normalise(engine: $block['engine'] ?? null, kind: (string)$kind);
		}

		return self::DEFAULT_ENGINE;
	}//end engineFor()

	/**
	 * The vendor source slug of an engine, or null for filinq's own engine.
	 *
	 * @param string $engine The engine value.
	 *
	 * @return string|null The source slug.
	 *
	 * @spec openspec/changes/decision-templates-render-through-a-vendor/tasks.md#2.2
	 */
	public static function vendorSlugOf(string $engine): ?string {
		if (str_starts_with($engine, self::VENDOR_PREFIX) === false) {
			return null;
		}

		$slug = substr($engine, strlen(self::VENDOR_PREFIX));
		if ($slug === '') {
			return null;
		}

		return $slug;
	}//end vendorSlugOf()

	/**
	 * The name a person knows a vendor source by.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return string The display name; the slug itself for an unknown source.
	 *
	 * @spec openspec/changes/decision-templates-render-through-a-vendor/tasks.md#2.2
	 */
	public static function vendorName(string $slug): string {
		return (self::VENDOR_NAMES[$slug] ?? $slug);
	}//end vendorName()

	/**
	 * A configured engine value; filinq when it is unset.
	 *
	 * @param mixed $engine The configured value.
	 * @param string $kind The decision kind, for the log line.
	 *
	 * @return string The engine.
	 *
	 * @throws RuntimeException When the value is neither filinq nor vendor:<source>.
	 */
	private function normalise(mixed $engine, string $kind): string {
		if ($engine === null || $engine === '') {
			return self::DEFAULT_ENGINE;
		}

		if (is_string($engine) === true && preg_match(self::ENGINE_PATTERN, $engine) === 1) {
			return $engine;
		}

		$this->logger->error(
			'Dossiq decision template: the engine is neither filinq nor vendor:<source>',
			['kind' => $kind],
		);

		throw new RuntimeException('decision_template_engine_invalid: decision kind ' . $kind);
	}//end normalise()
}//end class
