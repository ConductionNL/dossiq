<?php

/**
 * The StUF services against the schema fragment the app actually installs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-bidirectional-mapping
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\StufEnvelopeInspector;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\Stuf\StufVaultService;
use OCA\Dossiq\Tests\Unit\Fixtures\SchemaAwareStufRegister;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the StUF services to the shipped schema.
 *
 * Every test here drives a REAL service against
 * {@see SchemaAwareStufRegister}, which reads the shipped fragment and
 * reproduces the two live behaviours a hand-written mock hides: a save drops
 * what the schema does not declare, and a filter on an undeclared property
 * matches zero rows rather than being ignored.
 *
 * @covers \OCA\Dossiq\Service\Stuf\StufEnvelopeInspector
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class StufRegisterContractTest extends TestCase {
	use MakesCaseDateNormaliser;

	private SchemaAwareStufRegister $register;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new SchemaAwareStufRegister();
	}//end setUp()


	/**
	 * An inbound envelope resolves its endpoint from the zender.
	 *
	 * A real zaaksysteem sends no `X-Dossiq-Endpoint-Id` header, so the zender
	 * lookup is the ONLY route it has. A filter that matches nothing makes every
	 * unsolicited inbound message unroutable.
	 *
	 * @return void
	 */
	public function testAnInboundEnvelopeResolvesItsEndpointFromTheZender(): void {
		$this->register->seed(
			StufRegisterAccess::SCHEMA_ENDPOINT,
			['id' => 'ep-1', 'recipientApplication' => 'Key2Zaken']
		);

		$inspector = new StufEnvelopeInspector($this->register, $this->createMock(StufVaultService::class));
		$endpoint = $inspector->resolveEndpoint(
			envelopeXml: '<stuf:zender><stuf:applicatie>Key2Zaken</stuf:applicatie></stuf:zender>'
		);

		$this->assertNotNull($endpoint, 'The zender application must resolve its endpoint.');
		$this->assertSame('ep-1', ($endpoint['id'] ?? null));
	}//end testAnInboundEnvelopeResolvesItsEndpointFromTheZender()

	/**
	 * THE CLASS-CATCHING SWEEP.
	 *
	 * The tests above name the fields that are known to have drifted. This one
	 * names none of them: it collects every property key the services above
	 * handed to a save, and every filter key they handed to a lookup, and holds
	 * the whole collection against the schema the app installs.
	 *
	 * A new field written under a name the schema does not declare fails here
	 * before it fails silently in production, which is the failure mode that
	 * cost this app eight fields across three schemas: the vocabulary pass
	 * deliberately excluded `lib/Service/Stuf/` to protect the StUF wire element
	 * names, renamed the schema JSON and the Vue anyway, and left the register
	 * payload keys behind in Dutch.
	 *
	 * @return void
	 */
	public function testEveryFieldTheStufServicesTouchIsDeclared(): void {
		$this->driveEveryRegisterPath();

		// No StUF service in dossiq saves any more (the outbound audit moved to
		// integriq, decision 193); the lookups the provider side makes remain.
		$this->assertNotSame([], $this->register->filterKeys, 'The sweep must observe at least one lookup.');

		// Collected rather than asserted one at a time, so a run reports EVERY
		// drifted field at once. Failing on the first would have reported
		// `bronEntiteit` and hidden the other seven.
		$undeclared = [];
		foreach (['written' => $this->register->writtenKeys, 'filtered on' => $this->register->filterKeys] as $verb => $observed) {
			foreach ($observed as $schema => $keys) {
				$declared = $this->register->declaredProperties(schema: (string)$schema);
				$this->assertNotSame([], $declared, sprintf('Schema "%s" must declare properties.', $schema));
				foreach (array_unique($keys) as $key) {
					if (in_array($key, $declared, true) === false) {
						$undeclared[] = sprintf('%s.%s (%s)', $schema, $key, $verb);
					}
				}
			}
		}

		$this->assertSame(
			[],
			$undeclared,
			"A StUF service names a property its schema does not declare:\n  "
			. implode("\n  ", $undeclared)
			. "\nA write of an undeclared property reports success and is silently gone; a "
			. 'filter on one is answered with `1 = 0`, so the lookup matches zero rows '
			. 'however many are stored.'
		);
	}//end testEveryFieldTheStufServicesTouchIsDeclared()

	/**
	 * Exercise every register-facing path the StUF services own.
	 *
	 * @return void
	 */
	private function driveEveryRegisterPath(): void {
		$inspector = new StufEnvelopeInspector($this->register, $this->createMock(StufVaultService::class));
		$inspector->resolveEndpoint(
			envelopeXml: '<stuf:zender><stuf:applicatie>Key2Zaken</stuf:applicatie></stuf:zender>'
		);
	}//end driveEveryRegisterPath()

	/**
	 * The status-transition extras and the audit-log filter are declared too.
	 *
	 * These two reach the register through a literal the sweep above cannot
	 * observe: `transitionStatus()` merges whatever its caller hands it, and
	 * `StufController::messageFilters()` builds its filter map from a name list
	 * before any service sees it. Both wrote Dutch keys — `duurMs` for the
	 * round-trip duration on the failure and confirmation paths, `berichtSoort`
	 * for the message-kind filter — so a failed StUF call recorded no duration
	 * and the admin log's kind filter matched nothing at all.
	 *
	 * Read out of the source rather than driven, because reaching them means
	 * standing up the whole transport; the names are literals, so reading them
	 * is exact. The scan walks the WHOLE StUF service directory rather than the
	 * files known to have drifted: a first pass named two files by hand and
	 * missed three more `duurMs` writes sitting in StufAdapterService.
	 *
	 * @return void
	 */
	public function testTheTransitionExtrasAndAuditFiltersAreDeclared(): void {
		$declared = $this->register->declaredProperties(schema: StufRegisterAccess::SCHEMA_MESSAGE);
		$this->assertNotSame([], $declared, 'The stufMessage schema must declare properties.');

		$lib = __DIR__ . '/../../../../lib';
		$sources = glob($lib . '/Service/Stuf/*.php');
		$this->assertNotEmpty($sources, 'The StUF service directory must hold sources to scan.');
		$sources[] = $lib . '/Controller/StufController.php';

		$found = [];
		foreach ($sources as $file) {
			$source = (string)file_get_contents($file);

			// `extras: [ … ]` and `$extras = [ … ]` literals. The opening bracket
			// is found by regex and the CLOSING one by counting, because these
			// arrays contain subscripts: a non-greedy `\[(.*?)\]` stops at the
			// `]` of `$response['fout']` and silently reads half the array. That
			// is not hypothetical — it is how the first version of this test
			// passed while `duurMs` sat two keys further along the same line.
			foreach ($this->arrayLiteralsAfter(source: $source, opener: '/(?:extras:\s*|\$extras\s*=\s*)\[/') as $block) {
				preg_match_all("/'([A-Za-z][A-Za-z0-9_]*)'\s*=>/", $block, $keys);
				$found = array_merge($found, ($keys[1] ?? []));
			}

			// `$extras['key'] = …` additions.
			preg_match_all("/\\\$extras\\['([A-Za-z][A-Za-z0-9_]*)'\\]\s*=/", $source, $extra);
			$found = array_merge($found, ($extra[1] ?? []));

			// The audit-log filter name list.
			if (preg_match('/function messageFilters\(\): array \{.*?foreach \(\[(.*?)\]/s', $source, $filters) === 1) {
				preg_match_all("/'([A-Za-z][A-Za-z0-9_]*)'/", $filters[1], $names);
				$found = array_merge($found, ($names[1] ?? []));
			}
		}

		$found = array_values(array_unique($found));
		$this->assertNotSame([], $found, 'The scan must find at least one literal field name.');
		$this->assertContains('status', $found, 'The scan must reach the audit-log filter list.');

		$undeclared = array_values(array_diff($found, $declared));
		$this->assertSame(
			[],
			$undeclared,
			'A status transition or audit-log filter names a stufMessage property the schema '
			. 'does not declare: ' . implode(', ', $undeclared)
		);
	}//end testTheTransitionExtrasAndAuditFiltersAreDeclared()

	/**
	 * Extract every bracket-balanced array literal opened by a pattern.
	 *
	 * @param string $source The PHP source to scan.
	 * @param string $opener A regex whose match ENDS at the opening bracket.
	 *
	 * @return list<string> The literal bodies, brackets excluded.
	 */
	private function arrayLiteralsAfter(string $source, string $opener): array {
		if (preg_match_all($opener, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
			return [];
		}

		$blocks = [];
		foreach ($matches[0] as $match) {
			$start = ($match[1] + strlen($match[0]));
			$depth = 1;
			$cursor = $start;
			$length = strlen($source);
			while ($cursor < $length && $depth > 0) {
				if ($source[$cursor] === '[') {
					$depth++;
				}

				if ($source[$cursor] === ']') {
					$depth--;
				}

				$cursor++;
			}

			$blocks[] = substr($source, $start, ($cursor - $start - 1));
		}

		return $blocks;
	}//end arrayLiteralsAfter()
}//end class
