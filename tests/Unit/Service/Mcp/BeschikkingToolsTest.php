<?php

/**
 * Unit tests for the curated beschikking draft tool.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mcp;

use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Mcp\BeschikkingTools;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The agent drafts; it never approves, signs or sends (design D3).
 */
class BeschikkingToolsTest extends TestCase {

	private IUserSession&MockObject $session;

	private CaseAccessGuard&MockObject $guard;

	private BeschikkingService&MockObject $decisions;

	private BeschikkingTools $tools;

	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->decisions = $this->createMock(BeschikkingService::class);
		$this->tools = new BeschikkingTools(
			userSession: $this->session,
			caseAccess: $this->guard,
			decisions: $this->decisions,
		);
	}//end setUp()

	/**
	 * A signed-in caller.
	 *
	 * @return IUser
	 */
	private function signIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('henk');
		$this->session->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	public function testDraftingOnACaseTheCallerMayNotChangeIsRefused(): void {
		$user = $this->signIn();
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('c-1', $user)->willReturn(false);
		$this->decisions->expects($this->never())->method('compose');

		$this->assertSame('forbidden', $this->tools->draftBeschikking(caseId: 'c-1')['error']);
	}//end testDraftingOnACaseTheCallerMayNotChangeIsRefused()

	public function testADraftIsComposedWithTheGivenFieldsOnly(): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->decisions->expects($this->once())->method('compose')
			->with('c-1', 'tpl-toewijzing', ['decisionType' => 'toewijzing', 'rationale' => 'Voldoet aan de eisen.'])
			->willReturn(['id' => 'b-1', 'status' => 'concept']);

		$result = $this->tools->draftBeschikking(caseId: 'c-1', templateId: 'tpl-toewijzing', decisionType: 'toewijzing', rationale: 'Voldoet aan de eisen.');

		$this->assertSame('concept', $result['status']);
	}//end testADraftIsComposedWithTheGivenFieldsOnly()

	public function testNoTemplateLetsTheServiceChoose(): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->decisions->expects($this->once())->method('compose')->with('c-1', null, [])->willReturn(['id' => 'b-1']);

		$this->tools->draftBeschikking(caseId: 'c-1');
	}//end testNoTemplateLetsTheServiceChoose()

	public function testAComposeRefusalIsAnEnvelope(): void {
		$this->signIn();
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->decisions->method('compose')->willThrowException(new RuntimeException('not_found'));

		$this->assertSame(['error' => 'refused', 'message' => 'not_found'], $this->tools->draftBeschikking(caseId: 'c-1'));
	}//end testAComposeRefusalIsAnEnvelope()

	public function testTheToolClassOffersNoApproveSignOrSend(): void {
		$methods = array_map(static fn ($m) => strtolower($m->getName()), (new ReflectionClass(BeschikkingTools::class))->getMethods());

		foreach (['akkoord', 'onderteken', 'verzend', 'approve', 'sign', 'send'] as $forbidden) {
			foreach ($methods as $method) {
				$this->assertStringNotContainsString($forbidden, $method);
			}
		}
	}//end testTheToolClassOffersNoApproveSignOrSend()
}//end class
