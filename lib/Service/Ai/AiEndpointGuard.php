<?php

/**
 * Dossiq AI endpoint guard.
 *
 * The SSRF check applied to the configured AI model URL before any outbound
 * request is made: https plus a public address for cloud models, http/https to
 * an address inside the instance's own network for local ones.
 *
 * It answers with the ADDRESS to connect to rather than a boolean, so the check
 * and the connection cannot disagree about which host they meant.
 *
 * Split out of {@see \OCA\Dossiq\Service\AiService}: this is a self-contained
 * security decision with its own CIDR lists and its own IPv4/IPv6 range
 * arithmetic, and it belongs next to those lists rather than inside a class
 * that also builds prompts and writes audit entries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Ai
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
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

use OCA\Dossiq\Support\SuppressesWarnings;
use Psr\Log\LoggerInterface;

/**
 * Validates that a configured AI model URL is safe to connect to.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/ai-assistance/spec.md
 */
class AiEndpointGuard {

	use SuppressesWarnings;

	/**
	 * RFC1918 + loopback + link-local CIDR blocks a CLOUD endpoint may not
	 * resolve into: a cloud model is by definition somewhere else, so an address
	 * inside the instance's own network means the URL is being used to reach
	 * back in.
	 *
	 * @var string[]
	 */
	private const BLOCKED_CIDRS = [
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'::1/128',
		'fc00::/7',
		'fe80::/10',
	];

