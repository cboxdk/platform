<?php

declare(strict_types=1);

namespace Cbox\Platform\Compile;

use Cbox\Platform\Capability\CustomerAccess;
use Cbox\Platform\Manifest\Manifest;

/**
 * What a customer may do inside their own cluster, once they have a kubectl.
 *
 * The identity arrives as an OIDC token from cbox-id and the tenant API server
 * turns it into a username `cbox:<sub>` and groups `cbox:<role>`. Neither means
 * anything to Kubernetes until something binds them, and RBAC denies by
 * default — so without this, a customer with a working credential authenticates
 * and can then read nothing at all.
 *
 * BOUND TO GROUPS, NOT PEOPLE. Cortex holds no list of who is on which team;
 * cbox-id does, and it stamps the roles a person holds for this client in their
 * organization into every token it mints. So adding somebody to a team changes
 * their next token and no RoleBinding on any cluster has to be rewritten — and,
 * more to the point, removing them takes effect at their next token too, rather
 * than when a reconcile somewhere notices.
 *
 * NAMESPACE-SCOPED, deliberately. `admin` on a customer's own environment
 * namespace is a large permission and the right one: it is their application.
 * The same ClusterRole bound cluster-wide would reach kube-system, the CNI, the
 * CSI driver and every controller Cortex runs on their behalf — and Cortex's
 * admission policy protects its OBJECTS, not the cluster's plumbing.
 *
 * The cluster-wide grant here is the smallest thing that makes kubectl usable:
 * listing namespaces and nodes. Without it `kubectl get ns` fails and the tool
 * looks broken; with it a customer can see the shape of the cluster they are
 * paying for and nothing else.
 */
class CustomerAccessCompiler
{
    private const READER_ROLE = 'cortex:cluster-reader';

    public function __construct(private readonly CustomerAccess $access = new CustomerAccess) {}

    /**
     * Bindings for one environment namespace.
     *
     * Empty when identity is not configured, which is what keeps this off for
     * every existing tenant: no issuer means no token can name these groups, so
     * a binding would grant nothing and only add an object to explain later.
     *
     * @param  array<string, string>  $labels
     * @return list<Manifest>
     */
    public function forNamespace(string $namespace, array $labels): array
    {
        $manifests = [];

        foreach ($this->roles() as $role => $clusterRole) {
            $name = 'cortex-'.$role;

            $manifests[] = new Manifest(
                apiVersion: 'rbac.authorization.k8s.io/v1',
                kind: 'RoleBinding',
                name: $name,
                namespace: $namespace,
                body: [
                    'apiVersion' => 'rbac.authorization.k8s.io/v1',
                    'kind' => 'RoleBinding',
                    'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
                    'roleRef' => [
                        'apiGroup' => 'rbac.authorization.k8s.io',
                        // A ClusterRole referenced from a RoleBinding applies
                        // only inside this namespace. Kubernetes ships `admin`,
                        // `edit` and `view` and keeps them current as APIs
                        // change; a hand-written Role would need maintaining
                        // every time a resource is added.
                        'kind' => 'ClusterRole',
                        'name' => $clusterRole,
                    ],
                    'subjects' => [[
                        'apiGroup' => 'rbac.authorization.k8s.io',
                        'kind' => 'Group',
                        'name' => $this->access->groupPrefix.$role,
                    ]],
                ],
            );
        }

        return $manifests;
    }

    /**
     * The one cluster-wide grant: seeing that the cluster exists.
     *
     * Compiled with the tenant addons rather than per namespace, because it is
     * per cluster — and read-only on purpose. Everything a customer can change
     * they change inside their own namespace.
     *
     * @param  array<string, string>  $labels
     * @return list<array<string, mixed>>
     */
    public function clusterWide(array $labels): array
    {
        $roles = $this->roles();

        if ($roles === []) {
            return [];
        }

        $subjects = [];

        foreach (array_keys($roles) as $role) {
            $subjects[] = [
                'apiGroup' => 'rbac.authorization.k8s.io',
                'kind' => 'Group',
                'name' => $this->access->groupPrefix.$role,
            ];
        }

        return [
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'ClusterRole',
                'metadata' => ['name' => self::READER_ROLE, 'labels' => $labels],
                'rules' => [
                    [
                        // Namespaces and nodes, read-only. `kubectl get ns`
                        // failing is what makes a working credential look
                        // broken, and a customer paying for nodes should be
                        // able to see them.
                        'apiGroups' => [''],
                        'resources' => ['namespaces', 'nodes'],
                        'verbs' => ['get', 'list', 'watch'],
                    ],
                    [
                        // Storage classes, so a PersistentVolumeClaim can be
                        // written against a class the customer can actually
                        // name rather than guessed at.
                        'apiGroups' => ['storage.k8s.io'],
                        'resources' => ['storageclasses'],
                        'verbs' => ['get', 'list'],
                    ],
                ],
            ],
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'ClusterRoleBinding',
                'metadata' => ['name' => self::READER_ROLE, 'labels' => $labels],
                'roleRef' => [
                    'apiGroup' => 'rbac.authorization.k8s.io',
                    'kind' => 'ClusterRole',
                    'name' => self::READER_ROLE,
                ],
                'subjects' => $subjects,
            ],
        ];
    }

    /**
     * The configured roles, or none when customer identity is off.
     *
     * @return array<string, string>
     */
    private function roles(): array
    {
        return $this->access->roles;
    }
}
