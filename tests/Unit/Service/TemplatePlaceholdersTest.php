<?php

/**
 * Every placeholder a shipped template names is one the map can answer.
 *
 * 🔴 WHAT THIS IS PROTECTING, AND WHY NOTHING CAUGHT IT. On 2026-06-11 the
 * three shipped templates and the variable map that answered them were both in
 * Dutch: `{{startDatum}}`, `{{einddatum}}`, `{{behandelaar}}`. On 2026-08-14
 * the English rename moved the MAP's keys and did not touch the template
 * bodies, because a body is a string and the rename saw nothing in it. Every
 * mail sent from a shipped template since has carried a raw `{{behandelaar}}`
 * where the handler's name belongs.
 *
 * It could not have been caught by anything then in place.
 * `EmailTemplateService::resolve()` leaves an unanswered placeholder exactly as
 * it found it — correctly, because leaking `{{behandelaar}}` is better than
 * silently blanking the line and nobody reporting it for a month. So the
 * template renders, the mail sends, the send reports success, and the defect is
 * visible only to the recipient.
 *
 * 🔴 BOTH SIDES ARE DERIVED, NEITHER IS LISTED. A list of expected placeholders
 * written here would be a third copy of the same knowledge, written in June and
 * renamed in August exactly as the map was. The placeholders come from reading
 * the templates and the answerable keys from calling the map.
 *
 * 🔴 AND IT ASKS THE RENDERER'S OWN QUESTION. `collectUnresolved()` has existed
 * since the templates did and returns exactly these three names; nothing ever
 * asked it about a shipped template. This calls THAT rather than
 * re-implementing the match, so the gate cannot drift from the thing it guards.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/template-placeholders-answer/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Email\EmailTemplateRepository;
use OCA\Dossiq\Service\EmailTemplateService;
use OCA\Dossiq\Service\IntakeConfirmation;
use OCA\Dossiq\Service\IntegrationStatusService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * The two sets, compared.
 *
 * @covers \OCA\Dossiq\Service\EmailTemplateService
 * @uses \OCA\Dossiq\Service\Email\EmailTemplateRepository
 * @uses \OCA\Dossiq\Service\IntakeConfirmation
 * @uses \OCA\Dossiq\Service\IntegrationStatusService
 */
class TemplatePlaceholdersTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The service, wired with doubles for everything it does not use here.
	 *
	 * @return EmailTemplateService The service under test.
	 */
	private function service(): EmailTemplateService {
		return new EmailTemplateService(
			$this->getMockBuilder(EmailTemplateRepository::class)
				->disableOriginalConstructor()
				->getMock(),
			new NullLogger(),
			$this->getMockBuilder(IntegrationStatusService::class)
				->disableOriginalConstructor()
				->getMock(),
			$this->getMockBuilder(IntakeConfirmation::class)
				->disableOriginalConstructor()
				->getMock(),
		);
	}//end service()

	/**
	 * Every key the map answers for one case.
	 *
	 * Called rather than listed: a list here would drift the way the templates
	 * did.
	 *
	 * @return array<string, string> The variable map.
	 */
	private function answerableKeys(): array {
		$service = $this->service();
		$method = (new ReflectionClass($service))->getMethod('buildVariableMap');
		$method->setAccessible(true);

		return $method->invoke(
			$service,
			[
				'identifier' => '2026-0042',
				'title' => 'Dakkapel',
				'startDate' => '2026-09-01',
				'endDate' => '2026-10-01',
				'deadline' => '2026-11-01',
				'status' => 'ontvangen',
				'assignee' => 'a.handler',
				'contactName' => 'J. Jansen',
			]
		);
	}//end answerableKeys()

	/**
	 * Every template dossiq ships, keyed by where it comes from.
	 *
	 * @return array<string, string> Source name to subject plus body.
	 */
	private function shippedTemplates(): array {
		$templates = [];

		$defaults = (new ReflectionClass(EmailTemplateService::class))
			->getConstant('DEFAULT_TEMPLATES');
		foreach (($defaults ?: []) as $row) {
			$templates['DEFAULT_TEMPLATES::' . $row['slug']] =
				$row['subject'] . ' ' . $row['body'];
		}

		foreach (glob($this->root() . '/lib/Settings/register.d/*.json') as $path) {
			$decoded = json_decode((string)file_get_contents($path), true);
			if (is_array($decoded) === false) {
				continue;
			}

			foreach (($decoded['components']['objects'] ?? []) as $object) {
				if (is_array($object) === false || isset($object['body']) === false) {
					continue;
				}

				$templates[basename($path) . '::' . (string)($object['name'] ?? '?')] =
					(string)($object['subject'] ?? '') . ' ' . (string)$object['body'];
			}
		}

		return $templates;
	}//end shippedTemplates()

	/**
	 * Both sets are non-empty, so nothing below is a gate over nothing.
	 *
	 * @return void
	 */
	public function testBothSetsWereActuallyRead(): void {
		// A gate that read zero templates reports exactly the same green as one
		// that read them all.
		$this->assertGreaterThanOrEqual(
			6,
			count($this->shippedTemplates()),
			'the shipped templates must be found, or the comparison below checks nothing'
		);
		$this->assertGreaterThanOrEqual(10, count($this->answerableKeys()));
	}//end testBothSetsWereActuallyRead()

	/**
	 * No shipped template names a key the map cannot answer.
	 *
	 * @return void
	 */
	public function testEveryShippedPlaceholderIsAnswerable(): void {
		$service = $this->service();

		// 🔴 THE ALIASES ARE SUBTRACTED, and that is the difference between a
		// gate and a decoration. The map answers `{{behandelaar}}` so that
		// templates STORED before the rename keep working; if the gate also
		// accepted it, a shipped body could carry the deprecated name and this
		// test would pass — which is to say it would have waved the original
		// August defect straight through. Measured: with the aliases counted as
		// answerable, reintroducing `{{behandelaar}}` into a shipped body does
		// not redden anything.
		$keys = array_diff_key(
			$this->answerableKeys(),
			EmailTemplateService::DEPRECATED_ALIASES
		);

		$broken = [];
		foreach ($this->shippedTemplates() as $where => $text) {
			foreach ($service->collectUnresolved($text, $keys) as $name) {
				$broken[] = sprintf('%s names {{%s}}', $where, $name);
			}
		}

		sort($broken);
		$this->assertSame(
			[],
			$broken,
			"A placeholder the map cannot answer is LEFT IN THE TEXT by resolve(), so the mail "
			. "sends, reports success, and reaches the recipient with template syntax in it. "
			. "Either add the key to buildVariableMap() or use one it already answers:\n  "
			. implode("\n  ", $broken)
		);
	}//end testEveryShippedPlaceholderIsAnswerable()

	/**
	 * The gate is shown able to report an unanswerable name.
	 *
	 * @return void
	 */
	public function testTheGateCanFail(): void {
		// Without this, "no broken placeholders" could mean the comparison
		// never finds any, which is the same green as a clean tree.
		$this->assertSame(
			['nobodyAnswersThis'],
			$this->service()->collectUnresolved(
				'Beste {{contactNaam}}, {{nobodyAnswersThis}}.',
				$this->answerableKeys()
			)
		);
	}//end testTheGateCanFail()

	/**
	 * The map answers the names the templates carried before the rename.
	 *
	 * @return void
	 */
	public function testTheMapAnswersThePreRenameNames(): void {
		$keys = $this->answerableKeys();

		foreach (['startDatum' => 'startDate', 'einddatum' => 'endDate', 'behandelaar' => 'handler'] as $old => $new) {
			$this->assertArrayHasKey(
				$old,
				$keys,
				sprintf(
					'"%s" is in template bodies administrators have been editing since June; '
					. 'without the alias every one of those stays broken',
					$old
				)
			);
			$this->assertSame($keys[$new], $keys[$old], sprintf('%s must answer as %s does', $old, $new));
		}

		// And a stored body renders with nothing left over.
		$rendered = $this->service()->resolve(
			'Met vriendelijke groet,\n{{behandelaar}}',
			$keys
		);
		$this->assertStringContainsString('a.handler', $rendered);
		$this->assertStringNotContainsString('{{', $rendered);
	}//end testTheMapAnswersThePreRenameNames()

	/**
	 * The editor is offered the canonical names only.
	 *
	 * @return void
	 */
	public function testTheCatalogueOffersTheCanonicalNamesOnly(): void {
		$offered = [];
		foreach ($this->service()->getAvailableVariables('any') as $group) {
			$offered = array_merge($offered, $group);
		}

		$this->assertContains('handler', $offered);
		// The aliases are a door out of the past, not a second vocabulary. A
		// template authored tomorrow must not grow a fourth spelling.
		foreach (['behandelaar', 'startDatum', 'einddatum'] as $alias) {
			$this->assertNotContains(
				$alias,
				$offered,
				sprintf('"%s" is answered for old templates but must not be offered for new ones', $alias)
			);
		}
	}//end testTheCatalogueOffersTheCanonicalNamesOnly()

	/**
	 * Every name the catalogue offers is one the map answers.
	 *
	 * The other direction of the same contract: a catalogue entry nothing
	 * answers is a placeholder the editor invites an administrator to write.
	 *
	 * @return void
	 */
	public function testEveryOfferedNameIsAnswerable(): void {
		$keys = $this->answerableKeys();

		$unanswerable = [];
		foreach ($this->service()->getAvailableVariables('any') as $group => $names) {
			foreach ($names as $name) {
				if (array_key_exists($name, $keys) === false) {
					$unanswerable[] = $group . '.' . $name;
				}
			}
		}

		$this->assertSame(
			[],
			$unanswerable,
			'the editor must not offer a placeholder the map cannot fill: ' . implode(', ', $unanswerable)
		);
	}//end testEveryOfferedNameIsAnswerable()
}//end class
