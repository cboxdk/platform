<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

use LogicException;

/**
 * How a target gets a certificate for a hostname it serves.
 *
 * THIS IS THE FIRST CAPABILITY TWO REAL TARGETS DISAGREE ON, and the
 * disagreement is not a preference. ACME's HTTP-01 challenge requires the
 * authority to reach the hostname from the public internet. A hosted cluster
 * can be reached; a local kind cluster cannot, and no amount of configuration
 * makes it so — the order simply never validates and every hostname stays
 * without TLS.
 *
 * So a local target issues from its own authority instead. The compiled
 * `Certificate` objects are identical either way; only the `Issuer` differs,
 * which is precisely the shape a capability should have.
 */
readonly class Certificates
{
    private function __construct(
        public CertificateSource $source,
        public string $acmeServer = '',
        public string $acmeEmail = '',
        public string $caSecretName = '',
    ) {}

    /**
     * A public authority over ACME. The default target's answer, and the only
     * one that produces a certificate a browser trusts without configuration.
     */
    public static function acme(
        string $server = 'https://acme-staging-v02.api.letsencrypt.org/directory',
        string $email = '',
    ): self {
        if ($server === '') {
            throw new LogicException('An ACME issuer needs a directory URL.');
        }

        return new self(CertificateSource::Acme, acmeServer: $server, acmeEmail: $email);
    }

    /**
     * cert-manager signs with a key it generates itself.
     *
     * Honest about what it is: the certificate is valid and the traffic is
     * encrypted, but nothing trusts the signer, so a browser warns and a client
     * needs the chain. That is the right trade for a development cluster and
     * the wrong one for production, which is why it is named rather than
     * reached for as a fallback when ACME fails.
     */
    public static function selfSigned(): self
    {
        return new self(CertificateSource::SelfSigned);
    }

    /**
     * An authority the operator already has, held in a Secret in the
     * environment's namespace — a local development CA the host machine trusts,
     * or an internal PKI.
     */
    public static function certificateAuthority(string $secretName): self
    {
        if ($secretName === '') {
            throw new LogicException('A CA issuer needs the Secret holding its key pair.');
        }

        return new self(CertificateSource::CertificateAuthority, caSecretName: $secretName);
    }

    /**
     * Does issuing require the authority to reach the hostname from outside?
     *
     * The one question a consumer has to answer before pointing this at a
     * cluster that is not publicly reachable.
     */
    public function needsInboundReachability(): bool
    {
        return $this->source === CertificateSource::Acme;
    }
}
