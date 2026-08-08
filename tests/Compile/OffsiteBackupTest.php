<?php

declare(strict_types=1);

use Cbox\Platform\Database\BackupStorage;

/**
 * Whether a backup destination is actually somewhere else.
 *
 * The store shipped inside the tenant's own cluster, so backups sat on the same
 * nodes as the databases they protected — which survives a dropped table and
 * nothing worse. External object storage, in another region or another provider,
 * is the destination this should recommend; a self-hosted one has to be possible
 * and should not be the default answer.
 *
 * The classifier is conservative on purpose: the dangerous mistake is calling
 * something off-site when it is not, so anything ambiguous is treated as local.
 */
function storageAt(string $endpoint): BackupStorage
{
    return new BackupStorage(
        bucket: 'cortex-backups',
        endpoint: $endpoint,
        region: 'fsn1',
        accessKeyId: 'ak',
        secretAccessKey: 'sk',
        retainDays: 14,
    );
}

it('does not call an in-cluster service off-site', function (string $endpoint): void {
    expect(storageAt($endpoint)->isOffsite())->toBeFalse();
})->with([
    'the live tenant\'s own store' => ['http://minio.obj.svc:9000'],
    'fully qualified cluster DNS' => ['http://seaweedfs.objtest.svc.cluster.local:9000'],
    'bare service name' => ['http://minio:9000'],
    'loopback' => ['http://localhost:9000'],
    'loopback by address' => ['http://127.0.0.1:9000'],
]);

it('does not call a private address off-site', function (string $endpoint): void {
    // A store on the cell's private network is off the tenant's NODES but still
    // inside the same failure domain as far as a lost site is concerned, and it
    // is certainly not another provider.
    expect(storageAt($endpoint)->isOffsite())->toBeFalse();
})->with([
    'RFC1918 10/8' => ['http://10.100.1.10:9000'],
    'RFC1918 172.16/12' => ['http://172.16.4.4:9000'],
    'RFC1918 192.168/16' => ['http://192.168.1.50:9000'],
]);

it('recognises real external object storage', function (string $endpoint): void {
    expect(storageAt($endpoint)->isOffsite())->toBeTrue();
})->with([
    'Hetzner object storage' => ['https://fsn1.your-objectstorage.com'],
    'AWS S3' => ['https://s3.eu-central-1.amazonaws.com'],
    'Cloudflare R2' => ['https://abc123.r2.cloudflarestorage.com'],
    'Backblaze B2' => ['https://s3.us-west-004.backblazeb2.com'],
    'a public address' => ['https://77.42.9.53:9000'],
]);

it('treats an unparseable endpoint as local rather than guessing', function (): void {
    // The dangerous error is claiming off-site when it is not, so doubt resolves
    // towards local.
    expect(storageAt('')->isOffsite())->toBeFalse()
        ->and(storageAt('not a url')->isOffsite())->toBeFalse();
});
