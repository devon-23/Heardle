<?php
/**
 * @var array $filters @var array $stats @var list<array> $mostHeard @var list<array> $performers
 * @var array{songs: list<array>, total: int, pages: int, page: int} $result
 */
$shows = [
    'all' => 'All',
    'repeats' => 'Heard more than once',
    'openers' => 'From openers',
    'covers' => 'Covers',
    'review' => 'Medleys & partial',
    'excluded' => 'Excluded',
];
$sorts = ['heard' => 'Most heard', 'recent' => 'Most recent', 'title' => 'Title', 'artist' => 'Artist'];
// Builds a /songs link with these changes, leaving defaults out of the URL.
$query = function (array $changes) use ($filters): string {
    $q = $changes + $filters;
    $params = array_filter([
        'q' => $q['q'],
        'artist' => $q['artist'] > 0 ? $q['artist'] : null,
        'show' => $q['show'] !== 'all' ? $q['show'] : null,
        'sort' => $q['sort'] !== 'heard' ? $q['sort'] : null,
        'page' => $q['page'] > 1 ? $q['page'] : null,
    ], fn ($v) => $v !== null && $v !== '');

    return '/songs' . ($params === [] ? '' : '?' . http_build_query($params));
};
$year = fn (?string $date) => $date === null ? '' : substr($date, 0, 4);
?>
<section class="page">
    <div class="container">
        <p class="eyebrow">Step 4 · Your songs</p>
        <h1 class="page-title">Every song you’ve heard live</h1>
        <p class="lead">One entry per song, however many times you heard it. Open a song to see every show it came from.</p>

        <?php if ($stats['unextracted'] > 0): ?>
            <div class="callout">
                <p><strong><?= e($stats['unextracted']) ?> <?= $stats['unextracted'] === 1 ? 'setlist hasn’t' : 'setlists haven’t' ?></strong> been added to the library yet.</p>
                <a class="button button--primary button--small" href="/scan">Run the scan</a>
            </div>
        <?php endif ?>

        <dl class="stats">
            <div class="stat"><dt>Unique songs</dt><dd><?= e($stats['songs']) ?></dd></div>
            <div class="stat"><dt>Times heard</dt><dd><?= e($stats['hearings']) ?></dd></div>
            <div class="stat"><dt>Artists</dt><dd><?= e($stats['artists']) ?></dd></div>
            <div class="stat"><dt>Covers</dt><dd><?= e($stats['covers']) ?></dd></div>
        </dl>

        <?php if ($mostHeard !== []): ?>
            <h2 class="section-heading">Heard the most</h2>
            <ol class="top-songs">
                <?php foreach ($mostHeard as $i => $song): ?>
                    <li>
                        <a class="top-song" href="/songs/<?= e($song['id']) ?>">
                            <span class="top-song__rank"><?= $i + 1 ?></span>
                            <span class="top-song__title"><?= e($song['title_display']) ?></span>
                            <span class="top-song__artist"><?= e($song['performer']) ?></span>
                            <span class="top-song__count">×<?= e($song['heard']) ?></span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>

        <form class="song-filters" method="get" action="/songs" role="search">
            <input type="hidden" name="show" value="<?= e($filters['show']) ?>">
            <label class="field field--grow">
                <span class="visually-hidden">Search songs</span>
                <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search songs or artists">
            </label>
            <label class="field">
                <span class="visually-hidden">Artist</span>
                <select name="artist">
                    <option value="">All artists</option>
                    <?php foreach ($performers as $artist): ?>
                        <option value="<?= e($artist['id']) ?>"<?= $filters['artist'] === (int) $artist['id'] ? ' selected' : '' ?>><?= e($artist['name']) ?> (<?= e($artist['songs']) ?>)</option>
                    <?php endforeach ?>
                </select>
            </label>
            <label class="field">
                <span class="visually-hidden">Sort</span>
                <select name="sort">
                    <?php foreach ($sorts as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $filters['sort'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <button class="button button--ghost button--small" type="submit">Apply</button>
        </form>

        <nav class="chips song-chips" aria-label="Show">
            <?php foreach ($shows as $key => $label): ?>
                <a class="chip" href="<?= e($query(['show' => $key, 'page' => 1])) ?>"<?= $filters['show'] === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach ?>
        </nav>

        <p class="muted small result-count"><?= e($result['total']) ?> <?= $result['total'] === 1 ? 'song' : 'songs' ?></p>

        <?php if ($result['songs'] === []): ?>
            <p class="empty">No songs match.</p>
        <?php else: ?>
            <ol class="song-list">
                <?php foreach ($result['songs'] as $song): ?>
                    <li>
                        <a class="song-row" href="/songs/<?= e($song['id']) ?>">
                            <span class="song-row__text">
                                <span class="song-row__title"><?= e($song['title_display']) ?></span>
                                <span class="song-row__artist">
                                    <?= e($song['performer']) ?><?php if ($song['original_artist_name'] !== null): ?> · cover of <?= e($song['original_artist_name']) ?><?php endif ?>
                                </span>
                            </span>
                            <span class="song-row__flags">
                                <?php if ($song['medley']): ?><span class="badge badge--muted">from a medley</span><?php endif ?>
                                <?php if ($song['partial']): ?><span class="badge badge--muted">partial</span><?php endif ?>
                            </span>
                            <span class="song-row__year mono"><?= e($year($song['first_heard'])) ?></span>
                            <span class="heard-pill<?= $song['heard'] > 1 ? ' heard-pill--multi' : '' ?>">×<?= e($song['heard']) ?></span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>

        <?php if ($result['pages'] > 1): ?>
            <nav class="pager" aria-label="Pages">
                <?php if ($result['page'] > 1): ?><a class="button button--ghost button--small" href="<?= e($query(['page' => $result['page'] - 1])) ?>">Previous</a><?php endif ?>
                <span class="muted small">Page <?= e($result['page']) ?> of <?= e($result['pages']) ?></span>
                <?php if ($result['page'] < $result['pages']): ?><a class="button button--ghost button--small" href="<?= e($query(['page' => $result['page'] + 1])) ?>">Next</a><?php endif ?>
            </nav>
        <?php endif ?>

        <p class="muted small footnote">Song titles come from setlists on <a href="https://www.setlist.fm">setlist.fm</a>; each song’s page links to the setlists it came from.</p>
    </div>
</section>
