<?php

/**
 * EmailTemplateController Wire-Contract Tests
 *
 * Contract coverage (gate-25) for the four EmailTemplateController endpoints
 * that had no automated proof of their wire behaviour. The controller mixes
 * three DIFFERENT authorization postures in one class, and the whole point of
 * these tests is that a copy-paste between them is silent:
 *
 *  - `createTemplate()` and `saveSettings()` are INSTANCE CONFIG writes and are
 *    admin-only — `createTemplate()` proves it by asking IGroupManager about
 *    the SESSION's uid (asking about a uid taken from the request would be the
 *    realistic defect), and refuses with 403 `forbidden`;
 *  - `prefillDraft()` is a PER-CASE READ of citizen contact data and must ask
 *    CaseAccessGuard about the caseId ON THE ROUTE before rendering anything;
 *  - `variables()` returns a constant catalogue and needs only a session.
 *
 * Beyond authorization the tests pin the status codes the branches actually
 * use — 400 on a rejected create, 409 on a draft that cannot be rendered — and
 * the one piece of behaviour in `saveSettings()` that cannot be seen from the
 * response body at all: the password is stored with the `sensitive` flag, and
 * the masked placeholder `***` is treated as "unchanged" rather than written
 * back over the real credential.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\EmailTemplateController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Email\MailGatewayInterface;
use OCA\Dossiq\Service\Email\SenderBlocklist;
use OCA\Dossiq\Service\EmailTemplateService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for EmailTemplateController.
 *
 * @covers \OCA\Dossiq\Controller\EmailTemplateController
 */
class EmailTemplateControllerContractTest extends TestCase {

	/**
	 * The IRequest mock handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The templating backend.
	 *
	 * @var EmailTemplateService|MockObject
	 */
	private EmailTemplateService $templateService;

	/**
	 * The settings resolver (kept live for future per-type expansion).
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The app config the IMAP settings are written to.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The group manager consulted for the admin checks.
	 *
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * The per-case authorization guard.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The gateway that names the accounts intake can read.
	 *
	 * @var MailGatewayInterface|MockObject
	 */
	private MailGatewayInterface $mailGateway;

	/**
	 * Who may not open a case by mail.
	 *
	 * @var SenderBlocklist|MockObject
	 */
	private SenderBlocklist $blocklist;

	/**
	 * The controller under test.
	 *
	 * @var EmailTemplateController
	 */
	private EmailTemplateController $controller;

