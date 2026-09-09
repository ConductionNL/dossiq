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
	 * Filinq's template model carries a monotonic `version` and no effective
	 * dating, so the version effective on any date is the one the template
	 * currently holds. The date is echoed back rather than used to select,
	 * which is what filinq can actually answer — the mock's constant `v1` was
	 * not a version at all.
	 *
	 * @param string $templateId The filinq template UUID.
	 * @param string $effectiveDate The ISO date the beschikking is effective.
	 *
	 * @return array{templateId: string, version: string, effectiveDate: string} The resolved version.
	 *
	 * @throws RuntimeException When filinq is absent or does not know the template.
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

		return [
			'templateId' => $templateId,
			'version' => 'v' . (string)((int)($template['version'] ?? 1)),
			'effectiveDate' => $effectiveDate,
		];
	}//end resolveVersion()

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
