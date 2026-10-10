<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PipelinqCaseController;
use OCA\Dossiq\Controller\PipelinqContactMomentController;
use OCA\Dossiq\Controller\PipelinqProgrammeController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\People\PartyVocabulary;
use OCA\Dossiq\Service\Pipelinq\ContactMomentBridge;
use OCA\Dossiq\Service\Pipelinq\CorrespondenceLanguageConsumer;
use OCA\Dossiq\Service\Pipelinq\PartyKindConsumer;
use OCA\Dossiq\Service\Pipelinq\PipelinqGateway;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The pipelinq case surfaces, through the REAL consumers and gateway with
 * pipelinq doubles carrying pipelinq's real method signatures.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
 */
class PipelinqCaseControllerTest extends TestCase {

	/**
	 * The pipelinq services this instance has, keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/** @var CaseAccessGuard&MockObject */
	private CaseAccessGuard $access;

	/** @var MilestoneRepository&MockObject */
	private MilestoneRepository $cases;

	/** @var ContactMomentService&MockObject */
	private ContactMomentService $log;

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/**
	 * Fresh doubles, a signed-in user with full access by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->access = $this->createMock(CaseAccessGuard::class);
		$this->access->method('hasCaseReadAccess')->willReturn(true);
		$this->cases = $this->createMock(MilestoneRepository::class);
		$this->log = $this->createMock(ContactMomentService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * The gateway over the pipelinq services this instance has.
	 *
	 * @return PipelinqGateway The gateway.
	 */
	private function gateway(): PipelinqGateway {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException("nothing answers to {$id}");
				}

