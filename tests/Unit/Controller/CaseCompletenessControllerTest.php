<?php

/**
 * CaseCompletenessController tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseCompletenessController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\CaseCompleteness;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The read guard runs before anything about the case is read.
 *
 * @covers \OCA\Dossiq\Controller\CaseCompletenessController
 */
class CaseCompletenessControllerTest extends TestCase {

	/**
	 * Case access guard mock.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $access;

	/**
	 * Case store mock.
	 *
	 * @var CaseStatusStore|MockObject
	 */
	private CaseStatusStore $cases;

	/**
	 * Case type resolver mock.
	 *
	 * @var CaseTypeResolver|MockObject
	 */
	private CaseTypeResolver $caseTypes;

	/**
	 * Dossier mock.
	 *
	 * @var ZaakdossierService|MockObject
	 */
	private ZaakdossierService $dossier;

	/**
	 * Document reader mock.
	 *
	 * @var InformatieobjectReader|MockObject
	 */
	private InformatieobjectReader $reader;

	/**
	 * Build the controller.
	 *
	 * @param IUser|null $user The caller.
	 *
	 * @return CaseCompletenessController
	 */
	private function controller(?IUser $user): CaseCompletenessController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->access    = $this->createMock(CaseAccessGuard::class);
		$this->cases     = $this->createMock(CaseStatusStore::class);
		$this->caseTypes = $this->createMock(CaseTypeResolver::class);
		$this->dossier   = $this->createMock(ZaakdossierService::class);
		$this->reader    = $this->createMock(InformatieobjectReader::class);

		return new CaseCompletenessController(
			request: $this->createMock(IRequest::class),
			caseAccess: $this->access,
			cases: $this->cases,
			caseTypes: $this->caseTypes,
			dossier: $this->dossier,
			reader: $this->reader,
			completeness: new CaseCompleteness(),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A caller without read access on the case gets 403 and nothing is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function testACallerWhoMayNotReadTheCaseIsRefusedBeforeAnyRead(): void {
		$controller = $this->controller(user: $this->createMock(IUser::class));
		$this->access->method('hasCaseReadAccess')->willReturn(false);
		$this->cases->expects($this->never())->method('loadCase');
		$this->dossier->expects($this->never())->method('getDossierForCase');

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->show(caseId: 'case-1')->getStatus());
	}//end testACallerWhoMayNotReadTheCaseIsRefusedBeforeAnyRead()

	/**
	 * No session is 401.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function testNoSessionIsUnauthorized(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(user: null)->show(caseId: 'case-1')->getStatus());
	}//end testNoSessionIsUnauthorized()

	/**
	 * A case type without a list answers `{ items: [] }` without reading documents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function testACaseTypeWithoutAListAnswersEmpty(): void {
		$controller = $this->controller(user: $this->createMock(IUser::class));
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->cases->method('loadCase')->willReturn(['caseType' => 'ct-1']);
		$this->caseTypes->method('effectiveCaseType')->with('ct-1')->willReturn(['title' => 'Melding']);
		$this->dossier->expects($this->never())->method('getDossierForCase');

		$response = $controller->show(caseId: 'case-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['items' => []], $response->getData());
	}//end testACaseTypeWithoutAListAnswersEmpty()

	/**
	 * With a list, the documents are ticked and the reader's filter names the evidence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-completeness-checklist/specs/semantic-case-intake/spec.md#requirement-a-case-answers-how-complete-its-application-is
	 */
	public function testTheItemsAreTickedAgainstTheDocuments(): void {
		$controller = $this->controller(user: $this->createMock(IUser::class));
		$doc = ['id' => 'd1', 'bestandsnaam' => 'verzoek.pdf'];
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->cases->method('loadCase')->willReturn(['caseType' => ['id' => 'ct-woo']]);
		$this->caseTypes->method('effectiveCaseType')->with('ct-woo')->willReturn(
			['intakeRequirements' => ['completenessItems' => [['key' => 'verzoek', 'label' => 'Verzoek', 'documentTitleContains' => 'verzoek']]]]
		);
		$this->dossier->method('getDossierForCase')->with('case-1')->willReturn(['informatieobjecten' => [$doc]]);
		$this->reader->method('filterForUser')->willReturn([$doc]);

		$data = $controller->show(caseId: 'case-1')->getData();

		self::assertSame('verzoek.pdf', $data['items'][0]['evidence']);
		self::assertSame('complete', $data['verdict']);
	}//end testTheItemsAreTickedAgainstTheDocuments()
}//end class
