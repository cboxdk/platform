<?php

declare(strict_types=1);

namespace Cbox\Platform\Testing;

use Cbox\Platform\Binding\BindingSpec;
use Cbox\Platform\Binding\ConnectionField;
use Cbox\Platform\Binding\ConnectionSource;
use Cbox\Platform\Database\DatabaseEngine;
use Cbox\Platform\Database\DatabaseSpec;
use Cbox\Platform\Route\EnvironmentGatewaySpec;
use Cbox\Platform\Service\ResourceRequirements;
use Cbox\Platform\Service\ServiceSpec;

/**
 * Minimal valid intent, for tests that care about one field.
 *
 * A `ServiceSpec` has more than twenty parameters because a service genuinely
 * has that many facts, and a test asserting on replica count should not have to
 * name the other nineteen. Every builder here returns something that compiles,
 * and takes overrides for what the test is actually about.
 *
 * Deliberately NOT random. A fixture that varies between runs makes a golden
 * file impossible and a flake inevitable; these values are fixed and boring.
 */
class SpecFactory
{
    public const ORGANIZATION = '01J0000000000000000000ORG1';

    public const NAMESPACE = 'cx-production-test';

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $domains
     * @param  list<BindingSpec>  $bindings
     */
    public static function service(
        string $name = 'web',
        int $replicas = 1,
        array $env = [],
        array $domains = [],
        array $bindings = [],
        bool $scaleToZero = false,
        ?ResourceRequirements $resources = null,
    ): ServiceSpec {
        return new ServiceSpec(
            serviceId: '01J0000000000000000000SVC1',
            organizationId: self::ORGANIZATION,
            namespace: self::NAMESPACE,
            name: $name,
            image: 'ghcr.io/example/'.$name.':1.0.0',
            port: 8080,
            replicas: $replicas,
            env: $env,
            bindings: $bindings,
            domains: $domains,
            scaleToZero: $scaleToZero,
            resources: $resources,
        );
    }

    public static function database(
        DatabaseEngine $engine = DatabaseEngine::Postgres,
        string $name = 'primary',
        int $instances = 1,
    ): DatabaseSpec {
        return new DatabaseSpec(
            databaseId: '01J0000000000000000000DB01',
            organizationId: self::ORGANIZATION,
            namespace: self::NAMESPACE,
            name: $name,
            engine: $engine,
            version: $engine->defaultVersion(),
            instances: $instances,
            storageSize: '10Gi',
            password: $engine->needsPassword() ? 'fixed-password-for-the-fixture' : null,
        );
    }

    /** A binding to the database above, resolved the way a mapper would. */
    public static function binding(string $name = 'primary'): BindingSpec
    {
        return new BindingSpec(
            databaseName: $name,
            engine: 'postgres',
            map: [
                ['field' => ConnectionField::Host, 'name' => 'DB_HOST'],
                ['field' => ConnectionField::Password, 'name' => 'DB_PASSWORD'],
            ],
            source: new ConnectionSource(
                secretName: $name.'-app',
                secretKeys: [ConnectionField::Password->value => 'password'],
                plain: [ConnectionField::Host->value => $name.'-rw.'.self::NAMESPACE.'.svc.cluster.local'],
            ),
        );
    }

    /**
     * @param  list<string>  $domains
     */
    public static function gateway(array $domains = ['app.example.test']): EnvironmentGatewaySpec
    {
        return new EnvironmentGatewaySpec(
            environmentId: '01J0000000000000000000ENV1',
            organizationId: self::ORGANIZATION,
            namespace: self::NAMESPACE,
            domains: $domains,
        );
    }
}
