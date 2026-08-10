
it('refuses an engine that needs a password and has none', function (): void {
    // Measured on a local cluster: a Percona StatefulSet takes its root password
    // from `<name>-credentials` unconditionally, so a spec with no password
    // compiled a workload that mounts a Secret nothing creates —
    // CreateContainerConfigError, forever, on a database whose own pod is fine.
    //
    // It asks the ENGINE. Valkey needs no password and must keep compiling.
    $percona = new DatabaseSpec(
        databaseId: '01J0000000000000000000DB01',
        organizationId: 'local',
        namespace: 'cbox-acme',
        name: 'db',
        engine: DatabaseEngine::Percona,
        version: '8.0',
        instances: 1,
        storageSize: '1Gi',
    );

    expect(fn () => test()->compileDatabase($percona))
        ->toThrow(LogicException::class, 'has no password');

    $valkey = new DatabaseSpec(
        databaseId: '01J0000000000000000000DB02',
        organizationId: 'local',
        namespace: 'cbox-acme',
        name: 'cache',
        engine: DatabaseEngine::Valkey,
        version: '8',
        instances: 1,
        storageSize: '1Gi',
    );

    expect(test()->compileDatabase($valkey)->manifests)->not->toBeEmpty();
});
