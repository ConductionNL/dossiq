<?php

/**
 * The citizen manifest offers an answer on the questions the organisation asks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-answers-a-question-from-mijn-zaken-and-the-answer-lands-on-the-open-request-req-wds-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\CitizenManifest;
use OCA\Dossiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * REQ-WDS-001 and REQ-WDS-003 through the contribution portaliq reads.
 *
 * @covers \OCA\Dossiq\Portal\CitizenManifest
 *
 * @uses \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses \OCA\Dossiq\Portal\PortalPages
 * @uses \OCA\Dossiq\Portal\PortalConversation
 */
class CitizenManifestTest extends TestCase {

	/**
	 * One item of a list by its id.
	 *
	 * @param array<int, array<string, mixed>> $rows The list.
	 * @param string                           $id   The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function byId(array $rows, string $id): ?array {
		foreach ($rows as $row) {
			if (($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		return null;
	}//end byId()

	/**
	 * `vragenAanU` offers `beantwoordVraag` on an open row, forwarding the answer to a route that exists.
	 *
	 * @return void
	 */
	public function testTheQuestionsCollectionOffersAnAnswer(): void {
		foreach (['citizen', 'client'] as $audience) {
			$contribution = (new PortalContributionProvider())->getContribution(['audience' => $audience]);
			$this->assertNotNull($contribution, $audience);

			$questions = $this->byId(rows: $contribution['collections'], id: 'vragenAanU');
			$this->assertNotNull($questions, $audience);
			$this->assertSame([CitizenManifest::ANSWER_ACTION], $questions['rowActions'], $audience);

			$action = $this->byId(rows: $contribution['actions'], id: CitizenManifest::ANSWER_ACTION);
			$this->assertNotNull($action, $audience . ' gets the answer action');
			$this->assertSame('requestId', $action['rowField']);
			$this->assertSame(['field' => 'state', 'in' => ['open']], $action['rowWhen']);
			$this->assertSame(['antwoord'], $action['fields']);
			$this->assertSame(['antwoord'], $action['requiredFields']);
			$this->assertSame('POST', $action['method']);
			$this->assertSame(4000, $action['fieldConfigs']['antwoord']['maxLength']);
		}

		$routes = include __DIR__ . '/../../../appinfo/routes.php';
		$answerRoute = array_values(array_filter(
			$routes['routes'],
			static fn (array $route): bool => ($route['name'] ?? '') === 'portalWooAnswer#answer'
		));
		$this->assertCount(1, $answerRoute);
		$this->assertSame('POST', $answerRoute[0]['verb']);
		$this->assertSame('/index.php/apps/dossiq' . $answerRoute[0]['url'], $action['endpoint']);
	}//end testTheQuestionsCollectionOffersAnAnswer()

	/**
	 * Mijn zaken carries the term note and the result link the case declares.
	 *
	 * @return void
	 */
	public function testTheCaseCollectionCarriesTheTermNoteAndTheResultLink(): void {
		$contribution = (new PortalContributionProvider())->getContribution(['audience' => 'citizen']);
		$cases = $this->byId(rows: $contribution['collections'], id: 'mijnZaken');

		$this->assertContains('termNote', $cases['fields']);
		$this->assertContains('resultLink', $cases['fields']);
	}//end testTheCaseCollectionCarriesTheTermNoteAndTheResultLink()
}//end class
