<?php
/** @var ?array $job @var array $coverage @var string $mode @var bool $workerDown */
use HeardLive\Http\Csrf;

$active = $job !== null && in_array($job['status'], ['queued', 'running'], true);
$stage = $job['stage'] ?? null;
$stages = ['artists' => 'Artists', 'setlists' => 'Setlists'];

// Initial meter state; app.js keeps it current while the scan runs.
$meter = function (string $key) use ($job, $stage): array {
    if ($job === null || $stage === null) {
        return [0, ''];
    }
    $order = ['artists' => 0, 'setlists' => 1];
    if ($order[$key] < $order[$stage]) {
        return [100, 'done'];
    }
    if ($key !== $stage) {
        return [0, ''];
    }
    $pct = $job['total'] > 0 ? (int) floor($job['done'] / $job['total'] * 100) : 100;
    return [$pct, "{$job['done']} / {$job['total']}"];
};

$stateText = match ($job['status'] ?? null) {
    null => 'No scan yet.',
    'queued' => 'Waiting for the worker…',
    'running' => 'Scanning…',
    'done' => 'Finished.',
    'paused' => 'Paused: ' . ($job['error'] ?? ''),
    'failed' => 'Stopped: ' . ($job['error'] ?? ''),
    'canceled' => 'Canceled. Scanning again picks up where it stopped.',
    default => '',
};
$needChoice = $coverage['ambiguous'] + $coverage['artists_ambiguous'];
$noSetlist = $coverage['missing'] + $coverage['empty'];
?>
<section class="page">
    <div class="container narrow">
        <p class="eyebrow">Step 3 · Setlists</p>
        <h1 class="page-title">Find your setlists</h1>
        <p class="lead">For every act you saw, Heard Live looks on setlist.fm for that exact date and city. It goes about one request a second, so a full history takes a few minutes.</p>

        <?php if ($mode === 'fixture'): ?>
            <p class="alert">Using saved test data, not setlist.fm. To scan for real, add your API key to <span class="mono">.env</span> and set <span class="mono">SETLISTFM_MODE=live</span>.</p>
        <?php endif ?>
        <?php if ($workerDown): ?>
            <p class="alert alert--error" role="alert" data-worker-down>The background worker isn’t running, so scans won’t start. Run the app with <span class="mono">bin/dev</span>, which starts it too.</p>
        <?php endif ?>

        <div class="scan" data-job<?= $job !== null ? ' data-job-id="' . e($job['id']) . '"' : '' ?> data-job-status="<?= e($job['status'] ?? '') ?>">
            <?php foreach ($stages as $key => $label): [$pct, $count] = $meter($key); ?>
                <div class="meter<?= $stage === $key && $active ? ' is-active' : '' ?>" data-meter="<?= e($key) ?>">
                    <div class="meter__head">
                        <span class="meter__label"><?= e($label) ?></span>
                        <span class="meter__count" data-meter-count><?= e($count) ?></span>
                    </div>
                    <progress class="meter__bar" max="100" value="<?= e($pct) ?>" aria-label="<?= e($label) ?>"></progress>
                </div>
            <?php endforeach ?>

            <p class="scan__now" data-job-label><?= e($active ? ($job['current_label'] ?? '') : '') ?></p>
            <p class="scan__state<?= in_array($job['status'] ?? '', ['failed', 'paused'], true) ? ' is-error' : '' ?>" data-job-state role="status" aria-live="polite"><?= e($stateText) ?></p>

            <div class="scan__actions">
                <?php if ($active): ?>
                    <form method="post" action="/scan/<?= e($job['id']) ?>/cancel">
                        <?= Csrf::field() ?>
                        <button class="button button--ghost" type="submit">Cancel</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/scan">
                        <?= Csrf::field() ?>
                        <button class="button button--primary" type="submit"><?= $job === null ? 'Start scan' : 'Scan again' ?></button>
                    </form>
                    <?php if ($job !== null): ?>
                        <p class="muted small">Scanning again only looks up what’s new or unfinished.</p>
                    <?php endif ?>
                <?php endif ?>
            </div>
        </div>

        <h2 class="section-heading">Sets you saw</h2>
        <dl class="stats">
            <div class="stat"><dt>Setlist found</dt><dd><?= e($coverage['matched']) ?></dd></div>
            <div class="stat<?= $needChoice > 0 ? ' stat--warn' : ' stat--muted' ?>"><dt>Need your choice</dt><dd><?= e($needChoice) ?></dd></div>
            <div class="stat stat--muted"><dt>No setlist</dt><dd><?= e($noSetlist) ?></dd></div>
            <div class="stat stat--muted"><dt>Not scanned yet</dt><dd><?= e($coverage['pending'] + $coverage['error']) ?></dd></div>
        </dl>
        <div class="actions">
            <?php if ($needChoice > 0): ?>
                <a class="button button--primary" href="/concerts?filter=attention">Make <?= e($needChoice) ?> <?= $needChoice === 1 ? 'choice' : 'choices' ?></a>
            <?php endif ?>
            <?php if ($noSetlist > 0): ?>
                <a class="button button--ghost" href="/concerts?filter=missing">See shows without setlists</a>
            <?php endif ?>
        </div>
        <?php if ($coverage['artists_not_found'] > 0): ?>
            <p class="muted small footnote"><?= e($coverage['artists_not_found']) ?> <?= $coverage['artists_not_found'] === 1 ? 'artist isn’t' : 'artists aren’t' ?> on setlist.fm, so their sets count as “no setlist”.</p>
        <?php endif ?>
    </div>
</section>
