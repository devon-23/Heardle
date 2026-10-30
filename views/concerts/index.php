<?php
/**
 * @var string $filter @var array $stats @var array $counts @var array<string, list<array>> $byYear @var ?array $imported
 */
use HeardLive\Http\Csrf;

$filters = [
    'all' => 'All',
    'attention' => 'Needs a look',
    'missing' => 'Missing setlists',
    'unseen' => 'Has acts you missed',
    'festivals' => 'Festivals',
];
?>
<section class="page">
    <div class="container">
        <?php if ($imported !== null): ?>
            <p class="alert alert--ok" role="status">
                <?php if ($imported['updated'] === 0): ?>
                    Imported <?= e($imported['created']) ?> concerts.
                <?php else: ?>
                    Updated <?= e($imported['updated']) ?> concerts, added <?= e($imported['created']) ?><?= $imported['removed'] > 0 ? ', and hid ' . e($imported['removed']) . ' no longer in your export' : '' ?>.
                <?php endif ?>
            </p>
        <?php endif ?>

        <p class="eyebrow">Step 2 · Who did you see?</p>
        <h1 class="page-title">Your concerts</h1>
        <p class="lead">Everyone in “Bands Seen” is ticked already. Untick anyone you missed, tick anyone you caught after all. Changes save as you go.</p>

        <?php if ($stats['unscanned'] > 0): ?>
            <div class="callout">
                <p><strong><?= e($stats['unscanned']) ?> <?= $stats['unscanned'] === 1 ? 'set you saw hasn’t' : 'sets you saw haven’t' ?></strong> been looked up on setlist.fm yet.</p>
                <a class="button button--primary button--small" href="/scan">Find setlists</a>
            </div>
        <?php endif ?>

        <dl class="stats" data-stats>
            <div class="stat"><dt>Concerts</dt><dd><?= e($stats['events']) ?></dd></div>
            <div class="stat"><dt>Sets you saw</dt><dd data-stat="attended"><?= e($stats['attended']) ?></dd></div>
            <div class="stat"><dt>Artists</dt><dd data-stat="artists"><?= e($stats['artists']) ?></dd></div>
            <div class="stat<?= $stats['attention'] > 0 ? ' stat--warn' : ' stat--muted' ?>"><dt>Need a look</dt><dd data-stat="attention"><?= e($stats['attention']) ?></dd></div>
        </dl>

        <div class="toolbar">
            <nav class="chips" aria-label="Filter concerts">
                <?php foreach ($filters as $key => $label): ?>
                    <a class="chip" href="/concerts?filter=<?= e($key) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>>
                        <?= e($label) ?> <span class="chip__count"><?= e($counts[$key]) ?></span>
                    </a>
                <?php endforeach ?>
            </nav>
            <?php if ($filter === 'attention' && $counts['attention'] > 0): ?>
                <form method="post" action="/concerts/review-all">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="filter" value="attention">
                    <button class="button button--ghost button--small" type="submit">Mark all <?= e($counts['attention']) ?> as reviewed</button>
                </form>
            <?php endif ?>
        </div>

        <?php if ($byYear === []): ?>
            <p class="empty"><?= $filter === 'attention' ? 'Nothing needs a look. Nice.' : 'No concerts match this filter.' ?></p>
        <?php endif ?>

        <?php foreach ($byYear as $year => $events): ?>
            <section class="year" aria-labelledby="year-<?= e($year) ?>">
                <h2 class="year__title" id="year-<?= e($year) ?>">
                    <?= e($year) ?> <span class="year__count"><?= count($events) ?> <?= count($events) === 1 ? 'concert' : 'concerts' ?></span>
                </h2>
                <div class="events">
                    <?php foreach ($events as $event): ?>
                        <?= $view->partial('concerts/_event', ['event' => $event, 'filter' => $filter]) ?>
                    <?php endforeach ?>
                </div>
            </section>
        <?php endforeach ?>
    </div>
</section>
