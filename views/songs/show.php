<?php
/** @var array $song @var list<array> $mergeTargets @var bool $merged */
use HeardLive\Concerts\EventPresenter;
use HeardLive\Http\Csrf;

$performances = $song['performances'];
$heard = count($performances);
?>
<section class="page">
    <div class="container narrow">
        <p class="eyebrow"><a href="/songs">← All songs</a></p>

        <?php if ($merged): ?>
            <p class="alert alert--ok" role="status">Merged. Future scans will treat both titles as this song.</p>
        <?php endif ?>

        <h1 class="page-title song-title"><?= e($song['title_display']) ?></h1>
        <p class="lead">
            <?= e($song['performer']) ?>
            <?php if ($song['original_artist_name'] !== null): ?> · cover of <?= e($song['original_artist_name']) ?><?php endif ?>
        </p>

        <?php if ($song['excluded']): ?>
            <p class="alert">Excluded: this song stays in your history but won’t go on the playlist.</p>
        <?php endif ?>

        <dl class="stats stats--two">
            <div class="stat"><dt>Times heard</dt><dd><?= e($heard) ?></dd></div>
            <div class="stat"><dt>First heard</dt><dd class="stat__date"><?= e($heard > 0 ? (new DateTimeImmutable(end($performances)['start_date']))->format('M j, Y') : '—') ?></dd></div>
        </dl>

        <h2 class="section-heading">Where you heard it</h2>
        <?php if ($performances === []): ?>
            <p class="empty">None of the shows with this song currently count (the performer may be unticked, or the setlist changed).</p>
        <?php else: ?>
            <ol class="hearings">
                <?php foreach ($performances as $p): ?>
                    <li class="hearing">
                        <p class="hearing__date mono"><?= e((new DateTimeImmutable($p['setlist_event_date'] ?? $p['start_date']))->format('D m.d.Y')) ?></p>
                        <p class="hearing__event">
                            <a href="/concerts#event-<?= e($p['event_id']) ?>"><?= e($p['event_name'] ?? $p['venue'] ?? 'Concert') ?></a>
                        </p>
                        <p class="hearing__place muted"><?= e(implode(' · ', array_filter([$p['venue'], implode(', ', array_filter([$p['city'], $p['region']]))]))) ?></p>
                        <ul class="badges">
                            <?php if ($p['encore'] !== null): ?><li class="badge badge--muted">encore</li><?php endif ?>
                            <?php if ($p['times_in_show'] > 1): ?><li class="badge badge--muted">played ×<?= e($p['times_in_show']) ?></li><?php endif ?>
                            <?php if ($p['variant'] !== null): ?><li class="badge badge--muted"><?= e($p['variant']) ?></li><?php endif ?>
                            <?php if ($p['partial']): ?><li class="badge badge--muted">partial</li><?php endif ?>
                            <?php if ($p['medley']): ?><li class="badge badge--muted">from a medley</li><?php endif ?>
                            <?php if ($p['with_artist'] !== null): ?><li class="badge badge--muted">with <?= e($p['with_artist']) ?></li><?php endif ?>
                            <li class="badge badge--muted">song <?= e($p['position']) ?></li>
                        </ul>
                        <?php if ($p['info'] !== null && $p['info'] !== '>'): ?>
                            <p class="hearing__info"><?= e(rtrim($p['info'], '; >')) ?></p>
                        <?php endif ?>
                        <?php if (EventPresenter::setlistFmUrl($p['setlist_url']) !== null): ?>
                            <a class="hearing__link" href="<?= e($p['setlist_url']) ?>" target="_blank" rel="noopener">Setlist on setlist.fm&nbsp;↗</a>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>

        <h2 class="section-heading">Fix this song</h2>
        <div class="song-actions">
            <form method="post" action="/songs/<?= e($song['id']) ?>/exclude">
                <?= Csrf::field() ?>
                <input type="hidden" name="excluded" value="<?= $song['excluded'] ? '0' : '1' ?>">
                <button class="button button--ghost button--small" type="submit"><?= $song['excluded'] ? 'Put back on the playlist' : 'Leave off the playlist' ?></button>
            </form>

            <?php if ($mergeTargets !== []): ?>
                <form class="merge" method="post" action="/songs/<?= e($song['id']) ?>/merge">
                    <?= Csrf::field() ?>
                    <label class="field field--grow">
                        <span class="small muted">Same song as another title by <?= e($song['performer']) ?>?</span>
                        <select name="into" required>
                            <option value="">Choose the title to keep…</option>
                            <?php foreach ($mergeTargets as $target): ?>
                                <option value="<?= e($target['id']) ?>"><?= e($target['title_display']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </label>
                    <button class="button button--ghost button--small" type="submit">Merge</button>
                </form>
            <?php endif ?>
        </div>
    </div>
</section>
