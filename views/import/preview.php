<?php
/**
 * @var string $token @var string $filename @var array $summary @var list<array> $errors @var list<array> $skipped
 * @var list<string> $extraColumns @var list<\HeardLive\Import\ParsedEvent> $flagged @var bool $hasEvents
 */
use HeardLive\Concerts\Flags;
use HeardLive\Http\Csrf;

$fmt = fn (?string $date) => $date === null ? '—' : (new DateTimeImmutable($date))->format('M j, Y');
?>
<section class="page">
    <div class="container">
        <p class="eyebrow">Step 1 · Check your import</p>
        <h1 class="page-title">Here’s what we found</h1>
        <p class="lead"><span class="mono"><?= e($filename) ?></span> · <?= e($fmt($summary['first_date'])) ?> to <?= e($fmt($summary['last_date'])) ?></p>

        <dl class="stats">
            <div class="stat"><dt>Concerts</dt><dd><?= e($summary['events']) ?></dd></div>
            <div class="stat"><dt>Sets you saw</dt><dd><?= e($summary['performances_seen']) ?></dd></div>
            <div class="stat"><dt>Artists you saw</dt><dd><?= e($summary['artists_seen']) ?></dd></div>
            <div class="stat stat--muted"><dt>Listed, not seen</dt><dd><?= e($summary['performances_not_seen']) ?></dd></div>
        </dl>

        <?php if ($summary['events'] === 0): ?>
            <p class="alert alert--error" role="alert">There are no past concerts in this file to import.</p>
        <?php else: ?>
            <form class="confirm" method="post" action="/import/confirm">
                <?= Csrf::field() ?>
                <input type="hidden" name="file" value="<?= e($token) ?>">
                <button class="button button--primary" type="submit">
                    <?= $hasEvents ? 'Update my concerts' : 'Import ' . e($summary['events']) . ' concerts' ?>
                </button>
                <a class="button button--ghost" href="/import">Choose a different file</a>
            </form>
        <?php endif ?>

        <?php if ($flagged !== []): ?>
            <h2 class="section-heading"><?= count($flagged) ?> <?= count($flagged) === 1 ? 'show needs' : 'shows need' ?> a closer look later</h2>
            <p class="muted">These import fine. You’ll confirm them on the concert review screen.</p>
            <ul class="flag-list">
                <?php foreach ($flagged as $event): ?>
                    <li class="flag-list__item">
                        <span class="mono flag-list__date"><?= e($fmt($event->startDate)) ?></span>
                        <span class="flag-list__name"><?= e($event->name ?? ($event->seen[0] ?? 'Untitled show')) ?></span>
                        <span class="flag-list__why">
                            <?= e(implode('; ', array_map(fn ($f) => Flags::label($f, ['nonstandard_type' => $event->nonstandardType]), $event->flags))) ?>
                        </span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <?php if ($errors !== []): ?>
            <h2 class="section-heading"><?= count($errors) ?> <?= count($errors) === 1 ? 'row' : 'rows' ?> can’t be imported</h2>
            <ul class="flag-list">
                <?php foreach ($errors as $error): ?>
                    <li class="flag-list__item"><span class="mono flag-list__date">Row <?= e($error['row']) ?></span><span class="flag-list__why"><?= e($error['message']) ?></span></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <?php if ($skipped !== []): ?>
            <h2 class="section-heading"><?= count($skipped) ?> skipped</h2>
            <ul class="flag-list">
                <?php foreach ($skipped as $skip): ?>
                    <li class="flag-list__item"><span class="mono flag-list__date">Row <?= e($skip['row']) ?></span><span class="flag-list__why"><?= e($skip['message']) ?></span></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <?php if ($extraColumns !== []): ?>
            <p class="muted small">Columns not used: <?= e(implode(', ', $extraColumns)) ?>.</p>
        <?php endif ?>
    </div>
</section>
