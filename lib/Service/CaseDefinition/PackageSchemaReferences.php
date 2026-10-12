<?php

/**
 * Resolves a package setting or seed file that names another app's schema.
 *
 * A schema id differs per instance, so a package cannot ship one. It ships
 * the schema's address instead: the app that owns it, the register slug (or
 * the slugs that register has had) and the schema slug. The installer turns
 * that address into this instance's id at install time, and refuses the
 * package, naming the app, when the schema is not there.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns settings that name a schema by app, register and slug into schema ids.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackageSchemaReferences {

	/**
	 * Constructor.
	 *
	 * @param IAppManager         $appManager Answers whether the owning app is installed.
	 * @param ContainerInterface  $container  The DI container, for OpenRegister's mappers.
	 * @param LoggerInterface     $logger     Logger.
	 * @param PackageSettingRules $rules      Reads a schema reference.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PackageSettingRules $rules = new PackageSettingRules(),
	) {
	}//end __construct()

	/**
	 * Resolve every reference among a package's settings.
	 *
	 * @param array<string, mixed> $settings The decoded settings.json.
	 *
	 * @return array{settings: array<string, mixed>, missing: array<int, string>, reasons: array<int, string>}
	 *         The settings with each reference replaced by its schema id, and
	 *         for each schema not found here `<app>:<slug>` and a sentence.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function resolve(array $settings): array {
		$missing = [];
		$reasons = [];
		foreach ($settings as $key => $value) {
			$reference = $this->rules->reference(value: $value);
			if ($reference === null) {
				continue;
			}

			['app' => $app, 'slug' => $slug] = $reference;

			$found = $this->locate(reference: $reference);
			if ($found['schema'] === null) {
				$missing[] = "{$app}:{$slug}";
				$reasons[] = $found['reason'];
				continue;
			}

			$settings[$key] = $found['schema'];
		}

		return ['settings' => $settings, 'missing' => $missing, 'reasons' => $reasons];
	}//end resolve()

	/**
	 * Find the register and the schema one address names on this instance.
	 *
	 * @param array{app: string, registers: array<int, string>, slug: string} $reference The address.
	 *
	 * @return array{register: string|null, schema: string|null, reason: string} The ids, or null for both
	 *                                                                           with the reason they are missing.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function locate(array $reference): array {
		['app' => $app, 'registers' => $registers, 'slug' => $slug] = $reference;

		$found = null;
		if ($this->isInstalled(app: $app) === true) {
			$found = $this->findSchema(registers: $registers, slug: $slug);
		}

		if ($found === null) {
			return ['register' => null, 'schema' => null, 'reason' => $this->reason(app: $app, slug: $slug, registers: $registers)];
		}

		return ['register' => $found['register'], 'schema' => $found['schema'], 'reason' => ''];
	}//end locate()

	/**
	 * The group for one seed file.
	 *
	 * A file that names its schema by settings key goes to this app's
	 * register. A file that names another app's schema by app, register and
	 * slug goes to that register: the address is resolved here, and a group
	 * whose schema this instance does not hold carries `missing` and the
	 * reason, so the package is refused before a row is written.
	 *
	 * @param array<string, mixed> $seed The decoded seed file.
	 *
	 * @return array<string, mixed> The group.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function seedGroup(array $seed): array {
		$group = ['schemaKey' => '', 'rows' => array_values((array)$seed['rows'])];
		if (is_string($seed['match'] ?? null) === true) {
			$group['match'] = $seed['match'];
		}

		if (is_array($seed['schema']) === false) {
			$group['schemaKey'] = (string)$seed['schema'];

			return $group;
		}

		$reference = (array)$this->rules->reference(value: [PackageSettingRules::REFERENCE_KEY => $seed['schema']]);
		$group['schemaKey'] = "{$reference['app']}:{$reference['slug']}";

		$found = $this->locate(reference: $reference);
		if ($found['schema'] === null) {
			return $group + ['missing' => $group['schemaKey'], 'reason' => $found['reason']];
		}

		return $group + ['register' => $found['register'], 'schema' => $found['schema']];
	}//end seedGroup()

	/**
	 * Why a reference could not be resolved, for a person.
	 *
	 * @param string             $app       The owning app.
	 * @param string             $slug      The schema slug.
	 * @param array<int, string> $registers The register slugs.
	 *
	 * @return string The sentence.
	 */
	private function reason(string $app, string $slug, array $registers): string {
		if ($this->isInstalled(app: $app) === false) {
			return "{$app}'s schema '{$slug}', and {$app} is not installed";
		}

		return "{$app}'s schema '{$slug}', which register " . implode(' or ', $registers) . ' does not hold here';
	}//end reason()

	/**
	 * Whether the app is installed, under its id or a former one: a renamed
	 * fleet app keeps answering to its old id until the rename lands there.
	 *
	 * @param string $app The app id the package names.
	 *
	 * @return boolean True when installed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is the fleet's one rename-aware lookup.
	 */
	private function isInstalled(string $app): bool {
		return FleetAppId::isInstalled(appManager: $this->appManager, canonical: $app);
	}//end isInstalled()

	/**
	 * The first register that holds a schema with this slug, and that schema.
	 *
	 * @param array<int, string> $registers The register slugs, newest first.
	 * @param string             $slug      The schema slug.
	 *
	 * @return array{register: string, schema: string}|null The two ids, or null when no register holds it.
	 */
	private function findSchema(array $registers, string $slug): ?array {
		foreach ($registers as $registerSlug) {
			try {
				$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
				$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
				// A read of configuration, not of objects; an install may have no user.
				$register = $registerMapper->find($registerSlug, false, false);
				$schema = $schemaMapper->findBySlugInIds($slug, (array)$register->getSchemas());
			} catch (Throwable $e) {
				// An unknown register throws; so does a missing OpenRegister.
				// Either way this register does not hold the schema here.
				$this->logger->info(
					'Dossiq: register {register} did not answer for schema {slug}',
					['register' => $registerSlug, 'slug' => $slug, 'exception' => $e->getMessage()]
				);
				continue;
			}

			if ($schema !== null) {
				return ['register' => (string)$register->getId(), 'schema' => (string)$schema->getId()];
			}
		}

		return null;
	}//end findSchemaId()
}//end class