				return $this->services[$id];
			}
		);

		return new PipelinqGateway($container, $this->createMock(LoggerInterface::class));
	}//end gateway()

	/**
	 * The contact moment controller over the real bridge.
	 *
	 * @return PipelinqContactMomentController The controller.
	 */
	private function momentsController(): PipelinqContactMomentController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PipelinqContactMomentController(
			'dossiq',
			$this->request,
			new ContactMomentBridge($this->gateway(), $this->createMock(LoggerInterface::class)),
			$this->log,
			$this->access,
			$this->session,
			$l10n,
		);
	}//end momentsController()

	/**
	 * The programme controller over the real consumer.
	 *
	 * @return PipelinqProgrammeController The controller.
	 */
	private function programmeController(): PipelinqProgrammeController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PipelinqProgrammeController(
			'dossiq',
			$this->request,
			new ProgrammeConsumer($this->gateway(), $this->createMock(LoggerInterface::class)),
			$this->access,
			$this->session,
			$l10n,
		);
	}//end programmeController()

	/**
	 * The controller over real consumers.
	 *
	 * @return PipelinqCaseController The controller.
	 */
	private function controller(): PipelinqCaseController {
		$logger = $this->createMock(LoggerInterface::class);
		$gateway = $this->gateway();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PipelinqCaseController(
			'dossiq',
			$this->request,
			$gateway,
			new PartyKindConsumer($gateway, new PartyVocabulary($l10n), $this->createMock(SettingsService::class), $logger),
			new CorrespondenceLanguageConsumer($gateway, $logger),
			$this->cases,
			$this->access,
			$this->session,
			$l10n,
		);
	}//end controller()

	/**
	 * A filing double with pipelinq's real signatures, recording what it was asked.
	 *
	 * @param int $status The status pipelinq answers.
	 *
	 * @return object The double.
	 */
	private function filing(int $status = 200): object {
		return new class($status) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/**
			 * @param int $status The answer.
			 */
			public function __construct(private int $status) {
			}

			/**
			 * @param string $momentId The moment.
			 * @param string $caseId The case.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function fileOnAlsoCase(string $momentId, string $caseId): array {
				$this->calls[] = ['fileOnAlsoCase', $momentId, $caseId];

				return $this->status === 200 ? ['status' => 200] : ['status' => $this->status, 'error' => 'This contact moment is already on that case.'];
			}

			/**
			 * @param string $momentId The moment.
			 * @param string $caseId The case.
			 * @param string|null $newPrimary The new primary.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): array {
				$this->calls[] = ['unfileFromCase', $momentId, $caseId];

				return ['status' => $this->status];
			}
		};
	}//end filing()

	/**
	 * The moments come by membership with the shared marker, and an absent pipelinq says so.
	 *
	 * @return void
	 */
	public function testContactMomentsSayWhenPipelinqIsAbsent(): void {
		$absent = $this->momentsController()->contactMoments(caseId: 'case-b')->getData();
		$this->assertFalse($absent['available'], 'An absent pipelinq is not a case with no contact moments.');

		$this->services[PipelinqGateway::CONTACT_MOMENTS] = new class {
			/**
			 * @param string $hostId The case.
			 * @param int $limit The limit.
			 * @param string $partyId The party.
			 *
			 * @return array<string, mixed> The listing.
			 */
			public function list(string $hostId, int $limit = 50, string $partyId = ''): array {
				return [
					'status' => 200,
					'contactMoments' => [
						['id' => 'm-1', 'shared' => true, 'alsoOnCases' => ['case-a'], 'alsoOnHiddenCount' => 1, 'primaryCase' => 'case-a'],
					],
					'indicators' => [],
				];
			}
		};

		$data = $this->momentsController()->contactMoments(caseId: 'case-b')->getData();
		$this->assertTrue($data['available']);
		$this->assertSame(['case-a'], $data['moments'][0]['alsoOnCases']);
		$this->assertSame(1, $data['moments'][0]['alsoOnHiddenCount'], 'A case the reader may not see is counted, not named.');
	}//end testContactMomentsSayWhenPipelinqIsAbsent()

	/**
	 * A reader without access to the case gets nothing from pipelinq.
	 *
	 * @return void
	 */
	public function testAReaderWithoutAccessIsRefused(): void {
		$this->access = $this->createMock(CaseAccessGuard::class);
		$this->access->method('hasCaseReadAccess')->willReturn(false);

		$response = $this->momentsController()->contactMoments(caseId: 'case-b');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAReaderWithoutAccessIsRefused()

	/**
	 * Filing goes through pipelinq's act, and needs mutation access on BOTH cases.
	 *
	 * @return void
	 */
	public function testFilingNeedsBothCasesAndGoesThroughPipelinq(): void {
		$filing = $this->filing();
		$this->services[PipelinqGateway::CONTACT_MOMENT_FILING] = $filing;

		$this->access->method('hasCaseMutationAccess')->willReturnCallback(
			static fn (string $caseId): bool => $caseId === 'case-b'
		);

		$refused = $this->momentsController()->fileContactMoment(caseId: 'case-b', momentId: 'm-1', targetCaseId: 'case-x');
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus(), 'Putting a call on a case is a change to that case.');
		$this->assertSame([], $filing->calls, 'Nothing was asked of pipelinq for a refused caller.');
	}//end testFilingNeedsBothCasesAndGoesThroughPipelinq()

	/**
	 * A permitted filing reaches pipelinq's act with the target case.
	 *
	 * @return void
	 */
	public function testAPermittedFilingReachesPipelinqsAct(): void {
		$filing = $this->filing();
		$this->services[PipelinqGateway::CONTACT_MOMENT_FILING] = $filing;
		$this->access->method('hasCaseMutationAccess')->willReturn(true);

		$response = $this->momentsController()->fileContactMoment(caseId: 'case-b', momentId: 'm-1', targetCaseId: 'case-x');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['fileOnAlsoCase', 'm-1', 'case-x']], $filing->calls);
	}//end testAPermittedFilingReachesPipelinqsAct()

	/**
	 * Filing onto the same case, or onto nothing, is refused before pipelinq is asked.
	 *
	 * @return void
	 */
	public function testFilingOntoTheSameCaseIsRefused(): void {
		$filing = $this->filing();
		$this->services[PipelinqGateway::CONTACT_MOMENT_FILING] = $filing;
		$this->access->method('hasCaseMutationAccess')->willReturn(true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->momentsController()->fileContactMoment(caseId: 'case-b', momentId: 'm-1', targetCaseId: 'case-b')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->momentsController()->fileContactMoment(caseId: 'case-b', momentId: 'm-1')->getStatus());
		$this->assertSame([], $filing->calls);
	}//end testFilingOntoTheSameCaseIsRefused()

	/**
	 * pipelinq refusing an act is a 409 with pipelinq's reason, for the handler to read.
	 *
	 * @return void
	 */
	public function testARefusedActCarriesPipelinqsReason(): void {
		$this->services[PipelinqGateway::CONTACT_MOMENT_FILING] = $this->filing(status: 409);
		$this->access->method('hasCaseMutationAccess')->willReturn(true);

		$response = $this->momentsController()->fileContactMoment(caseId: 'case-b', momentId: 'm-1', targetCaseId: 'case-x');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This contact moment is already on that case.', $response->getData()['error']);
	}//end testARefusedActCarriesPipelinqsReason()

	/**
	 * Unfiling takes the moment off THIS case only, through pipelinq.
	 *
	 * @return void
	 */
	public function testUnfilingTakesItOffThisCase(): void {
		$filing = $this->filing();
		$this->services[PipelinqGateway::CONTACT_MOMENT_FILING] = $filing;
		$this->access->method('hasCaseMutationAccess')->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->momentsController()->unfileContactMoment(caseId: 'case-b', momentId: 'm-1')->getStatus());
		$this->assertSame([['unfileFromCase', 'm-1', 'case-b']], $filing->calls);
	}//end testUnfilingTakesItOffThisCase()

	/**
	 * The kinds are asked for the case's OWN type, read server-side.
	 *
	 * @return void
	 */
	public function testPartyKindsAreAskedForTheCasesType(): void {
		$this->cases->method('findCaseTypeId')->willReturn('ct-subsidie');
		$asked = '';
		$this->services[PipelinqGateway::PARTY_KINDS] = new class($asked) {
			/**
			 * @param string $asked Captures the record type.
			 */
			public function __construct(public string &$asked) {
			}

			/**
			 * @param string $recordType The target.
			 *
			 * @return array<int, array<string, mixed>> The kinds.
			 */
			public function kindsFor(string $recordType): array {
				$this->asked = $recordType;

				return [['code' => 'aanvrager', 'label' => 'Aanvrager'], ['code' => 'gemachtigde', 'label' => 'Gemachtigde']];
			}
		};

		$data = $this->controller()->partyKinds(caseId: 'case-b')->getData();

		$this->assertSame('pipelinq', $data['source']);
		$this->assertSame(['aanvrager', 'gemachtigde'], array_column($data['kinds'], 'code'));
		$this->assertSame('dossiq:case:ct-subsidie', $asked);
	}//end testPartyKindsAreAskedForTheCasesType()

	/**
	 * Without pipelinq the language says nothing can be read, rather than inventing a choice.
	 *
	 * @return void
	 */
	public function testTheLanguageWithoutPipelinqIsNotAChoice(): void {
		$data = $this->controller()->partyLanguage(caseId: 'case-b', partyId: 'party-1')->getData();

		$this->assertFalse($data['available']);
		$this->assertFalse($data['stated'], 'An unset preference is not dressed up as chosen.');
		$this->assertSame('nl', $data['language']);
	}//end testTheLanguageWithoutPipelinqIsNotAChoice()

	/**
	 * A link pipelinq refuses is a 409 naming the programme that already holds the case.
	 *
	 * @return void
	 */
	public function testARefusedLinkNamesTheHolder(): void {
		$this->access->method('hasCaseMutationAccess')->willReturn(true);
		$this->services[PipelinqGateway::PROGRAMMES] = new class {
			/**
			 * @param string $programmeId The programme.
			 * @param string $domainObjectType The type.
			 * @param string $domainObjectRef The object.
			 * @param string $title The title.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function linkWork(string $programmeId, string $domainObjectType, string $domainObjectRef, string $title = ''): array {
				return ['status' => 409, 'error' => 'This work is already in programme p-1.'];
			}
		};

		$response = $this->programmeController()->linkProgramme(caseId: 'case-b', programmeId: 'p-2', title: 'Subsidie buurtfeest');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('This work is already in programme p-1.', $response->getData()['error']);
	}//end testARefusedLinkNamesTheHolder()

	/**
	 * Without pipelinq the programme of a case is "cannot be read", not "none", and a
	 * caller who may not read the case is refused before pipelinq is asked.
	 *
	 * @return void
	 */
	public function testTheProgrammeOfACaseNeedsReadAccessAndSaysWhenPipelinqIsAbsent(): void {
		$data = $this->programmeController()->programme(caseId: 'case-b')->getData();

		$this->assertFalse($data['available']);
		$this->assertNull($data['programme']);

		$refusing = $this->createMock(CaseAccessGuard::class);
		$refusing->method('hasCaseReadAccess')->willReturn(false);
		$this->access = $refusing;
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->programmeController()->programme(caseId: 'case-b')->getStatus());
	}//end testTheProgrammeOfACaseNeedsReadAccessAndSaysWhenPipelinqIsAbsent()

	/**
	 * The programmes on offer are none, and say so, when pipelinq is absent.
	 *
	 * @return void
	 */
	public function testNoProgrammesAreOfferedWithoutPipelinq(): void {
		$data = $this->programmeController()->programmeOptions()->getData();

		$this->assertFalse($data['available']);
		$this->assertSame([], $data['programmes']);
	}//end testNoProgrammesAreOfferedWithoutPipelinq()

	/**
	 * A caller who may not change the case cannot put it under a programme.
	 *
	 * @return void
	 */
	public function testLinkingNeedsMutationAccess(): void {
		$this->access->method('hasCaseMutationAccess')->willReturn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->programmeController()->linkProgramme(caseId: 'case-b', programmeId: 'p-2')->getStatus());
	}//end testLinkingNeedsMutationAccess()

	/**
	 * The editor gets the whole vocabulary and the declaration, null when none.
	 *
	 * @return void
	 */
	public function testTheEditorGetsVocabularyAndDeclaration(): void {
		$data = $this->controller()->caseTypePartyKinds(caseTypeId: 'ct-1')->getData();

		$this->assertSame('dossiq', $data['source']);
		$this->assertNull($data['accepted'], 'Nothing declared is not "accepts nothing".');
		$this->assertFalse($data['available']);
	}//end testTheEditorGetsVocabularyAndDeclaration()

	/**
	 * Declaring without pipelinq is refused with the reason, not reported as done.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutPipelinqIsRefused(): void {
		$response = $this->controller()->declarePartyKinds(caseTypeId: 'ct-1', kinds: ['aanvrager']);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertFalse($response->getData()['declared']);
	}//end testADeclarationWithoutPipelinqIsRefused()
	/**
	 * Logging needs mutation access, and nothing is written for a refused caller.
	 *
	 * @return void
	 */
	public function testLoggingNeedsMutationAccess(): void {
		$this->access->method('hasCaseMutationAccess')->willReturn(false);
		$this->log->expects($this->never())->method('createContactMoment');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->momentsController()->logContactMoment(caseId: 'case-b')->getStatus());
	}//end testLoggingNeedsMutationAccess()

	/**
	 * A logged moment is written on THIS case, and pipelinq's refusal travels back with the indicator.
	 *
	 * @return void
	 */
	public function testALoggedMomentCarriesPipelinqsRefusal(): void {
		$this->access->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => [
				'notificationChannel' => 'phone',
				'direction' => 'outbound',
				'summary' => 'Teruggebeld over de planning.',
				'case' => 'some-other-case',
			][$key] ?? $default
		);

		$written = [];
		$this->log->method('createContactMoment')->willReturnCallback(
			static function (array $data) use (&$written): array {
				$written = $data;

				return $data + [
					'id' => 'cm-1',
					'pipelinqRefusal' => 'An indicator blocks outbound contact.',
					'pipelinqIndicators' => [['code' => 'GEEN_UITGAAND', 'label' => 'Geen uitgaand contact']],
				];
			}
		);

		$response = $this->momentsController()->logContactMoment(caseId: 'case-b');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus(), 'The dossiq record is written whatever pipelinq says.');
		$this->assertSame('case-b', $written['case'], 'The case comes from the route, never from the body.');
		$this->assertSame(['case-b'], $written['relatedCases']);
		$this->assertSame('outbound', $written['direction']);
		$this->assertSame('An indicator blocks outbound contact.', $data['pipelinqRefusal']);
		$this->assertSame('Geen uitgaand contact', $data['pipelinqIndicators'][0]['label']);
	}//end testALoggedMomentCarriesPipelinqsRefusal()

	/**
	 * An invalid moment is a 400 the dialog can show, not a 500.
	 *
	 * @return void
	 */
	public function testAnInvalidMomentIsABadRequest(): void {
		$this->access->method('hasCaseMutationAccess')->willReturn(true);
		$this->log->method('createContactMoment')->willThrowException(new RuntimeException('Invalid kanaal'));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->momentsController()->logContactMoment(caseId: 'case-b')->getStatus());
	}//end testAnInvalidMomentIsABadRequest()
}//end class
