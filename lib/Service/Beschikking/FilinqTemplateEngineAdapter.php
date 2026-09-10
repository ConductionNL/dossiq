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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Support\FleetAppId;
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
	 * How many chain entries to read when resolving the version in force.
	 *
	 * Filinq returns them newest first, so this is a ceiling on how far back a
	 * beschikking's effective date may reach, not a page to iterate. A template
	 * with more than this many revisions before the date falls through to the
	 * template's own version rather than reporting a wrong one.
	 *
	 * @var integer
	 */
	private const VERSION_PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves filinq's services across the rename.
	 * @param SettingsService $settings Supplies the case register/schema a template binds to.
	 * @param IUserSession $userSession The acting user; filinq stores the output in their Files.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Render a beschikking template through filinq and store the result.
	 *
	 * @param string $templateId The filinq template UUID.
	 * @param array<string, mixed> $context The zaakdata + beschikking context.
	 *
	 * @return array{format: string, fileId: string, checksumSha256: string, paginas: int} Composition metadata.
	 *
	 * @throws RuntimeException When filinq is absent, no user is acting, or the render fails.
	 *
	 * @psalm-suppress MixedMethodCall filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedArrayAccess filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedAssignment filinq is an optional cross-app dependency.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver over the
	 *      app-id and namespace rename map; injecting it would add a dependency to say
	 *      the same thing.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function render(string $templateId, array $context): array {
		$documentService = FleetAppId::getService($this->container, 'filinq', self::DOCUMENT_SERVICE);
		if ($documentService === null) {
			throw new RuntimeException(
				'filinq_unavailable: beschikking_template_adapter names the filinq adapter, but '
				. 'filinq\'s DocumentService could not be resolved on this instance'
			);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// Filinq refuses to store a generated document without an owning
			// user, and a render whose file is never written would hand back a
			// null fileId dressed as a composition. Refuse instead.
			throw new RuntimeException('user_required: a beschikking render must run as a Nextcloud user');
		}

		$caseId = (string)($context['caseId'] ?? '');

		try {
			$result = $documentService->generateDocument(
				$templateId,
				$this->buildDataRefs(caseId: $caseId),
				[
					'format' => 'pdf',
					'adHocData' => $context,
					'userId' => $user->getUID(),
					'caseId' => $caseId,
					'filename' => $this->buildFilename(caseId: $caseId),
					'output' => ['mode' => 'both'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Filinq refused a beschikking render',
				['app' => Application::APP_ID, 'templateId' => $templateId, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('filinq_render_failed: ' . $e->getMessage(), 0, $e);
		}

		return $this->toComposition(result: (array)$result, templateId: $templateId);
	}//end render()

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
	 * What answers it instead is filinq's OWN version chain, which already
	 * ships: `TemplateVersionService::getVersions()` returns every stored
	 * version of a template, newest first, each with a monotonic `version`
	 * number and its own creation timestamp. The version in force on a date is
	 * the highest one created on or before that date, so `$effectiveDate` now
	 * selects rather than being echoed back. A beschikking issued in March can
	 * therefore name the template that produced it rather than the one that
	 * happens to be current when someone appeals.
	 *
	 * Two fallbacks, in order, and neither of them invents a number:
	 *
	 *   - No chain entry at or before the date (a template saved once and never
	 *     edited has no chain at all): the template object's own OpenRegister
	 *     version, verbatim, lifted from `@self`.
	 *   - Neither available: a refusal. `v1` is not a safe default when the
	 *     answer is on an appealable decision.
	 *
	 * When filinq gains a first-class effective-version query (#1063), this
	 * method becomes a call to it and the chain walk below moves out.
	 *
	 * @param string $templateId The filinq template UUID.
	 * @param string $effectiveDate The ISO date the beschikking is effective.
	 *
	 * @return array{templateId: string, version: string, effectiveDate: string} The resolved version.
	 *
	 * @throws RuntimeException When filinq is absent, does not know the template,
	 *                          or holds no version for it.
	 *
	 * @psalm-suppress MixedMethodCall filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedArrayAccess filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedAssignment filinq is an optional cross-app dependency.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver.
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

		try {
			$template = (array)$templateService->getTemplate($templateId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Filinq does not know the beschikking template',
				['app' => Application::APP_ID, 'templateId' => $templateId, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('filinq_template_unknown: ' . $templateId, 0, $e);
		}

		$version = $this->versionInForce(templateId: $templateId, effectiveDate: $effectiveDate);
		if ($version === null) {
			$version = $this->templateOwnVersion(template: $template);
		}

		if ($version === null) {
			throw new RuntimeException(
				'filinq_template_unversioned: filinq holds no version for template ' . $templateId
			);
		}

		return [
			'templateId' => $templateId,
			'version' => $version,
			'effectiveDate' => $effectiveDate,
		];
	}//end resolveVersion()

	/**
	 * The highest chain version created on or before the effective date.
	 *
	 * A version whose creation timestamp cannot be read is skipped rather than
	 * treated as ancient: an unreadable date must not win a comparison it never
	 * took part in.
	 *
	 * @param string $templateId The filinq template UUID.
	 * @param string $effectiveDate The ISO date the beschikking is effective.
	 *
	 * @return string|null The version, as `v<n>`, or null when the chain
	 *                     answers nothing for that date.
	 *
	 * @psalm-suppress MixedMethodCall filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedArrayAccess filinq is an optional cross-app dependency.
	 * @psalm-suppress MixedAssignment filinq is an optional cross-app dependency.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver.
	 */
	private function versionInForce(string $templateId, string $effectiveDate): ?string {
		$versionService = FleetAppId::getService($this->container, 'filinq', self::TEMPLATE_VERSION_SERVICE);
		if ($versionService === null) {
			return null;
		}

		$cutoff = strtotime($effectiveDate . ' 23:59:59');
		if ($cutoff === false) {
			return null;
		}

		try {
			$page = (array)$versionService->getVersions($templateId, self::VERSION_PAGE_SIZE);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Filinq could not list the versions of a beschikking template',
				['app' => Application::APP_ID, 'templateId' => $templateId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$highest = $this->highestVersionAtOrBefore(rows: (array)($page['results'] ?? []), cutoff: $cutoff);
		if ($highest === null) {
			return null;
		}

		return 'v' . $highest;
	}//end versionInForce()

	/**
	 * The highest version number among the chain rows at or before a moment.
	 *
	 * @param array<int, mixed> $rows Filinq's chain rows.
	 * @param integer $cutoff The last second of the effective date, as a timestamp.
	 *
	 * @return integer|null The version number, or null when no row qualifies.
	 */
	private function highestVersionAtOrBefore(array $rows, int $cutoff): ?int {
		$highest = null;

		foreach ($rows as $row) {
			$row = (array)$row;
			$created = strtotime((string)(((array)($row['@self'] ?? []))['created'] ?? ''));
			if ($created === false || $created > $cutoff) {
				continue;
			}

			$number = (int)($row['version'] ?? 0);
			if ($number > 0 && ($highest === null || $number > $highest)) {
				$highest = $number;
			}
		}

		return $highest;
	}//end highestVersionAtOrBefore()

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
				['app' => Application::APP_ID, 'caseId' => $caseId]
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
			'checksumSha256' => hash('sha256', $content),
			'paginas' => $this->countPages(content: $content),
		];
	}//end toComposition()

	/**
	 * Count the pages in the produced document.
	 *
	 * Counted out of the PDF's own page objects rather than assumed, because a
	 * constant page count was one of the three fields the mock invented. A
	 * non-PDF output has no page structure to read, so it reports zero rather
	 * than a guess.
	 *
	 * @param string $content The produced document bytes.
	 *
	 * @return int The page count, or 0 when the bytes carry no page structure.
	 */
	private function countPages(string $content): int {
		if (str_starts_with($content, '%PDF') === false) {
			return 0;
		}

		return preg_match_all('#/Type\s*/Page[^s]#', $content);
	}//end countPages()
}//end class
