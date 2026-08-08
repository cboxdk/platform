<?php

declare(strict_types=1);

namespace Cbox\Platform\Capability;

/**
 * Who signs the certificates a target serves.
 *
 * @see Certificates for the named constructors that carry each one's settings.
 */
enum CertificateSource: string
{
    case Acme = 'acme';
    case SelfSigned = 'self-signed';
    case CertificateAuthority = 'ca';
}
