<?php
/**
 * One song's Apple Music match: the current pick, the alternatives, and the actions.
 * @var array $song row from MatchRepository (id, title_display, performer, original_artist_name, status, pick, candidates, …)
 * @var array<string, string|int> $back hidden fields that bring a non-JS form back here (tab, q, page or from=song)
 * @var bool $showSong whether to show the song's title (false on the song's own page)
 */
use HeardLive\Http\Csrf;
use HeardLive\Matching\MatchPresenter as M;

$id = (int) $song['id'];
$hidden = function () use ($back): string {
    $html = Csrf::field();
    foreach ($back as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }
    return $html;
};
$pickId = $song['pick'] !== null ? (int) $song['pick']['id'] : null;
$decided = $song['status'] === 'chosen';
?>
<article class="match" id="song-<?= e($id) ?>" data-match tabindex="-1" aria-label="<?= e($song['title_display'] . ' by ' . $song['performer']) ?>">
    <?php if ($showSong): ?>
        <header class="match__head">
            <h3 class="match__title"><a href="/songs/<?= e($id) ?>"><?= e($song['title_display']) ?></a></h3>
            <p class="match__meta">
                <?= e($song['performer']) ?><?php if ($song['original_artist_name'] !== null): ?> · cover of <?= e($song['original_artist_name']) ?><?php endif ?>
                <?php if (isset($song['heard'])): ?> · <span class="mono">heard ×<?= e($song['heard']) ?></span><?php endif ?>
            </p>
        </header>
    <?php endif ?>

    <p class="match__status small" data-match-status><?= e($song['excluded'] ? 'Left off the playlist' : M::statusText($song['status'])) ?></p>

    <?php if ($song['candidates'] === []): ?>
        <p class="match__none muted">Apple Music has nothing under this title by <?= e($song['performer']) ?>. Try your own search below.</p>
    <?php else: ?>
        <ol class="options">
            <?php foreach ($song['candidates'] as $i => $c):
                $url = M::appleMusicUrl($c['view_url']);
                $art = M::artwork($c['artwork_url']);
                $isPick = (int) $c['id'] === $pickId;
            ?>
                <li class="option<?= $isPick ? ' is-pick' : '' ?>">
                    <form method="post" action="/matches/<?= e($id) ?>/choose" class="option__form js-match">
                        <?= $hidden() ?>
                        <input type="hidden" name="recording" value="<?= e($c['id']) ?>">
                        <button class="option__pick" type="submit" data-pick="<?= $i + 1 ?>"<?= $isPick && $decided ? ' aria-pressed="true"' : '' ?>
                                aria-label="Use <?= e($c['track_name'] . ' by ' . $c['artist_name']) ?>">
                            <span class="option__key mono" aria-hidden="true"><?= $i + 1 ?></span>
                        </button>
                    </form>
                    <?php if ($art !== null): ?>
                        <img class="art" src="<?= e($art) ?>" alt="" width="56" height="56" loading="lazy">
                    <?php else: ?>
                        <span class="art art--empty" aria-hidden="true"></span>
                    <?php endif ?>
                    <div class="option__text">
                        <p class="option__track"><?= e($c['track_name']) ?></p>
                        <p class="option__album muted"><?= e($c['artist_name']) ?> · <?= e($c['collection_name'] ?? '') ?> <span class="mono"><?= e(M::year($c['release_date'])) ?></span></p>
                        <ul class="badges">
                            <?php if ($isPick): ?><li class="badge badge--ok"><?= $decided ? 'your pick' : 'best match' ?></li><?php endif ?>
                            <?php foreach (M::badges($c, $song['original_artist_name']) as $badge): ?>
                                <li class="badge badge--muted"><?= e($badge) ?></li>
                            <?php endforeach ?>
                        </ul>
                    </div>
                    <?php if ($url !== null): ?>
                        <a class="option__link" href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="Open <?= e($c['track_name']) ?> in Apple Music">Apple&nbsp;Music&nbsp;↗</a>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>

    <div class="match__actions">
        <?php if ($pickId !== null && !$decided && !$song['excluded']): ?>
            <form method="post" action="/matches/<?= e($id) ?>/accept" class="js-match">
                <?= $hidden() ?>
                <button class="button button--primary button--small" type="submit" data-action="accept">Confirm pick</button>
            </form>
        <?php endif ?>
        <?php if (in_array($song['status'], ['auto', 'chosen'], true) && !$song['excluded']): ?>
            <form method="post" action="/matches/<?= e($id) ?>/reopen" class="js-match">
                <?= $hidden() ?>
                <button class="button button--ghost button--small" type="submit">Not right</button>
            </form>
        <?php endif ?>
        <form method="post" action="/matches/<?= e($id) ?>/exclude" class="js-match">
            <?= $hidden() ?>
            <input type="hidden" name="excluded" value="<?= $song['excluded'] ? '0' : '1' ?>">
            <button class="button button--ghost button--small" type="submit" data-action="exclude"><?= $song['excluded'] ? 'Put back on the playlist' : 'Leave off the playlist' ?></button>
        </form>
        <form method="post" action="/matches/<?= e($id) ?>/search" class="match__search">
            <?= $hidden() ?>
            <label class="field field--grow">
                <span class="visually-hidden">Search Apple Music for</span>
                <input type="search" name="terms" value="<?= e($song['search_terms'] ?? '') ?>" placeholder="<?= e($song['performer'] . ' ' . $song['title_display']) ?>">
            </label>
            <button class="button button--ghost button--small" type="submit">Search again</button>
        </form>
    </div>
</article>
