<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * The customer's own registry credential, resolved.
 *
 * Without one, a service whose image is private simply cannot start: the
 * kubelet has no credential, the pull fails, and the pod sits in
 * ImagePullBackOff with an error that says nothing about Cortex. Most
 * customers with a build pipeline have private images, so this was not an edge
 * case — it was most of them.
 *
 * Absent is not an error. Public images are the common case and must not be
 * made to depend on a credential nobody needs.
 */
readonly class RegistrySpec
{
    public function __construct(
        public string $server,
        public string $username,
        public string $password,
        /**
         * PEM of the authority that signed the registry's certificate, when the
         * system trust store cannot verify it.
         *
         * Empty for every external registry — Docker Hub, GHCR, ECR and the rest
         * carry publicly-trusted certificates, which is one of the reasons an
         * external registry is the recommended shape. It is populated only for
         * the optional self-hosted case, and it is the difference between that
         * option working and a build producing an image no node can pull.
         */
        public string $ca = '',
        /**
         * Where images this organization builds are pushed. Empty when they only
         * ever deploy images built elsewhere — the credential is then a pull
         * credential and nothing more.
         */
        public string $pushRepository = '',
    ) {}

    /**
     * The `.dockerconfigjson` a kubernetes.io/dockerconfigjson Secret holds.
     *
     * `auth` is base64 of `user:password` — an encoding, not a protection.
     * That is precisely why this belongs in a Secret referenced by the pod
     * rather than anywhere in a pod spec.
     */
    public function dockerConfigJson(): string
    {
        return json_encode([
            'auths' => [
                $this->server => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'auth' => base64_encode($this->username.':'.$this->password),
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