	/**
	 * Build the controller with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->templateService = $this->createMock(EmailTemplateService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);
		$this->mailGateway = $this->createMock(MailGatewayInterface::class);
		$this->blocklist = $this->createMock(SenderBlocklist::class);

		$this->controller = new EmailTemplateController(
			request: $this->request,
			templateService: $this->templateService,
			settingsService: $this->settingsService,
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			caseAccessGuard: $this->caseAccessGuard,
			mailGateway: $this->mailGateway,
			blocklist: $this->blocklist,
		);
	}//end setUp()

	/**
	 * Make `getParam()` behave like the real request: return the override when
	 * one is configured, otherwise the caller's own default.
	 *
	 * @param array<string, mixed> $overrides Parameter values to serve.
	 *
	 * @return void
	 */
	private function withRequestParams(array $overrides): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($overrides): mixed {
				return ($overrides[$key] ?? $default);
			}
		);
	}//end withRequestParams()

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The uid the session user reports.
	 *
	 * @return IUser|MockObject The session user.
	 */
	private function signIn(string $uid = 'handler'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * Every one of the four endpoints refuses an anonymous caller with 401 and
	 * touches neither the templating backend nor the app config.
	 *
	 * @return void
	 */
	public function testAllFourEndpointsRefuseAnAnonymousCallerWith401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->templateService->expects($this->never())->method('createTemplate');
		$this->templateService->expects($this->never())->method('prefillDraft');
		$this->templateService->expects($this->never())->method('getAvailableVariables');
		$this->appConfig->expects($this->never())->method('setValueString');

		$responses = [
			'createTemplate' => $this->controller->createTemplate(caseTypeId: 'ct-1'),
			'prefillDraft' => $this->controller->prefillDraft(caseId: 'case-1', templateId: 'tpl-1'),
			'saveSettings' => $this->controller->saveSettings(),
			'variables' => $this->controller->variables(caseTypeId: 'ct-1'),
		];

		foreach ($responses as $endpoint => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$endpoint . ' must refuse an anonymous caller'
			);
			$this->assertSame(['message' => 'unauthenticated'], $response->getData());
		}
	}//end testAllFourEndpointsRefuseAnAnonymousCallerWith401()

	/**
	 * createTemplate is admin-only, and the admin question is asked about the
	 * SESSION's uid — not a uid taken from the request, which is the realistic
	 * defect on an endpoint that already reads three request params.
	 *
	 * @return void
	 */
	public function testCreateTemplateRefusesANonAdminAndAsksAboutTheSessionUid(): void {
		$this->signIn(uid: 'plain-user');
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('plain-user')
			->willReturn(false);
		$this->templateService->expects($this->never())->method('createTemplate');

		$response = $this->controller->createTemplate(caseTypeId: 'ct-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'forbidden'], $response->getData());
	}//end testCreateTemplateRefusesANonAdminAndAsksAboutTheSessionUid()

	/**
	 * An admin's create reaches the backend with the caseType from the ROUTE and
	 * the name/subject/body from the request body, and answers 200 with the
	 * created template.
	 *
	 * @return void
	 */
	public function testCreateTemplateForwardsTheRouteCaseTypeAndTheBodyFields(): void {
		$this->signIn(uid: 'admin');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams(
			[
				'name' => 'Ontvangstbevestiging',
				'subject' => 'Uw aanvraag',
				'body' => 'Beste {{contactNaam}}',
			]
		);

		$this->templateService->expects($this->once())
			->method('createTemplate')
			->with(
				'ct-42',
				[
					'name' => 'Ontvangstbevestiging',
					'subject' => 'Uw aanvraag',
					'body' => 'Beste {{contactNaam}}',
				]
			)
			->willReturn(['id' => 'tpl-9', 'version' => 1]);

		$response = $this->controller->createTemplate(caseTypeId: 'ct-42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'tpl-9', 'version' => 1], $response->getData());
	}//end testCreateTemplateForwardsTheRouteCaseTypeAndTheBodyFields()

	/**
	 * A rejected create answers 400 carrying the backend's reason — not a 500
	 * and not a silent success.
	 *
	 * @return void
	 */
	public function testCreateTemplateAnswers400WhenTheBackendRejectsThePayload(): void {
		$this->signIn(uid: 'admin');
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->withRequestParams([]);
		$this->templateService->method('createTemplate')
			->willThrowException(new \RuntimeException('Template name is required'));

		$response = $this->controller->createTemplate(caseTypeId: 'ct-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Template name is required'], $response->getData());
	}//end testCreateTemplateAnswers400WhenTheBackendRejectsThePayload()

	/**
	 * prefillDraft renders citizen contact data into the response, so it must
	 * clear the per-case read guard FOR THE CASE ON THE ROUTE first — and must
	 * not render anything when the guard refuses.
	 *
	 * @return void
	 */
	public function testPrefillDraftRefusesWhenTheCaseGuardDeniesTheRouteCase(): void {
		$user = $this->signIn(uid: 'handler');

		$this->caseAccessGuard->expects($this->once())
			->method('hasCaseReadAccess')
			->with('case-77', $user)
			->willReturn(false);
		$this->templateService->expects($this->never())->method('prefillDraft');

		$response = $this->controller->prefillDraft(caseId: 'case-77', templateId: 'tpl-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'forbidden'], $response->getData());
	}//end testPrefillDraftRefusesWhenTheCaseGuardDeniesTheRouteCase()

	/**
	 * A cleared caller gets the rendered draft, with case and template ids
	 * forwarded in that order (transposing them is the realistic defect on a
	 * two-id route).
	 *
	 * @return void
	 */
	public function testPrefillDraftReturnsTheRenderedDraftForAClearedCaller(): void {
		$this->signIn(uid: 'handler');
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->templateService->expects($this->once())
			->method('prefillDraft')
			->with('case-77', 'tpl-1')
			->willReturn(['subject' => 'Uw aanvraag', 'to' => 'burger@example.test']);

		$response = $this->controller->prefillDraft(caseId: 'case-77', templateId: 'tpl-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['subject' => 'Uw aanvraag', 'to' => 'burger@example.test'], $response->getData());
	}//end testPrefillDraftReturnsTheRenderedDraftForAClearedCaller()

	/**
	 * A draft that cannot be rendered answers 409 Conflict — a distinct code
	 * from the 403 above, so a client can tell "not allowed" from "template and
	 * case do not go together".
	 *
	 * @return void
	 */
	public function testPrefillDraftAnswers409WhenTheDraftCannotBeRendered(): void {
		$this->signIn(uid: 'handler');
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);
		$this->templateService->method('prefillDraft')
			->willThrowException(new \RuntimeException('Template not found'));

		$response = $this->controller->prefillDraft(caseId: 'case-77', templateId: 'tpl-x');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'Template not found'], $response->getData());
	}//end testPrefillDraftAnswers409WhenTheDraftCannotBeRendered()

	/**
	 * saveSettings() persists every key the request supplied, and there is no
	 * longer a key among them that holds a credential.
	 *
	 * 🔴 THE PASSWORD IS NOT ACCEPTED, NOT EVEN MASKED. This test used to pin
	 * the `sensitive` flag on `email_imap_password`, and its successor pins the
	 * opposite: Nextcloud Mail holds the account now (design D-2), so a request
	 * still posting a password writes nothing at all. A surface that quietly
	 * accepted the old key would keep putting a credential in appconfig while
	 * the repair step deletes it on every upgrade.
	 *
	 * @return void
	 */
	public function testSaveSettingsWritesTheAccountAndRefusesAPassword(): void {
		$this->signIn(uid: 'admin');
		$this->withRequestParams(
			[
				'email_mail_account_id' => '7',
				'email_imap_folder' => 'INBOX/Zaken',
				'email_imap_password' => 'hunter2',
				'email_imap_host' => 'imap.gemeente.test',
			]
		);

		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(
				static function (
					string $app,
					string $key,
					string $value,
					bool $lazy = false,
					bool $sensitive = false,
				) use (&$written): bool {
					$written[$key] = ['value' => $value, 'sensitive' => $sensitive];
					return true;
				}
			);

		$response = $this->controller->saveSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('7', $written['email_mail_account_id']['value']);
		$this->assertSame('INBOX/Zaken', $written['email_imap_folder']['value']);
		$this->assertArrayNotHasKey(
			'email_imap_password',
			$written,
			'dossiq stores no mailbox password, so the key must not be written'
		);
		$this->assertArrayNotHasKey('email_imap_host', $written, 'nor the host it belonged to');
		$this->assertFalse(
			$written['email_mail_account_id']['sensitive'],
			'an account id is not a secret'
		);
	}//end testSaveSettingsWritesTheAccountAndRefusesAPassword()

	/**
	 * The block list is written through the class that reads it, so it is
	 * stored normalised rather than as the administrator typed it.
	 *
	 * `SenderBlocklist::blocks()` compares entries against a normalised
	 * address. A list written straight to appconfig keeps the capitals and the
	 * spaces, matches nothing, and still shows the address as blocked on the
	 * settings page, which is the worst of the three possible outcomes.
	 *
	 * @return void
	 */
	public function testTheBlockListIsWrittenThroughTheClassThatReadsIt(): void {
		$this->signIn(uid: 'admin');
		$this->withRequestParams(
			['email_intake_blocklist' => 'Spammer@Voorbeeld.NL, @reclame.nl']
		);

		$replaced = [];
		$this->blocklist->expects($this->once())
			->method('replace')
			->willReturnCallback(
				static function (array $entries) use (&$replaced): void {
					$replaced = $entries;
				}
			);

		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$written): bool {
					$written[$key] = $value;
					return true;
				}
			);

		$response = $this->controller->saveSettings();

		$this->assertSame(['saved' => true], $response->getData());
		$this->assertSame(['Spammer@Voorbeeld.NL', ' @reclame.nl'], $replaced);
		$this->assertArrayNotHasKey(
			'email_intake_blocklist',
			$written,
			'the raw value must not also be written straight to appconfig'
		);
	}//end testTheBlockListIsWrittenThroughTheClassThatReadsIt()

	/**
	 * variables answers the backend's catalogue verbatim for the caseType on
	 * the route, unwrapped — the template editor reads the groups straight off
	 * the response root, so an accidental `['results' => ...]` wrapper here
	 * would empty the placeholder picker.
	 *
	 * @return void
	 */
	public function testVariablesReturnsTheCatalogueUnwrappedForTheRouteCaseType(): void {
		$this->signIn(uid: 'handler');
		$catalogue = [
			'case' => ['zaakNummer', 'titel'],
			'contact' => ['contactNaam'],
			'caseType' => ['naam'],
		];

		$this->templateService->expects($this->once())
			->method('getAvailableVariables')
			->with('ct-42')
			->willReturn($catalogue);

		$response = $this->controller->variables(caseTypeId: 'ct-42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($catalogue, $response->getData());
	}//end testVariablesReturnsTheCatalogueUnwrappedForTheRouteCaseType()
}//end class
