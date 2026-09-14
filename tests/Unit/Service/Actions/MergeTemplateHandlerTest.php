<?php

/**
 * Unit tests for MergeTemplateHandler — rendering a template into a case field.
 *
 * The behaviour worth protecting is the WRITE DISCIPLINE: only the target
 * field reaches the stored case, a dry run writes nothing, and a missing
 * target field fails loudly.
 *
 * WHERE THE runAs TESTS WENT. This suite used to assert that the handler
 * wrapped its write in dossiq's FlowRunAsScope (the 'Anonymous' refusal on run
 * f087ae22). That duty moved into the engine: RegistryStepDispatcher executes
 * every contributed node — and the handlers those nodes delegate to — inside
 * `ObjectService::runAs()` as the run's validated acting identity
 * (openregister#3332, proven by its RegistryStepDispatcherRunAsTest). The
 * local wrap is deleted, so asserting it here would re-encode the retired
 * requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\Service\Actions\MergeTemplateHandler;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Actions\MergeTemplateHandler
 *
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class MergeTemplateHandlerTest extends TestCase {

	/**
	 * The case the object service was asked to save, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * The object service double behind the handler.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * The upload the dossier double was asked for, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $uploaded = null;

	protected function setUp(): void {
		$this->saved = null;
		$this->uploaded = null;

		$saved = &$this->saved;
		$this->objectService = new class($saved) {
			/**
			 * @param array<string, mixed>|null $sink Receives the saved case.
			 */
			public function __construct(private ?array &$sink) {
			}

			/**
			 * @param array<string, mixed> $object   The object to save.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->sink = $object;

				return $object;
			}

			/**
			 * PATCH-semantic, like the real seam the handler writes through.
			 *
			 * @param string               $objectId The case id.
			 * @param array<string, mixed> $data     The partial payload.
			 * @param string|null          $register The register.
			 * @param string|null          $schema   The schema.
			 *
			 * @return array<string, mixed> The written fields so far.
			 */
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->sink = array_merge(($this->sink ?? []), $data);

				return $this->sink;
			}
		};
	}//end setUp()

	/**
	 * A handler over the recording object service.
	 *
	 * @return MergeTemplateHandler The handler under test.
	 */
	private function handler(?ZaakdossierService $dossier = null): MergeTemplateHandler {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dossier): object {
				if ($id === ZaakdossierService::class) {
					if ($dossier === null) {
						throw new \RuntimeException('ZaakdossierService not available');
					}

					return $dossier;
				}

				return $this->objectService;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Els Jansen');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				unset($app, $default);

				return ($key === 'register') ? 'dossiq' : 'case';
			}
		);

		return new MergeTemplateHandler(
			container: $container,
			appConfig: $appConfig,
			caseWriter: new CaseFieldWriter(),
			userSession: $userSession,
			logger: new NullLogger(),
		);
	}//end handler()

	public function testTheRenderedTemplateIsSavedIntoTheTargetField(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Besluit over Kapvergunning', $this->saved['besluitDocument']);
	}//end testTheRenderedTemplateIsSavedIntoTheTargetField()

	public function testADryRunPersistsNothing(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Besluit over {{case.title}}',
				'targetField' => 'besluitDocument',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: ['dryRun' => true]
		);

		self::assertTrue($result->succeeded);
		self::assertSame('Besluit over Kapvergunning', $result->data['rendered']);
		self::assertNull($this->saved, 'A dry run must not write the case.');
	}//end testADryRunPersistsNothing()

	/**
	 * A dossier service double recording the one upload it is asked for.
	 *
	 * @return ZaakdossierService The recording double.
	 */
	private function recordingDossier(): ZaakdossierService {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->method('uploadDocument')->willReturnCallback(
			function (string $caseId, string $fileName, string $content, array $metadata): array {
				$this->uploaded = [
					'caseId' => $caseId,
					'fileName' => $fileName,
					'content' => $content,
					'metadata' => $metadata,
				];

				return ['id' => 'inf-1'];
			}
		);

		return $dossier;
	}//end recordingDossier()

	/**
	 * REQ-BES-012: no targetField files the render in the case dossier.
	 *
	 * @return void
	 */
	public function testNoTargetFieldFilesTheRenderInTheDossier(): void {
		$result = $this->handler($this->recordingDossier())->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Wij hebben uw aanvraag {{case.title}} ontvangen.',
				'templateName' => 'Ontvangstbevestiging',
				'documentType' => 'iot-uitgaand',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: []
		);

		self::assertTrue($result->succeeded, (string)$result->error);
		self::assertSame('inf-1', $result->data['informatieobject']);
		self::assertSame('case-1', $this->uploaded['caseId']);
		self::assertSame(
			'Wij hebben uw aanvraag Kapvergunning ontvangen.',
			$this->uploaded['content']
		);
		self::assertNull($this->saved, 'The case itself must not be written.');
	}//end testNoTargetFieldFilesTheRenderInTheDossier()

	/**
	 * The filed document carries the title, direction and author the spec names.
	 *
	 * @return void
	 */
	public function testTheFiledDocumentCarriesTheSpecifiedMetadata(): void {
		$this->handler($this->recordingDossier())->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Beste lezer',
				'templateName' => 'Ontvangstbevestiging',
				'documentType' => 'iot-uitgaand',
			],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertSame('Ontvangstbevestiging', $this->uploaded['metadata']['title']);
		self::assertSame('outgoing', $this->uploaded['metadata']['direction']);
		self::assertSame('Els Jansen', $this->uploaded['metadata']['auteur']);
		self::assertSame('ontvangstbevestiging.md', $this->uploaded['fileName']);
	}//end testTheFiledDocumentCarriesTheSpecifiedMetadata()

	/**
	 * REQ-005: the template's documentType follows into the informatieobject.
	 *
	 * @return void
	 */
	public function testTheTemplatesDocumentTypeFollowsIntoTheDossier(): void {
		$this->handler($this->recordingDossier())->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Beste lezer',
				'templateName' => 'Verdagingsbrief',
				'documentType' => 'iot-verdaging',
			],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertSame(
			'iot-verdaging',
			$this->uploaded['metadata']['informatieobjecttype']
		);
	}//end testTheTemplatesDocumentTypeFollowsIntoTheDossier()

	/**
	 * A template naming a field the case does not have creates NOTHING.
	 *
	 * renderTemplate() blanks an unknown path, which is right for a case field
	 * and wrong for a letter: a document with a hole where the addressee
	 * belongs would file successfully and go out wrong.
	 *
	 * @return void
	 */
	public function testAFailedRenderLeavesTheDossierUntouched(): void {
		$result = $this->handler($this->recordingDossier())->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Beste {{case.geadresseerde.naam}}',
				'templateName' => 'Ontvangstbevestiging',
				'documentType' => 'iot-uitgaand',
			],
			case: ['id' => 'case-1', 'title' => 'Kapvergunning'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_template_field:case.geadresseerde.naam', $result->error);
		self::assertNull($this->uploaded, 'No informatieobject and no join.');
	}//end testAFailedRenderLeavesTheDossierUntouched()

	/**
	 * A template with no document type cannot be filed, and says so.
	 *
	 * @return void
	 */
	public function testATemplateWithoutADocumentTypeCreatesNothing(): void {
		$result = $this->handler($this->recordingDossier())->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Beste lezer',
				'templateName' => 'Ontvangstbevestiging',
			],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_document_type', $result->error);
		self::assertNull($this->uploaded);
	}//end testATemplateWithoutADocumentTypeCreatesNothing()

	/**
	 * With the dossier service unavailable, nothing is created and the step
	 * fails loudly rather than reporting a document nobody can open.
	 *
	 * @return void
	 */
	public function testAnUnavailableDossierServiceFailsTheStep(): void {
		$result = $this->handler()->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Beste lezer',
				'templateName' => 'Ontvangstbevestiging',
				'documentType' => 'iot-uitgaand',
			],
			case: ['id' => 'case-1'],
			transitionContext: []
		);

		self::assertFalse($result->succeeded);
		self::assertSame('dossier_service_unavailable', $result->error);
	}//end testAnUnavailableDossierServiceFailsTheStep()

	/**
	 * A dry run still previews, and still writes nothing, on either branch.
	 *
	 * @return void
	 */
	public function testADryRunWithoutATargetFieldFilesNothing(): void {
		$result = $this->handler($this->recordingDossier())->handle(
			actionConfig: [
				'type' => 'mergeTemplate',
				'template' => 'Beste lezer',
				'templateName' => 'Ontvangstbevestiging',
				'documentType' => 'iot-uitgaand',
			],
			case: ['id' => 'case-1'],
			transitionContext: ['dryRun' => true]
		);

		self::assertTrue($result->succeeded);
		self::assertNull($this->uploaded);
	}//end testADryRunWithoutATargetFieldFilesNothing()
}//end class
