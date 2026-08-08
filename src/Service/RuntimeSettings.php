<?php

declare(strict_types=1);

namespace Cbox\Platform\Service;

/**
 * What a service can do because of the image it runs on.
 *
 * The Cortex base image is not a bare PHP runtime: it carries cbox-init, which
 * supervises a scheduler, Horizon, Reverb and queue workers **inside the same
 * container**, and it reads about 156 environment variables that tune PHP,
 * OPcache, FPM and nginx.
 *
 * TYPED, NOT A BAG OF STRINGS. All of this is already expressible as
 * environment variables, and a customer could set every one of them by hand —
 * which is exactly the state this replaces. A key/value table cannot say that
 * `LARAVEL_QUEUE_HIGH` needs `LARAVEL_QUEUE`, that a scheduler keeps a
 * container awake, or that `PHP_FPM_MAX_CHILDREN` turns off the auto-tuning
 * that reads the memory limit the customer set on the previous screen. Every
 * one of those is a support ticket a form can prevent.
 *
 * ABSENT MEANS ABSENT. A field that was never set emits no variable at all, so
 * the base image's own default applies and a customer who set something by hand
 * in `env` keeps it. That is what makes this safe to add to services that
 * already exist: the compiled output does not change until somebody chooses
 * something.
 */
readonly class RuntimeSettings
{
    public function __construct(
        /** `php artisan schedule:work`, supervised. */
        public bool $scheduler = false,
        public bool $horizon = false,
        public bool $reverb = false,
        public bool $queue = false,
        public bool $queueHigh = false,
        /** cboxdk/laravel-queue-autoscale, which sizes the workers from depth. */
        public bool $queueAutoscaler = false,
        public ?int $queueScale = null,
        public ?int $queueHighScale = null,

        /** `config:cache`, `route:cache`, `view:cache` on startup. */
        public bool $optimize = false,
        /**
         * `php artisan migrate --force` on startup.
         *
         * Dangerous in a way the label has to carry: with more than one replica
         * every one of them runs it, and a rollback does not un-migrate.
         */
        public bool $migrate = false,
        public bool $migrateAllowFailure = false,

        public ?string $memoryLimit = null,
        public ?int $maxExecutionTime = null,
        public ?string $uploadMaxFilesize = null,
        public ?string $postMaxSize = null,
        public ?string $timezone = null,

        public ?OpcacheJit $opcacheJit = null,
        public ?int $opcacheMemory = null,
        public ?FpmProfile $fpmProfile = null,
    ) {}

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $bool = static fn (string $key): bool => (bool) ($stored[$key] ?? false);
        $int = static fn (string $key): ?int => isset($stored[$key]) && is_numeric($stored[$key])
            ? (int) $stored[$key]
            : null;
        $string = static fn (string $key): ?string => isset($stored[$key])
            && is_string($stored[$key]) && trim($stored[$key]) !== ''
            ? trim($stored[$key])
            : null;

        return new self(
            scheduler: $bool('scheduler'),
            horizon: $bool('horizon'),
            reverb: $bool('reverb'),
            queue: $bool('queue'),
            queueHigh: $bool('queue_high'),
            queueAutoscaler: $bool('queue_autoscaler'),
            queueScale: $int('queue_scale'),
            queueHighScale: $int('queue_high_scale'),
            optimize: $bool('optimize'),
            migrate: $bool('migrate'),
            migrateAllowFailure: $bool('migrate_allow_failure'),
            memoryLimit: $string('memory_limit'),
            maxExecutionTime: $int('max_execution_time'),
            uploadMaxFilesize: $string('upload_max_filesize'),
            postMaxSize: $string('post_max_size'),
            timezone: $string('timezone'),
            opcacheJit: OpcacheJit::tryFrom($string('opcache_jit') ?? ''),
            opcacheMemory: $int('opcache_memory'),
            fpmProfile: FpmProfile::tryFrom($string('fpm_profile') ?? ''),
        );
    }

    /**
     * The environment the base image reads.
     *
     * Only what was chosen. Everything absent falls through to the image's own
     * default, which is the behaviour every existing service already has.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $env = [];

        // Processes. The shorthand names, not the CBOX_INIT_PROCESS_* ones they
        // map to: the image owns that mapping, and repeating it here would be a
        // second copy to keep in step with a version we do not control.
        foreach ([
            'LARAVEL_SCHEDULER' => $this->scheduler,
            'LARAVEL_HORIZON' => $this->horizon,
            'LARAVEL_REVERB' => $this->reverb,
            'LARAVEL_QUEUE' => $this->queue,
            'LARAVEL_QUEUE_HIGH' => $this->queueHigh,
            'CBOX_QUEUE_AUTOSCALER' => $this->queueAutoscaler,
            'LARAVEL_OPTIMIZE_ENABLED' => $this->optimize,
            'LARAVEL_MIGRATE_ENABLED' => $this->migrate,
            'LARAVEL_MIGRATE_ALLOW_FAILURE' => $this->migrateAllowFailure,
        ] as $name => $enabled) {
            if ($enabled) {
                $env[$name] = 'true';
            }
        }

        // Scale only where the worker is on. Sizing a queue nobody runs is a
        // setting that does nothing and reads as if it does.
        if ($this->queue && $this->queueScale !== null) {
            $env['CBOX_INIT_PROCESS_QUEUE_DEFAULT_SCALE'] = (string) max(1, $this->queueScale);
        }

        if ($this->queueHigh && $this->queueHighScale !== null) {
            $env['CBOX_INIT_PROCESS_QUEUE_HIGH_SCALE'] = (string) max(1, $this->queueHighScale);
        }

        foreach ([
            'PHP_MEMORY_LIMIT' => $this->memoryLimit,
            'PHP_MAX_EXECUTION_TIME' => $this->maxExecutionTime,
            'PHP_UPLOAD_MAX_FILESIZE' => $this->uploadMaxFilesize,
            'PHP_POST_MAX_SIZE' => $this->postMaxSize,
            'PHP_DATE_TIMEZONE' => $this->timezone,
            'PHP_OPCACHE_JIT' => $this->opcacheJit?->value,
            'PHP_OPCACHE_MEMORY_CONSUMPTION' => $this->opcacheMemory,
            'PHP_FPM_AUTOTUNE_PROFILE' => $this->fpmProfile?->value,
        ] as $name => $value) {
            if ($value !== null && $value !== '') {
                $env[$name] = (string) $value;
            }
        }

        return $env;
    }

    /**
     * Whether anything here keeps the container busy when no request is in
     * flight.
     *
     * THE ONE INTERACTION THAT MUST NOT BE SILENT. A scheduler ticks every
     * minute and a queue worker blocks on a connection: either one means the
     * container is never idle, so it never scales to zero, and the customer is
     * billed for a service they were told would sleep.
     *
     * This platform has already shipped that failure twice — a runtime default
     * claiming an agent no cell had, and a database whose scale_to_zero
     * compiled to nothing — so it is a method rather than a note in a docblock.
     */
    public function keepsContainerAwake(): bool
    {
        return $this->scheduler || $this->horizon || $this->reverb
            || $this->queue || $this->queueHigh;
    }

    /**
     * The processes running beside the web server, named as a person would.
     *
     * @return array<int, string>
     */
    public function backgroundProcesses(): array
    {
        $running = [];

        if ($this->scheduler) {
            $running[] = 'scheduler';
        }

        if ($this->horizon) {
            $running[] = 'Horizon';
        }

        if ($this->reverb) {
            $running[] = 'Reverb';
        }

        if ($this->queue) {
            $running[] = 'queue'.($this->queueScale !== null ? ' ×'.max(1, $this->queueScale) : '');
        }

        if ($this->queueHigh) {
            $running[] = 'queue:high'.($this->queueHighScale !== null ? ' ×'.max(1, $this->queueHighScale) : '');
        }

        return $running;
    }
}
