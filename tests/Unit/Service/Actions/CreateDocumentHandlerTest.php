<?php

/**
 * CreateDocumentHandler Unit Tests
 *
 * The handler had no test at all. It soft-bound `renderAndAttach` on
 * `ZgwDocumentService`, a method that class has never had, and when
 * `method_exists()` said no it fell through to
 * `succeeded: true, documentId: null`. A workflow configured to generate a
 * document recorded a successful generation and filed nothing.
 *
 * These tests assert the effect, not the envelope: the dossier is asked to
 * store the rendered body under the configured name and document type, and
 * the id it hands back is the id the action reports. A handler that files
 * nothing fails here whatever it returns.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\CreateDocumentHandler;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Actions\CreateDocumentHandler
 *
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class CreateDocumentHandlerTest extends TestCase {

	/**
	 * A handler over a container that answers with the given dossier service.
	 *
	 * @param ZaakdossierService|null $dossier The service, or null to make the
	 *                                         container refuse.
	 *
	 * @return CreateDocumentHandler The handler under test.
	 */
	private function handler(?ZaakdossierService $dossier): CreateDocumentHandler {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dossier): object {
				if ($id === ZaakdossierService::class && $dossier !== null) {
					return $dossier;
				}

				throw new class('not registered') extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Ruben');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CreateDocumentHandler(
			container: $container,
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end handler()

	/**
	 * The action files the rendered document in the case dossier.
	 *
	 * This is the assertion that did not exist. The old body reached the same
	 * `succeeded: true` without asking the dossier for anything.
	 *
	 * @return void
	 */
	public function testFilesTheRenderedDocumentInTheDossier(): void {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->expects(self::once())
			->method('uploadDocument')
			->with(
				'case-3',
				'besluit-ZK-2024-001.md',
				"Beste Jansen,\n\nUw aanvraag is toegekend.",
				[
					'title' => 'besluit-ZK-2024-001.md',
					'informatieobjecttype' => 'besluit',
					'direction' => 'outgoing',
					'auteur' => 'Ruben',
					'format' => 'text/markdown',
				]
			)
			->willReturn(['id' => 'doc-9']);

		$result = $this->handler($dossier)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => "Beste {{case.indiener.naam}},\n\nUw aanvraag is {{case.mergeFields.uitkomst}}.",
				'outputName' => 'besluit-{{case.title}}.md',
				'documentType' => 'besluit',
				'mergeFields' => ['uitkomst' => 'toegekend'],
			],
			case: [
				'id' => 'case-3',
				'title' => 'ZK-2024-001',
				'indiener' => ['naam' => 'Jansen'],
			],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('doc-9', $result->data['documentId']);
		self::assertSame('case-3', $result->data['case']);
	}//end testFilesTheRenderedDocumentInTheDossier()

	/**
	 * A dry run previews the rendered document and files nothing.
	 *
	 * @return void
	 */
	public function testADryRunFilesNothing(): void {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->expects(self::never())->method('uploadDocument');

		$result = $this->handler($dossier)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => 'Besluit voor {{case.title}}',
				'outputName' => 'besluit.md',
				'documentType' => 'besluit',
			],
			case: ['id' => 'case-3', 'title' => 'ZK-2024-001'],
			transitionContext: ['dryRun' => true],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Besluit voor ZK-2024-001', $result->data['body']);
	}//end testADryRunFilesNothing()

	/**
	 * A template with a hole where the addressee should be files nothing.
	 *
	 * The renderer blanks an unknown path rather than leaking template syntax,
	 * which is right for a case field and wrong for a letter that goes out
	 * under the council's name. The refusal happens before the first write.
	 *
	 * @return void
	 */
	public function testAnUnresolvablePlaceholderFilesNothing(): void {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->expects(self::never())->method('uploadDocument');

		$result = $this->handler($dossier)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => 'Beste {{case.indiener.naam}},',
				'outputName' => 'besluit.md',
				'documentType' => 'besluit',
			],
			case: ['id' => 'case-3'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_template_field:case.indiener.naam', $result->error);
	}//end testAnUnresolvablePlaceholderFilesNothing()

	/**
	 * Without a document type the dossier schema cannot accept the file, and
	 * guessing a type for an outgoing letter is worse than refusing.
	 *
	 * @return void
	 */
	public function testFailsWithoutADocumentType(): void {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->expects(self::never())->method('uploadDocument');

		$result = $this->handler($dossier)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => 'Tekst',
				'outputName' => 'besluit.md',
			],
			case: ['id' => 'case-3'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_document_type', $result->error);
	}//end testFailsWithoutADocumentType()

	/**
	 * A case with no id has no dossier to file into.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseHasNoId(): void {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->expects(self::never())->method('uploadDocument');

		$result = $this->handler($dossier)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => 'Tekst',
				'outputName' => 'besluit.md',
				'documentType' => 'besluit',
			],
			case: [],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_case_id', $result->error);
	}//end testFailsWhenTheCaseHasNoId()

	/**
	 * A dossier the container cannot produce is a failure, not a document.
	 *
	 * @return void
	 */
	public function testAnUnavailableDossierIsAFailure(): void {
		$result = $this->handler(null)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => 'Tekst',
				'outputName' => 'besluit.md',
				'documentType' => 'besluit',
			],
			case: ['id' => 'case-3'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('document_service_unavailable', $result->error);
	}//end testAnUnavailableDossierIsAFailure()

	/**
	 * An upload that throws is reported as a failure, not swallowed.
	 *
	 * @return void
	 */
	public function testAFailedUploadIsReportedAsAFailure(): void {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->method('uploadDocument')
			->willThrowException(new RuntimeException('informatieobjecttype is required'));

		$result = $this->handler($dossier)->handle(
			actionConfig: [
				'type' => 'createDocument',
				'templateSlug' => 'Tekst',
				'outputName' => 'besluit.md',
				'documentType' => 'besluit',
			],
			case: ['id' => 'case-3'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('document_create_failed', $result->error);
	}//end testAFailedUploadIsReportedAsAFailure()
}//end class
