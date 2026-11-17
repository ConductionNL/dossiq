<?php

/**
 * Dossiq Filinq Template-Engine Adapter.
 *
 * The real renderer behind {@see TemplateEngineAdapterInterface}. Until this
 * class existed the seam had exactly one implementation,
 * {@see MockTemplateEngineAdapter}, so every beschikking in the fleet was
 * composed by hashing its own arguments: a deterministic `fileId`, a checksum
 * over the request rather than over a document, and a constant four pages. It
 * returned a well-formed composition and no document was ever produced. That
 * reads as a working template pipeline, which is the failure this app keeps
 * having to remove.
 *
 * Filinq owns document generation for the fleet (ADR-075), so this class holds
 * no rendering logic of its own. It resolves filinq's `DocumentService`
 * through {@see FleetAppId} — the id AND the namespace both moved when
 * docudesk became filinq, and a lookup against either stale half returns null
 * without erroring — and hands it the template id and the zaakdata context.
 * The composition metadata it returns describes the file filinq actually
 * wrote: the Nextcloud file id filinq stored, a SHA-256 over the produced
 * bytes, and the page count read out of those bytes.
 *
 * ABSENCE IS AN ERROR HERE, NOT A FALLBACK. This class is only bound when an
 * admin has named it in `beschikking_template_adapter`; they asked for filinq.
 * Answering a render with mock metadata because filinq could not be reached
 * would put the app straight back to claiming a document it does not have, so
 * every unreachable path throws. The choice between this adapter and the mock
 * belongs one level up, in {@see SubstitutableAdapterRegistrar}, where it is
 * logged and shown on the Integrations page.
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/changes/decision-templates-render-through-a-vendor/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Support\FleetAppId;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Renders a beschikking through filinq's document pipeline.
 *
 * @psalm-suppress UnusedClass Bound by name from `beschikking_template_adapter`.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class FilinqTemplateEngineAdapter implements TemplateEngineAdapterInterface {

	/**
	 * Filinq's document-generation service, below its app namespace root.
	 *
	 * @var string
	 */
	private const DOCUMENT_SERVICE = 'Service\DocumentService';

	/**
	 * Filinq's template-metadata service, below its app namespace root.
	 *
	 * @var string
	 */
	private const TEMPLATE_SERVICE = 'Service\TemplateService';

	/**
	 * Filinq's template version chain, below its app namespace root.
	 *
	 * @var string
	 */
	private const TEMPLATE_VERSION_SERVICE = 'Service\TemplateVersionService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves filinq's services across the rename.
	 * @param SettingsService $settings Supplies the case register/schema a template binds to.
	 * @param IUserSession $userSession The acting user; filinq stores the output in their Files.
	 * @param LoggerInterface $logger Structured logger.
	 * @param DecisionTemplateEngine $engines The engine the case type names for a template.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly DecisionTemplateEngine $engines,
	) {
	}//end __construct()

	/**
	 * Render a beschikking template through filinq and store the result.
	 *
	 * The engine the case type names for the template (`filinq`, or
	 * `vendor:<sourceSlug>` for a document vendor reached through integriq)
	 * travels to filinq with the render. A vendor render that fails is refused
	 * with a message naming the vendor; it never falls back to filinq's own
	 * engine or to the mock.
	 *
	 * @param string $templateId The filinq template UUID.
	 * @param array<string, mixed> $context The zaakdata + beschikking context.
	 *
	 * @return array{format: string, fileId: string, checksumSha256: string, paginas: int} Composition metadata.
	 *
	 * @throws RuntimeException When filinq is absent, no user is acting, or the render fails.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/decision-templates-render-through-a-vendor/tasks.md#2.2
	 */
	public function render(string $templateId, array $context): array {
		$documentService = $this->filinqDocumentService();
		$user = $this->actingUser();

		$caseId = (string)($context['caseId'] ?? '');
		$engine = $this->engines->engineFor(caseId: $caseId, templateId: $templateId);

		try {
			$options = $this->renderOptions(context: $context, job: ['user' => $user, 'caseId' => $caseId, 'engine' => $engine]);
			$result = $documentService->generateDocument($templateId, $this->buildDataRefs(caseId: $caseId), $options);
		} catch (Throwable $e) {
			throw $this->renderFailure(job: ['engine' => $engine, 'templateId' => $templateId], e: $e);
		}

		return $this->toComposition(result: (array)$result, templateId: $templateId);
	}//end render()

	/**
	 * Resolve filinq's DocumentService, refusing when filinq is absent.
	 *
	 * @return object The DocumentService.
	 *
	 * @throws RuntimeException When filinq's DocumentService cannot be resolved.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function filinqDocumentService(): object {
		$documentService = FleetAppId::getService($this->container, 'filinq', self::DOCUMENT_SERVICE);
		if ($documentService === null) {
			throw new RuntimeException(
				'filinq_unavailable: beschikking_template_adapter names the filinq adapter, but '
				. 'filinq\'s DocumentService could not be resolved on this instance'
			);
		}

		return $documentService;
	}//end filinqDocumentService()

	/**
	 * The acting Nextcloud user, refusing when nobody is acting.
	 *
	 * @return IUser The acting user.
	 *
	 * @throws RuntimeException When no user is acting.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function actingUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			// Filinq refuses to store a generated document without an owning
			// user, and a render whose file is never written would hand back a
			// null fileId dressed as a composition. Refuse instead.
			throw new RuntimeException('user_required: a beschikking render must run as a Nextcloud user');
		}

		return $user;
	}//end actingUser()

	/**
	 * The options filinq's generateDocument() receives for a beschikking render.
	 *
	 * @param array<string, mixed> $context The zaakdata + beschikking context.
	 * @param array{user: IUser, caseId: string, engine: string} $job The acting user, the case id and the engine
	 *                                                                the case type names for the template.
	 *
	 * @return array<string, mixed> The render options.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function renderOptions(array $context, array $job): array {
		return [
			'format' => 'pdf',
			'adHocData' => $context,
			'userId' => $job['user']->getUID(),
			'caseId' => $job['caseId'],
			'filename' => $this->buildFilename(caseId: $job['caseId']),
			'output' => ['mode' => 'both'],
			'engine' => $job['engine'],
		];
	}//end renderOptions()

	/**
	 * Log a refused render and build the exception that reports it.
	 *
	 * A vendor render names the vendor; a filinq render carries filinq's message.
	 *
	 * @param array{engine: string, templateId: string} $job The engine the render went to and the filinq template UUID.
	 * @param Throwable $e The render failure.
	 *
	 * @return RuntimeException The exception to throw.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/changes/decision-templates-render-through-a-vendor/tasks.md#2.2
	 */
	private function renderFailure(array $job, Throwable $e): RuntimeException {
		['engine' => $engine, 'templateId' => $templateId] = $job;
		$vendor = DecisionTemplateEngine::vendorSlugOf(engine: $engine);
		if ($vendor !== null) {
			$this->logger->error(
				'A document vendor refused a beschikking render',
				['app' => AppIdentity::APP_ID, 'templateId' => $templateId, 'source' => $vendor, 'exception' => $e->getMessage()]
			);
			return new RuntimeException(DecisionTemplateEngine::vendorName(slug: $vendor) . ' is niet bereikbaar', 0, $e);
		}

		$this->logger->error(
			'Filinq refused a beschikking render',
			['app' => AppIdentity::APP_ID, 'templateId' => $templateId, 'exception' => $e->getMessage()]
		);
		return new RuntimeException('filinq_render_failed: ' . $e->getMessage(), 0, $e);
	}//end renderFailure()

	/**
	 * Resolve the template version effective on a given date.
	 *
	 * 🔴 THIS USED TO ANSWER `v1`, ALWAYS, ON EVERY INSTANCE. It read
	 * `$template['version']` and coalesced to 1. Filinq's `getTemplate()`
	 * returns `ObjectEntity::jsonSerialize()`, which keeps the OpenRegister
	 * version under `@self`, and filinq's `template` schema declares no
	 * `version` property of its own — so that key is never set and the
	 * coalesce always fired. The value was the mock's constant with a call in
	 * front of it, which is worse than the mock, because it looked like a
	 * query. Filinq #1063 records the same three facts from the other side.
	 *
	 * What answers it instead is filinq itself:
	 * `TemplateVersionService::versionInForceAt()` names the version that was the
	 * head of the template at a moment, from its own version chain (filinq change
	 * generated-document-names-its-template-version). The adapter asks it for the
	 * end of the effective date, so `$effectiveDate` selects rather than being
	 * echoed back. A beschikking issued in March can therefore name the template
	 * that produced it rather than the one that happens to be current when
	 * someone appeals.
	 *
	 * Two fallbacks, in order, and neither of them invents a number:
	 *
	 *   - Filinq names no version for the date (a filinq without the query, or
	 *     one that cannot read the chain): the template object's own
	 *     OpenRegister version, verbatim, lifted from `@self`.
	 *   - Neither available: a refusal. `v1` is not a safe default when the
	 *     answer is on an appealable decision.
	 *
	 * @param string $templateId The filinq template UUID.
	 * @param string $effectiveDate The ISO date the beschikking is effective.
	 *
	 * @return array{templateId: string, version: string, effectiveDate: string} The resolved version.
	 *
	 * @throws RuntimeException When filinq is absent, does not know the template,
	 *                          or holds no version for it.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function resolveVersion(string $templateId, string $effectiveDate): array {
		$templateService = FleetAppId::getService($this->container, 'filinq', self::TEMPLATE_SERVICE);
		if ($templateService === null) {
			throw new RuntimeException(
				'filinq_unavailable: filinq\'s TemplateService could not be resolved on this instance'
			);
		}

		$template = $this->fetchTemplate(templateService: $templateService, templateId: $templateId);

		return [
			'templateId' => $templateId,
			'version' => $this->effectiveVersion(asked: ['templateId' => $templateId, 'effectiveDate' => $effectiveDate], template: $template),
			'effectiveDate' => $effectiveDate,
		];
	}//end resolveVersion()

	/**
	 * Fetch a template from filinq, refusing a template filinq does not know.
	 *
	 * @param object $templateService Filinq's TemplateService.
	 * @param string $templateId The filinq template UUID.
	 *
	 * @return array<string, mixed> The template.
	 *
	 * @throws RuntimeException When filinq does not know the template.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function fetchTemplate(object $templateService, string $templateId): array {
		try {
			return (array)$templateService->getTemplate($templateId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Filinq does not know the beschikking template',
				['app' => AppIdentity::APP_ID, 'templateId' => $templateId, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('filinq_template_unknown: ' . $templateId, 0, $e);
		}
	}//end fetchTemplate()

	/**
	 * The version in force on the date, else the template's own version.
	 *
	 * @param array{templateId: string, effectiveDate: string} $asked The filinq template UUID and the ISO date the
	 *                                                               beschikking is effective.
	 * @param array<string, mixed> $template The template as filinq returns it.
	 *
	 * @return string The resolved version.
	 *
	 * @throws RuntimeException When filinq holds no version for the template.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function effectiveVersion(array $asked, array $template): string {
		['templateId' => $templateId, 'effectiveDate' => $effectiveDate] = $asked;
		$version = $this->versionInForce(templateId: $templateId, effectiveDate: $effectiveDate);
		if ($version === null) {
			$version = $this->templateOwnVersion(template: $template);
		}

		if ($version === null) {
			throw new RuntimeException(
				'filinq_template_unversioned: filinq holds no version for template ' . $templateId
			);
		}

		return $version;
	}//end effectiveVersion()

	/**
	 * The chain version in force at the end of the effective date.
	 *
	 * Filinq answers this itself (`TemplateVersionService::versionInForceAt()`,
	 * generated-document-names-its-template-version): a snapshot is written when
	 * its version is replaced, so only filinq knows which version was the head at
	 * a moment. A beschikking effective on a date takes the version that was the
	 * head at the end of that day.
	 *
	 * @param string $templateId The filinq template UUID.
	 * @param string $effectiveDate The ISO date the beschikking is effective.
	 *
	 * @return string|null The version, as `v<n>`, or null when the chain
	 *                     answers nothing for that date.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function versionInForce(string $templateId, string $effectiveDate): ?string {
		$versionService = FleetAppId::getService($this->container, 'filinq', self::TEMPLATE_VERSION_SERVICE);
		if ($versionService === null) {
			return null;
		}

		$moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($effectiveDate, 0, 10) . ' 23:59:59');
		if ($moment === false) {
			return null;
		}

		try {
			$number = $versionService->versionInForceAt($templateId, $moment);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Filinq could not say which version of a beschikking template was in force',
				['app' => AppIdentity::APP_ID, 'templateId' => $templateId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_int($number) === false || $number < 1) {
			return null;
		}

		return 'v' . $number;
	}//end versionInForce()

	/**
	 * The template object's own OpenRegister version, if it carries one.
	 *
	 * This is the fallback for a template that has never been versioned, and it
	 * is a real value read off the object rather than a default. It is returned
	 * verbatim, so an OpenRegister version (`0.0.3`) stays distinguishable from
	 * a chain version (`v3`) in everything that stores it.
	 *
	 * @param array<string, mixed> $template Filinq's serialised template object.
	 *
	 * @return string|null The version, or null when the object carries none.
	 */
	private function templateOwnVersion(array $template): ?string {
		$version = (((array)($template['@self'] ?? []))['version'] ?? ($template['version'] ?? null));

		if (is_int($version) === true && $version > 0) {
			return 'v' . $version;
		}

		if (is_string($version) === true && $version !== '') {
			return $version;
		}

		return null;
	}//end templateOwnVersion()

	/**
	 * The OpenRegister references filinq should resolve into the render context.
	 *
	 * An unconfigured register or case schema is not fatal: the template still
	 * renders from `adHocData`. It just cannot pull the stored case, so the
	 * omission is logged rather than swallowed.
	 *
	 * @param string $caseId The case UUID, or an empty string.
	 *
	 * @return array<int, array<string, string>> Filinq `dataRefs` entries.
	 */
	private function buildDataRefs(string $caseId): array {
		if ($caseId === '') {
			return [];
		}

		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('case_schema');
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Rendering a beschikking without the case object: register or case_schema is unset',
				['app' => AppIdentity::APP_ID, 'caseId' => $caseId]
			);
			return [];
		}

		return [['register' => $register, 'schema' => $schema, 'id' => $caseId]];
	}//end buildDataRefs()

	/**
	 * The stored document's basename.
	 *
	 * @param string $caseId The case UUID, or an empty string.
	 *
	 * @return string The filename stem.
	 */
	private function buildFilename(string $caseId): string {
		if ($caseId === '') {
			return 'beschikking';
		}

		return 'beschikking-' . $caseId;
	}//end buildFilename()

	/**
	 * Turn filinq's generation result into this seam's composition metadata.
	 *
	 * @param array<string, mixed> $result Filinq's `generateDocument()` return value.
	 * @param string $templateId The template that was rendered, for the error message.
	 *
	 * @return array{format: string, fileId: string, checksumSha256: string, paginas: int}
	 *
	 * @throws RuntimeException When filinq produced no bytes or did not store the file.
	 */
	private function toComposition(array $result, string $templateId): array {
		$content = (string)($result['content'] ?? '');
		$fileId = ($result['output']['fileId'] ?? null);

		if ($content === '' || $fileId === null) {
			// Filinq answered, but with nothing to point at. Reporting a
			// composition here is precisely the lie this adapter replaces.
			throw new RuntimeException(
				'filinq_render_empty: filinq returned no stored document for template ' . $templateId
			);
		}

		return [
			'format' => (string)($result['format'] ?? 'pdf'),
			'fileId' => (string)$fileId,
			'checksumSha256' => $this->checksumOf(result: $result, content: $content),
			'paginas' => (int)($result['pageCount'] ?? 0),
		];
	}//end toComposition()

	/**
	 * The SHA-256 of the produced document, as filinq reports it.
	 *
	 * Filinq holds the bytes and reports their digest with the result
	 * (generated-document-names-its-template-version). Only a filinq that does
	 * not report one is answered by hashing the returned bytes here.
	 *
	 * @param array<string, mixed> $result  Filinq's `generateDocument()` return value.
	 * @param string               $content The produced document bytes.
	 *
	 * @return string The lowercase hex digest.
	 */
	private function checksumOf(array $result, string $content): string {
		$reported = ($result['sha256'] ?? null);
		if (is_string($reported) === true && preg_match('/^[0-9a-f]{64}$/', $reported) === 1) {
			return $reported;
		}

		return hash('sha256', $content);
	}//end checksumOf()
}//end class
