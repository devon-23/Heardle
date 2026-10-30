<?php
/**
 * @var string $tab @var string $q @var array<string, int> $counts @var string $mode @var bool $scanning @var string $error
 * @var array{songs: list<array>, total: int, pages: int, page: int} $result
 */
use HeardLive\Http\Csrf;
use HeardLive\Matching\MatchPresenter as M;

$link = function (array $changes) use ($tab, $q, $result): string {
    $params = array_filter($changes + ['tab' => $tab, 'q' => $q, 'page' => $result['page']], fn ($v) => $v !== '' && $v !== 1 && $v !== null);

    return '/matches' . ($params === [] ? '' : '?' . http_build_query($params));
};
$back = array_filter(['tab' => $tab, 'q' => $q, 'page' => $result['page'] > 1 ? $result['page'] : null], fn ($v) => $v !== null && $v !== '');
$grid = in_array($tab, ['auto', 'chosen'], true);
$total = array_sum(array_intersect_key($counts, array_flip(['review', 'unmatched', 'covers', 'auto', 'chosen'])));
?>
<section class="page">
    <div class="container">
        <p class="eyebrow">Step 5 · Apple Music</p>
        <h1 class="page-title">Match your songs</h1>
        <p class="lead">Each song gets one Apple Music recording, studio versions first. Sure matches are picked for you; the rest wait here for a quick look.</p>

        <?php if ($mode === 'fixture'): ?>
            <p class="alert">Using saved test data, not Apple Music. Set <span class="mono">ITUNES_MODE=live</span> in <span class="mono">.env</span> to search for real.</p>
        <?php endif ?>
        <?php if ($error === 'search'): ?>
            <p class="alert alert--error" role="alert">Apple Music didn’t answer that search. Wait a minute and try again.</p>
        <?php endif ?>
        <?php if ($counts['unsearched'] > 0 && $scanning): ?>
            <div class="callout">
                <p><strong>Searching Apple Music now: <?= e($counts['unsearched']) ?> <?= $counts['unsearched'] === 1 ? 'song' : 'songs' ?> to go.</strong> Apple allows about 20 searches a minute. You can review what’s here already; reload to see more.</p>
                <a class="button button--ghost button--small" href="/scan">Watch progress</a>
            </div>
        <?php elseif ($counts['unsearched'] > 0): ?>
            <div class="callout">
                <p><strong><?= e($counts['unsearched']) ?> <?= $counts['unsearched'] === 1 ? 'song hasn’t' : 'songs haven’t' ?></strong> been searched for yet. Apple allows about 20 searches a minute, so this runs in the background.</p>
                <a class="button button--primary button--small" href="/scan">Run the scan</a>
            </div>
        <?php elseif ($total === 0): ?>
            <p class="empty">No songs to match yet. <a href="/scan">Run the scan</a> first.</p>
        <?php endif ?>

        <nav class="chips match-tabs" aria-label="Queues">
            <?php foreach (M::TAB_LABELS as $key => $label): ?>
                <a class="chip" href="<?= e($link(['tab' => $key, 'page' => 1, 'q' => ''])) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
                    <?= e($label) ?> <span class="chip__count"><?= e($counts[$key]) ?></span>
                </a>
            <?php endforeach ?>
        </nav>

        <form class="song-filters" method="get" action="/matches" role="search">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <label class="field field--grow">
                <span class="visually-hidden">Filter songs</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by song or artist">
            </label>
            <button class="button button--ghost button--small" type="submit">Filter</button>
            <?php if ($q !== ''): ?><a class="button button--ghost button--small" href="<?= e($link(['q' => '', 'page' => 1])) ?>">Clear</a><?php endif ?>
        </form>

        <?php if ($tab === 'auto' && $counts['auto'] > 0): ?>
            <div class="callout">
                <p>These are the same artist and title, studio versions. Scan them, send any wrong ones back with “Not right”, then confirm the rest.</p>
                <form method="post" action="/matches/approve-all">
                    <?= Csrf::field() ?>
                    <button class="button button--primary button--small" type="submit">Confirm all <?= e($counts['auto']) ?></button>
                </form>
            </div>
        <?php endif ?>

        <p class="muted small result-count"><?= e($result['total']) ?> <?= $result['total'] === 1 ? 'song' : 'songs' ?><?= $grid ? '' : ', most heard first' ?></p>

        <?php if ($result['songs'] === []): ?>
            <p class="empty"><?= $q !== '' ? 'No songs match that filter.' : 'Nothing here.' ?></p>
        <?php elseif ($grid): ?>
            <ol class="tiles">
                <?php foreach ($result['songs'] as $song): $pick = $song['pick']; $art = M::artwork($pick['artwork_url'] ?? null, 200); ?>
                    <li class="tile" id="song-<?= e($song['id']) ?>">
                        <a class="tile__link" href="/songs/<?= e($song['id']) ?>#apple-music">
                            <?php if ($art !== null): ?>
                                <img class="tile__art" src="<?= e($art) ?>" alt="" width="200" height="200" loading="lazy">
                            <?php else: ?>
                                <span class="tile__art art--empty" aria-hidden="true"></span>
                            <?php endif ?>
                            <span class="tile__title"><?= e($song['title_display']) ?></span>
                            <span class="tile__artist"><?= e($song['performer']) ?></span>
                            <?php if ($pick !== null && $pick['collection_name'] !== null): ?>
                                <span class="tile__album"><?= e($pick['collection_name']) ?></span>
                            <?php endif ?>
                        </a>
                        <?php if ($tab === 'auto'): ?>
                            <form method="post" action="/matches/<?= e($song['id']) ?>/reopen" class="js-match tile__action">
                                <?= Csrf::field() ?>
                                <?php foreach ($back as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endforeach ?>
                                <button class="button button--ghost button--small" type="submit">Not right</button>
                            </form>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php else: ?>
            <p class="keys muted small" aria-hidden="true">
                <kbd>j</kbd> <kbd>k</kbd> move · <kbd>1</kbd>–<kbd>5</kbd> pick · <kbd>enter</kbd> confirm · <kbd>x</kbd> leave off
            </p>
            <div class="match-queue" data-match-queue>
                <?php foreach ($result['songs'] as $song): ?>
                    <?= $view->partial('matches/_song', ['song' => $song, 'back' => $back, 'showSong' => true]) ?>
                <?php endforeach ?>
            </div>
        <?php endif ?>

        <?php if ($result['pages'] > 1): ?>
            <nav class="pager" aria-label="Pages">
                <?php if ($result['page'] > 1): ?><a class="button button--ghost button--small" href="<?= e($link(['page' => $result['page'] - 1])) ?>">Previous</a><?php endif ?>
                <span class="muted small">Page <?= e($result['page']) ?> of <?= e($result['pages']) ?></span>
                <?php if ($result['page'] < $result['pages']): ?><a class="button button--ghost button--small" href="<?= e($link(['page' => $result['page'] + 1])) ?>">Next</a><?php endif ?>
            </nav>
        <?php endif ?>

        <p class="muted small footnote">Recordings and artwork from the iTunes Search API, linked to Apple Music.</p>
    </div>
</section>
