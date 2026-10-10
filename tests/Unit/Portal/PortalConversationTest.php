<?php

/**
 * The resident's conversation with the handler, as dossiq declares it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each declaration is asserted against the rule portaliq keeps it by
 * (portaliq development: InboxReplyConfigNormaliser, ActionOptionsNormaliser,
 * FileFieldConfigNormaliser), because portaliq drops a malformed one in
 * silence and the page then simply lacks the control.
 *
 * @covers \OCA\Dossiq\Portal\PortalConversation
 * @covers \OCA\Dossiq\Portal\CitizenManifest
 * @uses   \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses   \OCA\Dossiq\Portal\PortalPages
 */
class PortalConversationTest extends TestCase {

	/**
	 * The citizen contribution.
	 *
	 * @return array<string, mixed>
	 */
	private function contribution(): array {
		$contribution = (new PortalContributionProvider())->getContribution(['audience' => 'client']);
		$this->assertIsArray($contribution);

		return $contribution;
	}//end contribution()

	/**
	 * One citizen collection by id.
	 *
	 * @param string $id The collection id.
	 *
	 * @return array<string, mixed>
	 */
	private function collection(string $id): array {
		return array_column($this->contribution()['collections'], null, 'id')[$id];
	}//end collection()

	/**
	 * One citizen action by id.
	 *
	 * @param string $id The action id.
	 *
	 * @return array<string, mixed>
	 */
	private function action(string $id): array {
		$actions = array_column($this->contribution()['actions'], null, 'id');
		$this->assertArrayHasKey($id, $actions, "the citizen contribution declares {$id}");

		return $actions[$id];
	}//end action()

	/**
	 * The inbox declares its reply, carrying the case from the message, in the
	 * shape portaliq keeps: the action is a create, and the carried field is
	 * whitelisted by the action and projected by the inbox.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheInboxReplyCarriesTheCaseFromTheMessage(): void {
		$berichten = $this->collection('berichten');

		$this->assertSame(
			['action' => 'replyToMessage', 'carry' => ['caseId' => 'caseId'], 'subjectFrom' => 'subject'],
			$berichten['reply']
		);

		$reply = $this->action('replyToMessage');
		$this->assertSame('create', $reply['type']);
		foreach ($berichten['reply']['carry'] as $replyField => $messageField) {
			$this->assertContains($replyField, $reply['fields'], 'the reply whitelists the carried field');
			$this->assertContains($messageField, $berichten['fields'], 'the inbox projects the carried field');
		}

		$this->assertContains($berichten['reply']['subjectFrom'], $berichten['fields']);
	}//end testTheInboxReplyCarriesTheCaseFromTheMessage()

	/**
	 * The reply form offers the resident's own cases by title, never a uuid box.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheReplyFormOffersTheResidentsOwnCases(): void {
		$provider = $this->action('replyToMessage')['optionsProviders']['caseId'];

		$this->assertSame(
			['type' => 'collection', 'register' => 'dossiq', 'schema' => 'case', 'labelField' => 'title', 'valueField' => 'id'],
			$provider
		);

		// portaliq drops a collection provider unless all four keys are non-empty strings.
		foreach (['register', 'schema', 'labelField', 'valueField'] as $key) {
			$this->assertIsString($provider[$key]);
			$this->assertNotSame('', $provider[$key]);
		}

		// The label a resident picks by is a field the case list projects.
		$this->assertContains('title', $this->collection('mijnZaken')['fields']);
		// And the guard still checks whatever arrives.
		$this->assertTrue($this->action('replyToMessage')['crossRefs']['caseId']['required']);
	}//end testTheReplyFormOffersTheResidentsOwnCases()

	/**
	 * The inbox serves the message's files, and the reply form takes files as attachments.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testAttachmentsAreFilesBothWays(): void {
		$this->assertTrue($this->collection('berichten')['filesDownload']);

		foreach (['replyToMessage', 'askAboutCase'] as $id) {
			$config = $this->action($id)['fieldConfigs']['attachments'];
			$this->assertSame('file', $config['type'], $id);
			$this->assertTrue($config['multiple'], $id);
			$this->assertSame(20, $config['maxSizeMb'], $id);
			$this->assertContains('.pdf', $config['accept'], $id);
			foreach ($config['accept'] as $accept) {
				$this->assertMatchesRegularExpression('/^\.[a-z0-9]{1,16}$/', $accept, "{$id}: portaliq keeps only a well-formed extension");
			}
		}
	}//end testAttachmentsAreFilesBothWays()

	/**
	 * A resident asks about the case on screen: the case comes from the page,
	 * is hidden on the form and is guarded against their own cases; the write
	 * is a message to the handler, stamped by the server.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testAResidentAsksFromTheCase(): void {
		$ask = $this->action('askAboutCase');

		$this->assertSame('create', $ask['type']);
		$this->assertSame('portaalBericht', $ask['schema']);
		$this->assertSame('senderRef', $ask['scopeField']);
		$this->assertSame('caseId', $ask['recordField']);
		$this->assertFalse($ask['fieldConfigs']['caseId']['visible']);
		$this->assertSame(
			['register' => 'dossiq', 'schema' => 'case', 'scopeField' => 'portalSubject', 'required' => true],
			$ask['crossRefs']['caseId']
		);
		$this->assertSame(['direction' => 'citizen_to_handler', 'senderType' => 'burger'], $ask['defaults']);
		$this->assertNotContains('direction', $ask['fields']);
		$this->assertNotContains('recipientRef', $ask['fields']);
	}//end testAResidentAsksFromTheCase()

	/**
	 * The case detail lists the messages about the case through a provider
	 * the contribution actually answers, and offers the question.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheCaseDetailListsItsMessagesAndOffersTheQuestion(): void {
		$cases = $this->collection('mijnZaken');

		$this->assertSame(['label' => 'Berichten', 'provider' => 'caseMessages'], $cases['messages']);
		$this->assertTrue(method_exists(PortalContributionProvider::class, $cases['messages']['provider']));
		$this->assertSame(['askAboutCase'], $cases['detail']['actions']);
	}//end testTheCaseDetailListsItsMessagesAndOffersTheQuestion()

	/**
	 * The inbox page still offers the reply form, not the question: the page
	 * takes the first create on its schema, and a question needs a case on screen.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheInboxPageKeepsTheReplyForm(): void {
		$pages = array_column($this->contribution()['pages'], null, 'id');
		$actions = [];
		foreach ($pages['berichten']['blocks'] as $block) {
			if (($block['type'] ?? '') === 'action') {
				$actions[] = $block['action'];
			}
		}

		$this->assertSame(['replyToMessage'], $actions);
	}//end testTheInboxPageKeepsTheReplyForm()
}//end class
