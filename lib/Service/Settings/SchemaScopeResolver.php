<?php

/**
 * Dossiq schema scope resolver.
 *
 * Answers one question for a guard that runs on OpenRegister's global object
 * events: is the object being written one of MINE?
 *
 * WHY THIS EXISTS. Every such guard used to answer it the same way, by reading
 * the `*_schema` appconfig key and standing aside when the key was empty:
 *
 * ```php
 * $expected = $this->settingsService->getConfigValue('milestone_definition_schema');
 * if ($expected === '') {
 *     return false;   // stands aside, silently
 * }
 * ```
 *
 * That key is written by the configuration load. On an instance whose setup
 * never finished, the key is absent, so the guard reports nothing and refuses
 * nothing, and the only evidence is the thing it should have refused sitting in
 * the register. Measured on 2026-09-19: a milestone definition that waits for
 * itself was stored with a 201 while its cycle guard was wired, tested and
 * running, because that one `return false` fired first. It is the same shape as
 * the download gate that failed open because a read returned nothing.
 *
 * WHAT THIS CLASS DOES INSTEAD. A missing key is treated as a missing ANSWER
 * rather than as a negative one, and three sources are asked in order:
 *
 * 1. the configured schema id, which is authoritative when it is there;
 * 2. the LIVE schema id for the slug, resolved against OpenRegister, which is
 *    the same answer the reconciler would have written into the key;
 * 3. the slug itself, because a write addressed by slug carries it.
 *
 * Only when none of the three can speak does it answer {@see UNDECIDED}, and
 * UNDECIDED is a question for the caller, not a licence. A caller that can
 * recognise its own payload should still check it; a caller that cannot should
 * say so in a log line rather than in silence.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Settings
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
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Settings;

use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether an object payload belongs to one of Dossiq's schemas.
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */
class SchemaScopeResolver {

	/**
	 * The payload belongs to the named schema.
	 *
	 * @var string
	 */
	public const IN_SCOPE = 'in-scope';

	/**
	 * The payload belongs to a different schema.
	 *
	 * @var string
	 */
	public const OUT_OF_SCOPE = 'out-of-scope';

	/**
	 * Nothing available could tell the two apart.
	 *
	 * @var string
	 */
	public const UNDECIDED = 'undecided';

	/**
	 * Config keys already reported as missing, so one broken instance does not
	 * write a warning per object in an import.
	 *
	 * @var array<string, bool>
	 */
	private array $reported = [];

	/**
	 * Live schema ids by slug, memoised per request ('' when unresolvable).
	 *
	 * @var array<string, string>
	 */
	private array $liveIds = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reads the app configuration.
	 * @param SchemaSlugResolver $slugResolver Resolves a slug to a live schema.
	 * @param ContainerInterface $container The DI container, for OpenRegister's mapper.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly SchemaSlugResolver $slugResolver,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Classify an object payload against one of Dossiq's schemas.
	 *
	 * @param array<string, mixed> $payload The object payload, including `@self`.
	 * @param string $configKey The appconfig key that holds the schema id.
	 * @param string $slug The schema slug, e.g. `milestoneDefinition`.
	 *
	 * @return string One of IN_SCOPE, OUT_OF_SCOPE or UNDECIDED.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function classify(array $payload, string $configKey, string $slug): string {
		$candidates = $this->candidates(payload: $payload);
		if ($candidates === []) {
			return self::UNDECIDED;
		}

		$configured = trim($this->settingsService->getConfigValue($configKey));
		if ($configured !== '') {
			if ($this->identifies(candidates: $candidates, expected: $configured) === true) {
				return self::IN_SCOPE;
			}

			return self::OUT_OF_SCOPE;
		}

		// The key is absent. That says the configuration load never completed,
		// which is a statement about setup and not about this object.
		$this->reportMissingKey(configKey: $configKey, slug: $slug);

		$live = $this->liveSchemaId(slug: $slug);
		if ($live !== '') {
			if ($this->identifies(candidates: $candidates, expected: $live) === true
				|| $this->identifies(candidates: $candidates, expected: $slug) === true
			) {
				return self::IN_SCOPE;
			}

			return self::OUT_OF_SCOPE;
		}

		if ($this->identifies(candidates: $candidates, expected: $slug) === true) {
			return self::IN_SCOPE;
		}

		return self::UNDECIDED;
	}//end classify()

	/**
	 * Every identity the payload offers for its schema.
	 *
	 * `@self.schema` holds the schema id on a plain write and an EXPANDED
	 * schema (id, slug, title) when the metadata is extended, so both shapes
	 * are read and both the id and the slug are kept.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return array<int, string> The identities, without empties.
	 */
	private function candidates(array $payload): array {
		$raw = ($payload['@self']['schema'] ?? ($payload['schema'] ?? null));

		$values = [$raw];
		if (is_array($raw) === true) {
			$values = [($raw['id'] ?? null), ($raw['uuid'] ?? null), ($raw['slug'] ?? null)];
		}

		$candidates = [];
		foreach ($values as $value) {
			if (is_scalar($value) === false) {
				continue;
			}

			$candidate = trim((string)$value);
			if ($candidate !== '') {
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}//end candidates()

	/**
	 * Whether any candidate identity names the expected schema.
	 *
	 * The suffix match is kept from the guards this class replaces: a schema
	 * can be addressed as a bare id or as a `register/schema` path.
	 *
	 * @param array<int, string> $candidates The payload's identities.
	 * @param string $expected The expected id or slug.
	 *
	 * @return bool True on a match.
	 */
	private function identifies(array $candidates, string $expected): bool {
		foreach ($candidates as $candidate) {
			if ($candidate === $expected || str_ends_with($candidate, '/' . $expected) === true) {
				return true;
			}
		}

		return false;
	}//end identifies()

	/**
	 * The live schema id for a slug, or '' when it cannot be resolved.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return string The id, or ''.
	 */
	private function liveSchemaId(string $slug): string {
		$known = $this->liveIds;
		if (array_key_exists($slug, $known) === true) {
			return $known[$slug];
		}

		$id = '';

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$schema = $this->slugResolver->resolve(schemaMapper: $schemaMapper, slug: $slug);
			if ($schema !== null && method_exists($schema, 'getId') === true) {
				$id = trim((string)$schema->getId());
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: could not resolve a schema slug while scoping a guard',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
		}

		// Assigned whole rather than by offset, including the empty answer:
		// one failed lookup per request is enough, and phpmd reads a field
		// only ever written through an offset as an unused one.
		$this->liveIds = ($known + [$slug => $id]);

		return $id;
	}//end liveSchemaId()

	/**
	 * Say once, out loud, that a guard is working without its config key.
	 *
	 * @param string $configKey The missing appconfig key.
	 * @param string $slug The schema slug it should hold the id of.
	 *
	 * @return void
	 */
	private function reportMissingKey(string $configKey, string $slug): void {
		$reported = $this->reported;
		if (array_key_exists($configKey, $reported) === true) {
			return;
		}

		$this->reported = ($reported + [$configKey => true]);
		$this->logger->warning(
			'Dossiq: a guard is scoping without its schema config key, '
			. 'which means the configuration load never completed on this instance',
			['configKey' => $configKey, 'slug' => $slug]
		);
	}//end reportMissingKey()
}//end class