	/**
	 * The whole of what "local" means: an address a LOCAL model endpoint is
	 * permitted to resolve to.
	 *
	 * An ALLOW-list, not a deny-list, and that is the entire point of it. This
	 * check used to be a deny-list of exactly one block (169.254.0.0/16), so
	 * every host on the internet passed as "local": `http://evil.example.com`
	 * configured as a local model was accepted, and an administrator reading
	 * "Local (Ollama)" on the settings page was told the case identifiers stayed
	 * on the instance while they were being posted to a third party. A deny-list
	 * cannot make that promise no matter how long it gets, because the promise
	 * is about what IS allowed.
	 *
	 * Loopback alone would be too narrow to be usable and would push people
	 * straight back to cloud mode: a containerised Nextcloud reaches its Ollama
	 * sidecar by service name over the compose bridge network, which lands in
	 * 172.16.0.0/12 or 10.0.0.0/8. So "local" means loopback or the private
	 * address space the instance itself sits in — routable only from inside it —
	 * and nothing else.
	 *
	 * 169.254.0.0/16 and fe80::/10 are deliberately ABSENT. Link-local is where
	 * every cloud provider parks its instance metadata service (169.254.169.254),
	 * which hands out credentials to anything that asks. It is not on this list,
	 * so it is refused.
	 *
	 * @var string[]
	 */
	private const LOCAL_CIDRS = [
		'127.0.0.0/8',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'::1/128',
		'fc00::/7',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger interface.
	 *
	 * @return void
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the configured AI model URL to the ONE address it may be connected
	 * to, or null when the URL is refused (SSRF guard).
	 *
	 * Returns an address rather than a yes/no ON PURPOSE. A guard that answers
	 * `true` and leaves the caller to connect by NAME checks one thing and
	 * permits another: between the check and the request the name can be
	 * re-resolved, and the second answer is under the control of whoever owns
	 * the DNS record. That is DNS rebinding, and it is the standard way past
	 * exactly this kind of check — a hostname that answers 127.0.0.1 while it is
	 * being validated and the metadata service a millisecond later. The caller
	 * pins this address with `CURLOPT_RESOLVE`, so the connection goes to the
	 * address that was actually inspected while the Host header and TLS SNI
	 * still carry the original name.
	 *
	 * Fails CLOSED everywhere: a host that cannot be resolved is refused rather
	 * than passed through, because an unverifiable address cannot be shown to be
	 * safe.
	 *
	 * @param string $url The base AI model URL.
	 * @param string $modelType The model type ('local' or 'cloud').
	 *
	 * @return string|null The single IP address to connect to, or null when refused.
	 *
	 * @spec openspec/specs/ai-assistance/spec.md
	 */
	public function resolveSafeAddress(string $url, string $modelType): ?string {
		$parsed = parse_url($url);
		$scheme = strtolower($parsed['scheme'] ?? '');
		// An IPv6 literal arrives bracketed from parse_url(); inet_pton() and
		// the CIDR arithmetic below both want it bare.
		$host = strtolower(trim(($parsed['host'] ?? ''), '[]'));

		if ($host === '' || $this->isAllowedScheme(scheme: $scheme, modelType: $modelType) === false) {
			$this->logger->warning(
				'AI SSRF: model URL rejected on scheme or host',
				['scheme' => $scheme, 'host' => $host, 'modelType' => $modelType]
			);
			return null;
		}//end if

		$addresses = $this->resolveHost(host: $host);
		if (count($addresses) === 0) {
			$this->logger->warning(
				'AI SSRF: host could not be resolved, refusing',
				['host' => $host, 'detail' => $this->lastSuppressedWarning()]
			);
			return null;
		}//end if

		// EVERY address the name answers with has to pass, not merely the first.
		// A name that resolves to a permitted address and a forbidden one is a
		// name whose next answer cannot be predicted.
		foreach ($addresses as $address) {
			if ($this->isAllowedAddress(ipAddress: $address, modelType: $modelType) === false) {
				$this->logger->warning(
					'AI SSRF: model URL resolves to an address this model type may not reach',
					['host' => $host, 'ip' => $address, 'modelType' => $modelType]
				);
				return null;
			}//end if
		}

		return $addresses[0];
	}//end resolveSafeAddress()

	/**
	 * Whether a URL scheme is permitted for this model type.
	 *
	 * @param string $scheme The lower-cased URL scheme.
	 * @param string $modelType The model type ('local' or 'cloud').
	 *
	 * @return bool True when the scheme is permitted.
	 */
	private function isAllowedScheme(string $scheme, string $modelType): bool {
		if ($modelType === 'local') {
			// Plain http is accepted only because the address allow-list has
			// already confined the connection to the instance's own network.
			return in_array($scheme, ['http', 'https'], true);
		}//end if

		return $scheme === 'https';
	}//end isAllowedScheme()

	/**
	 * Whether one resolved address is permitted for this model type.
	 *
	 * Local is an allow-list (it must be inside the instance's own network);
	 * cloud is a deny-list (it must be outside it). The two are inverses, and
	 * neither is a superset of the other.
	 *
	 * @param string $ipAddress The resolved IP address.
	 * @param string $modelType The model type ('local' or 'cloud').
	 *
	 * @return bool True when the address is permitted.
	 */
	private function isAllowedAddress(string $ipAddress, string $modelType): bool {
		if ($modelType === 'local') {
			return $this->ipInAnyCidr(ipAddress: $ipAddress, cidrs: self::LOCAL_CIDRS);
		}//end if

		return $this->ipInAnyCidr(ipAddress: $ipAddress, cidrs: self::BLOCKED_CIDRS) === false;
	}//end isAllowedAddress()

	/**
	 * Whether an address falls inside any of a set of CIDR blocks.
	 *
	 * @param string $ipAddress The IP address to test.
	 * @param string[] $cidrs The CIDR blocks.
	 *
	 * @return bool True when the address is inside at least one block.
	 */
	private function ipInAnyCidr(string $ipAddress, array $cidrs): bool {
		foreach ($cidrs as $cidr) {
			if ($this->ipInCidr(ipAddress: $ipAddress, cidr: $cidr) === true) {
				return true;
			}//end if
		}

		return false;
	}//end ipInAnyCidr()

	/**
	 * Resolve a host to every address it answers with, ONCE.
	 *
	 * An IP literal resolves to itself. A name goes through the SYSTEM resolver
	 * (`gethostbynamel`) rather than straight to DNS, because a container
	 * reaches its sidecar through `/etc/hosts` and the compose embedded resolver
	 * — which `dns_get_record()` does not consult, so the previous cloud path
	 * could not see a docker service name at all. `dns_get_record()` is kept as
	 * the AAAA fallback, which `gethostbynamel()` cannot return.
	 *
	 * @param string $host The lower-cased host, without IPv6 brackets.
	 *
	 * @return string[] Every resolved address; empty when the host cannot be resolved.
	 */
	private function resolveHost(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}//end if

		$addresses = $this->withoutWarnings(
			operation: static function () use ($host): mixed {
				return gethostbynamel($host);
			}
		);
		if (is_array($addresses) === true && count($addresses) > 0) {
			return $addresses;
		}//end if

		$records = $this->withoutWarnings(
			operation: static function () use ($host): mixed {
				return dns_get_record($host, DNS_AAAA);
			}
		);
		if (is_array($records) === false) {
			return [];
		}//end if

		$resolved = [];
		foreach ($records as $record) {
			$address = ($record['ipv6'] ?? null);
			if ($address !== null) {
				$resolved[] = $address;
			}//end if
		}

		return $resolved;
	}//end resolveHost()

