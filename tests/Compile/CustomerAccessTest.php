<?php

declare(strict_types=1);

use Cbox\Platform\Capability\CustomerAccess;
use Cbox\Platform\Compile\CustomerAccessCompiler;

/**
 * RBAC for a customer's own kubectl.
 *
 * The identity arrives from cbox-id and the tenant API server turns it into
 * `cbox:<sub>` with groups `cbox:<role>`. Kubernetes denies by default, so
 * without these bindings a working credential authenticates and can read
 * nothing — which is indistinguishable, from the customer's side, from a broken
 * login.
 */
/**
 * The roles a customer's identity provider grants, as the target declares them.
 *
 * @param  array<string, string>  $roles
 */
function accessCompiler(array $roles = ['cluster-admin' => 'admin', 'cluster-viewer' => 'view']): CustomerAccessCompiler
{
    return new CustomerAccessCompiler(new CustomerAccess(roles: $roles));
}

it('binds a role to a group inside the customer namespace only', function (): void {
    $manifests = accessCompiler()->forNamespace('cx-shop-abc123', ['platform.cbox.dk/managed' => 'true']);

    expect($manifests)->toHaveCount(2);

    $admin = collect($manifests)->firstOrFail(fn ($m): bool => $m->name === 'cbox-cluster-admin');

    // A RoleBinding, never a ClusterRoleBinding: `admin` on the customer's own
    // namespace is their application, and the same ClusterRole bound
    // cluster-wide would reach kube-system, the CNI and every controller Cortex
    // runs on their behalf.
    // The BODY's kind, not just the Manifest's: the body is what is applied,
    // and a mutation that changed only it left this test green.
    expect($admin->kind)->toBe('RoleBinding')
        ->and($admin->body['kind'])->toBe('RoleBinding')
        ->and($admin->body['metadata']['namespace'])->toBe('cx-shop-abc123')
        ->and($admin->namespace)->toBe('cx-shop-abc123')
        ->and($admin->body['roleRef']['kind'])->toBe('ClusterRole')
        ->and($admin->body['roleRef']['name'])->toBe('admin');

    // A GROUP, not a person. Cortex holds no membership list — cbox-id stamps
    // the roles somebody holds in their organization into every token — so
    // adding or removing a teammate changes their next token and rewrites no
    // binding anywhere.
    expect($admin->body['subjects'])->toBe([[
        'apiGroup' => 'rbac.authorization.k8s.io',
        'kind' => 'Group',
        'name' => 'cbox:cluster-admin',
    ]]);
});

// The prefix is what keeps an issued identity out of the admission policy's
// exemption, and it has to be the same string the API server applies.
it('names groups with the prefix the API server applies', function (): void {
    $manifests = accessCompiler()->forNamespace('cx-shop-abc123', []);

    foreach ($manifests as $manifest) {
        foreach ($manifest->body['subjects'] as $subject) {
            expect($subject['name'])->toStartWith('cbox:')
                ->and($subject['name'])->not->toBe('kubernetes-admin')
                ->and($subject['name'])->not->toStartWith('system:');
        }
    }
});

it('grants read-only sight of the cluster and nothing more', function (): void {
    $objects = accessCompiler()->clusterWide([]);

    $role = collect($objects)->firstOrFail(fn (array $o): bool => $o['kind'] === 'ClusterRole');

    foreach ($role['rules'] as $rule) {
        // Nothing cluster-wide may write. Everything a customer can change,
        // they change inside their own namespace.
        expect($rule['verbs'])->each->toBeIn(['get', 'list', 'watch']);
    }

    $resources = collect($role['rules'])->flatMap(fn (array $r): array => $r['resources'])->all();

    expect($resources)->toContain('namespaces')
        ->and($resources)->toContain('nodes')
        // Secrets cluster-wide would be every other tenant-facing controller's
        // credentials, including the customer's own provider token.
        ->and($resources)->not->toContain('secrets')
        ->and($resources)->not->toContain('pods');
});

/**
 * OFF is the state every existing tenant is in, and it has to compile to
 * nothing.
 *
 * A binding naming a group no token can carry grants nobody anything — it is
 * only an object somebody has to explain later, on every cluster.
 */
it('compiles nothing when customer identity is not configured', function (): void {
    expect(accessCompiler([])->forNamespace('cx-shop-abc123', []))->toBe([])
        ->and(accessCompiler([])->clusterWide([]))->toBe([]);
});

/**
 * The admission policy is compiled AHEAD of the addons it protects.
 *
 * A ValidatingAdmissionPolicy is eventually consistent: the API server compiles
 * it and syncs the binding asynchronously, and the identity lab measured the
 * best part of ninety seconds before it began refusing anything. Applied last,
 * that window is spent with every addon already in place; applied first, it is
 * spent while the cluster is still being built.
 */
