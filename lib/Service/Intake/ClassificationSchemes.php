<?php

/**
 * Dossiq classification schemes.
 *
 * The readable list of classification schemes this instance knows, and the
 * values each of them allows. A case type names a scheme; this says whether
 * the instance can resolve it.
 *
 * WHY THIS EXISTS RATHER THAN A FREE-TEXT CLASSIFICATION. Where a case type
 * marks its classification as an access rule, the classification is what
 * decides who can reach the case. A scheme nobody can resolve therefore cannot
 * be turned into an access rule, and a case created under it is reachable by
 * nobody or by everybody, depending on which way the evaluation happens to
 * fall. ADR-102 says config absence fails closed with a status, so an
 * unresolvable scheme refuses the creation and names the scheme.
 *
 * Administered the way the junk rules are: one app-config key, readable and
 * changeable, with a shipped default so a fresh instance resolves the scheme
 * the VNG already standardised.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Throwable;

/**
 * Which classification schemes resolve on this instance, and to what.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */
class ClassificationSchemes {

	/**
	 * The app-config key holding the administered schemes.
	 */
	public const SCHEMES_KEY = 'case_classification_schemes';

	/**
	 * The scheme every instance resolves without being configured.
	 *
	 * The VNG vertrouwelijkheidaanduiding, the same eight levels the `case`
	 * and `caseType` schemas already carry on `confidentiality`. Shipping it
	 * means a case type can mark its classification an access rule on a fresh
	 * instance without an administrator first writing a list out by hand.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const SHIPPED = [
		'vertrouwelijkheidaanduiding' => [
			'openbaar',
			'beperkt_openbaar',
			'intern',
			'zaakvertrouwelijk',
			'vertrouwelijk',
			'confidentieel',
			'geheim',
			'zeer_geheim',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The administered schemes.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every scheme this instance knows, shipped and administered.
	 *
	 * An administered scheme under the same name replaces the shipped one, so
	 * a gemeente that classifies differently is not stuck with the default.
	 *
	 * @return array<string, array<int, string>> Scheme name to allowed values.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function schemes(): array {
		return array_merge(self::SHIPPED, $this->administered());
	}//end schemes()

	/**
	 * Whether this instance can resolve a named scheme.
	 *
	 * An unnamed scheme resolves. A case type that names no scheme has not
	 * asked for one, which is a different fact from naming one nobody knows.
	 *
	 * @param string $scheme The scheme the case type names.
	 *
	 * @return boolean True when the scheme is known or unnamed.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function resolves(string $scheme): bool {
		$name = trim($scheme);
		if ($name === '') {
			return true;
		}

		return array_key_exists($name, $this->schemes());
	}//end resolves()

	/**
	 * The values one scheme allows.
	 *
	 * @param string $scheme The scheme.
	 *
	 * @return array<int, string> The allowed values, empty when unknown.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function valuesOf(string $scheme): array {
		$schemes = $this->schemes();
		$name = trim($scheme);
		if (array_key_exists($name, $schemes) === false) {
			return [];
		}

		return $schemes[$name];
	}//end valuesOf()

	/**
	 * Whether one value belongs to a scheme.
	 *
	 * A scheme that resolves to no values accepts anything, because an empty
	 * list is an administrator saying "these are free text", not an empty
	 * vocabulary that refuses everything.
	 *
	 * @param string $scheme The scheme.
	 * @param string $value  The value on the case.
	 *
	 * @return boolean True when the value is allowed.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function allows(string $scheme, string $value): bool {
		$values = $this->valuesOf(scheme: $scheme);
		if ($values === []) {
			return true;
		}

		return in_array(trim($value), $values, true);
	}//end allows()

	/**
	 * The schemes an administrator wrote, read defensively.
	 *
	 * Unreadable configuration answers an empty list rather than throwing. The
	 * shipped scheme then still resolves, and a case type naming a scheme that
	 * only lived in the broken configuration refuses creation, which is the
	 * fail-closed direction.
	 *
	 * @return array<string, array<int, string>> Scheme name to allowed values.
	 */
	private function administered(): array {
		try {
			$raw = $this->appConfig->getValueString(Application::APP_ID, self::SCHEMES_KEY, '');
		} catch (Throwable $e) {
			return [];
		}

		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$schemes = [];
		foreach ($decoded as $name => $values) {
			if (is_string($name) === false || trim($name) === '') {
				continue;
			}

			$schemes[trim($name)] = $this->valueList(value: $values);
		}

		return $schemes;
	}//end administered()

	/**
	 * One scheme's values, cleaned.
	 *
	 * @param mixed $value The administered value.
	 *
	 * @return array<int, string> The allowed values.
	 */
	private function valueList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$values = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false) {
				continue;
			}

			$name = trim($entry);
			if ($name === '' || in_array($name, $values, true) === true) {
				continue;
			}

			$values[] = $name;
		}

		return $values;
	}//end valueList()
}//end class