	/**
	 * Check if an IP address falls within a CIDR range (IPv4 and IPv6).
	 *
	 * @param string $ipAddress The IP address to test.
	 * @param string $cidr The CIDR block (e.g. '10.0.0.0/8').
	 *
	 * @return bool True if the IP is within the range.
	 */
	private function ipInCidr(string $ipAddress, string $cidr): bool {
		$isIpv6Cidr = str_contains($cidr, ':');
		$isIpv6Ip = str_contains($ipAddress, ':');

		if ($isIpv6Cidr === true && $isIpv6Ip === true) {
			return $this->isIpv6InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}//end if

		if ($isIpv6Cidr === false && $isIpv6Ip === false) {
			return $this->isIpv4InCidr(ipAddress: $ipAddress, cidr: $cidr);
		}//end if

		return false;
	}//end ipInCidr()

	/**
	 * Check if an IPv6 address falls within an IPv6 CIDR range.
	 *
	 * @param string $ipAddress The IPv6 address to test.
	 * @param string $cidr The IPv6 CIDR block (e.g. 'fc00::/7').
	 *
	 * @return bool True if the IP is within the range.
	 */
	private function isIpv6InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$networkBin = inet_pton($network);
		$inputBin = inet_pton($ipAddress);
		if ($networkBin === false || $inputBin === false) {
			return false;
		}//end if

		$fullBytes = intdiv($prefixLen, 8);
		$remainBits = $prefixLen % 8;
		for ($i = 0; $i < $fullBytes; $i++) {
			if ($networkBin[$i] !== $inputBin[$i]) {
				return false;
			}//end if
		}

		if ($remainBits > 0 && $fullBytes < 16) {
			$mask = (0xFF << (8 - $remainBits)) & 0xFF;
			if ((ord($networkBin[$fullBytes]) & $mask) !== (ord($inputBin[$fullBytes]) & $mask)) {
				return false;
			}//end if
		}//end if

		return true;
	}//end isIpv6InCidr()

	/**
	 * Check if an IPv4 address falls within an IPv4 CIDR range.
	 *
	 * @param string $ipAddress The IPv4 address to test.
	 * @param string $cidr The IPv4 CIDR block (e.g. '10.0.0.0/8').
	 *
	 * @return bool True if the IP is within the range.
	 */
	private function isIpv4InCidr(string $ipAddress, string $cidr): bool {
		[$network, $prefix] = explode('/', $cidr);
		$prefixLen = (int)$prefix;
		$networkLong = ip2long($network);
		$ipLong = ip2long($ipAddress);
		if ($networkLong === false || $ipLong === false) {
			return false;
		}//end if

		$mask = 0;
		if ($prefixLen !== 0) {
			$mask = ~0 << (32 - $prefixLen);
		}//end if

		return ($ipLong & $mask) === ($networkLong & $mask);
	}//end isIpv4InCidr()
}//end class
