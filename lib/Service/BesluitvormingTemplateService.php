<?php

/**
 * Procest Besluitvorming Template Service
 *
 * Seeds the pre-configured bestuurlijke-besluitvorming zaaktype bundles
 * (College-besluit, Raadsbesluit, Mandaatbesluit) into OpenRegister. Each
 * bundle activates a caseType plus its statusType, propertyDefinition,
 * roleType, documentType, resultType, workflowTemplate, and default
 * parafeerroute records. Activation is idempotent — re-running it does not
 * duplicate records (existing caseTypes are detected by identifier).
 *
 * This class is the activation orchestrator only: which slugs exist, which
 * bundle file backs each one, which schemas the write needs, and the
 * system-principal elevation the boot-time repair step requires. The writes
 * themselves live in {@see TemplateBundleSeeder}, and the name→id rewrite of
 * a bundle's workflow payload in {@see WorkflowReferenceResolver}.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Besluitvorming\TemplateBundleSeeder;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Seeds besluitvorming zaaktype templates into OpenRegister.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
class BesluitvormingTemplateService {
	use SearchesObjects;

	/**
	 * Recognised template slugs and their backing JSON files.
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATES = [
		'college-besluit' => 'bvw-college-besluit.json',
		'raadsbesluit' => 'bvw-raadsbesluit.json',
		'mandaatbesluit' => 'bvw-mandaatbesluit.json',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + app config.
	 * @param LoggerInterface $logger Logger.
	 * @param TemplateBundleSeeder $seeder The OpenRegister write path for a bundle.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly TemplateBundleSeeder $seeder,
	) {
	}//end __construct()

	/**
	 * Activate (seed) all three besluitvorming templates.
	 *
	 * @return array<string, mixed> Per-template result summary.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function activateAll(): array {
		$summary = [];
		foreach (array_keys(self::TEMPLATES) as $slug) {
			try {
				$summary[$slug] = $this->activate(slug: $slug);
			} catch (\Throwable $e) {
				$this->logger->error(
					'Procest: failed to activate besluitvorming template',
					['slug' => $slug, 'exception' => $e->getMessage(), 'app' => Application::APP_ID],
				);
				$summary[$slug] = ['success' => false, 'message' => 'activation_failed'];
			}
		}

		return $summary;
	}//end activateAll()

	/**
	 * Activate a single besluitvorming template by slug.
	 *
	 * Reads the template JSON bundle and upserts its caseType + related
	 * records into OpenRegister. Idempotent: if a caseType with the same
	 * identifier already exists, no records are created.
	 *
	 * @param string $slug The template slug (college-besluit|raadsbesluit|mandaatbesluit).
	 *
	 * @return array<string, mixed> A result summary with creation counts.
	 *
	 * @throws RuntimeException When the slug is unknown or the bundle cannot be read.
	 *
	 * @spec openspec/specs/besluitvorming-workflow/spec.md
	 */
	public function activate(string $slug): array {
		if (isset(self::TEMPLATES[$slug]) === false) {
			throw new RuntimeException('Onbekend besluitvorming-template: ' . $slug);
		}

		$bundle = $this->loadBundle(slug: $slug);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schemas = $this->resolveSchemas();
		if ($register === '' || $schemas['caseType'] === '') {
			throw new RuntimeException('Register of caseType-schema is niet geconfigureerd');
		}

		$caseTypeData = (array)($bundle['caseType'] ?? []);
		$identifier = (string)($caseTypeData['identifier'] ?? '');
		if ($identifier === '') {
			throw new RuntimeException('Template mist een caseType.identifier');
		}

		// This service is only ever invoked from the boot-time
		// SeedBesluitvormingTemplates repair step — never from a live user
		// request — so it is safe to elevate the idempotency read + bundle
		// writes below for the duration of this call. Anonymous callers are
		// otherwise fail-closed by OpenRegister RBAC (#1955) on every boot.
		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $register, $schemas, $slug, $caseTypeData, $bundle, $identifier): array {
				// Idempotency: skip if a caseType with this identifier already exists.
				$existing = $this->seeder->findByIdentifier(
					objectService: $objectService,
					register: $register,
					schema: $schemas['caseType'],
					identifier: $identifier,
				);
				if ($existing !== null) {
					$this->logger->info(
						'Procest: besluitvorming template already active, skipping',
						['slug' => $slug, 'identifier' => $identifier],
					);
					return ['success' => true, 'skipped' => true, 'slug' => $slug];
				}

				// The default parafeerroute lives either at the bundle top level or
				// nested under caseType; accept both shapes.
				$parafeerroute = (array)($bundle['parafeerroute'] ?? ($caseTypeData['parafeerroute'] ?? []));
				unset($caseTypeData['parafeerroute']);

				return $this->seeder->seedBundle(
					objectService: $objectService,
					register: $register,
					schemas: $schemas,
					slug: $slug,
					caseTypeData: $caseTypeData,
					parafeerroute: $parafeerroute,
				);
			}
		);
	}//end activate()

	/**
	 * Load and decode a template JSON bundle.
	 *
	 * @param string $slug The template slug.
	 *
	 * @return array<string, mixed> The decoded bundle.
	 *
	 * @throws RuntimeException When the file is missing or invalid.
	 */
	private function loadBundle(string $slug): array {
		$path = __DIR__ . '/../Settings/templates/' . self::TEMPLATES[$slug];
		if (file_exists($path) === false) {
			throw new RuntimeException('Template-bestand ontbreekt: ' . basename($path));
		}

		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException('Kon template-bestand niet lezen: ' . basename($path));
		}

		$decoded = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			throw new RuntimeException('Ongeldige JSON in template-bestand: ' . basename($path));
		}

		return $decoded;
	}//end loadBundle()

	/**
	 * Resolve the schema ids needed to seed a bundle.
	 *
	 * @return array<string, string> Map of schema-key => configured schema id.
	 */
	private function resolveSchemas(): array {
		return [
			'caseType' => $this->settingsService->getConfigValue(key: 'case_type_schema'),
			'statusType' => $this->settingsService->getConfigValue(key: 'status_type_schema'),
			'roleType' => $this->settingsService->getConfigValue(key: 'role_type_schema'),
			'propertyDefinition' => $this->settingsService->getConfigValue(key: 'property_definition_schema'),
			'documentType' => $this->settingsService->getConfigValue(key: 'document_type_schema'),
			'resultType' => $this->settingsService->getConfigValue(key: 'result_type_schema'),
			'workflowTemplate' => $this->settingsService->getConfigValue(key: 'workflow_template_schema'),
			'parafeerroute' => $this->settingsService->getConfigValue(key: 'parafeerroute_schema'),
		];
	}//end resolveSchemas()
}//end class
