<?php
/**
 * @var array $summary @var list<array> $mostHeard @var list<array> $topArtists @var list<array> $openers
 * @var array $covers @var list<array> $nights @var array $caughtOnce @var array $coverage
 */

$n = fn (int $value) => number_format($value);
$plural = fn (int $count, string $one, string $many) => $n($count) . ' ' . ($count === 1 ? $one : $many);
$date = fn (?string $d) => $d === null ? '' : (new DateTimeImmutable($d))->format('M j, Y');
$year = fn (?string $d) => $d === null ? '' : substr($d, 0, 4);
$hours = (int) round($summary['playlist_ms'] / 3_600_000);
$coveragePct = $coverage['sets'] > 0 ? (int) round($coverage['matched'] / $coverage['sets'] * 100) : 0;
$statusLabels = ['missing' => 'no setlist on setlist.fm', 'empty' => 'setlist has no songs', 'ambiguous' => 'needs you to pick a setlist',
    'pending' => 'not looked up yet', 'error' => 'lookup failed'];
$topMax = max([1, ...array_column($topArtists, 'songs')]);
$openerMax = max([1, ...array_column($openers, 'songs')]);
?>
<section class="page">
    <div class="container">
        <p class="eyebrow">Stats</p>

        <?php if ($summary['songs'] === 0): ?>
            <h1 class="page-title">Your live history</h1>
            <p class="empty">No songs yet. <a href="/import">Import your concerts</a> and run the scan, and the numbers show up here.</p>
        <?php else: ?>
            <div class="stats-hero">
                <p class="stats-hero__number"><?= e($n($summary['songs'])) ?></p>
                <h1 class="stats-hero__label">songs heard live</h1>
                <p class="lead">From <?= e($plural($summary['artists'], 'artist', 'artists')) ?> across <?= e($plural($summary['concerts'], 'concert', 'concerts')) ?>, <?= e($year($summary['first_show'])) ?>–<?= e($year($summary['last_show'])) ?>.</p>
            </div>

            <dl class="stats">
                <div class="stat"><dt>Times heard</dt><dd><?= e($n($summary['hearings'])) ?></dd></div>
                <div class="stat"><dt>Covers</dt><dd><?= e($n($summary['covers'])) ?></dd></div>
                <div class="stat"><dt>From supporting sets</dt><dd><?= e($n($summary['opener_songs'])) ?></dd></div>
                <div class="stat"><dt>Playlist</dt><dd><?= e($n($summary['playlist'])) ?><span class="stat__unit"> · <?= e($hours) ?> h</span></dd></div>
            </dl>

            <div class="stats-grid">
                <section class="panel panel--wide" aria-labelledby="most-heard">
                    <h2 class="panel__title" id="most-heard">Heard the most</h2>
                    <?php if ($mostHeard === []): ?>
                        <p class="muted">You haven’t heard any song twice yet.</p>
                    <?php else: ?>
                        <ol class="ranked">
                            <?php foreach ($mostHeard as $i => $song): ?>
                                <li class="ranked__item">
                                    <span class="ranked__rank mono"><?= $i + 1 ?></span>
                                    <div class="ranked__text">
                                        <a class="ranked__title" href="/songs/<?= e($song['id']) ?>"><?= e($song['title_display']) ?></a>
                                        <span class="ranked__sub"><?= e($song['performer']) ?><?= $song['original_artist_name'] !== null ? ' · cover of ' . e($song['original_artist_name']) : '' ?></span>
                                        <details class="shows">
                                            <summary>The <?= e($plural(count($song['shows']), 'show', 'shows')) ?></summary>
                                            <ol class="shows__list">
                                                <?php foreach ($song['shows'] as $show): ?>
                                                    <li><span class="mono"><?= e($date($show['start_date'])) ?></span> <a href="/concerts#event-<?= e($show['event_id']) ?>"><?= e($show['event'] ?? 'Show') ?></a><?= $show['city'] !== null ? ', ' . e($show['city']) : '' ?></li>
                                                <?php endforeach ?>
                                            </ol>
                                        </details>
                                    </div>
                                    <span class="ranked__count">×<?= e($song['heard']) ?></span>
                                </li>
                            <?php endforeach ?>
                        </ol>
                    <?php endif ?>
                </section>

                <section class="panel" aria-labelledby="top-artists">
                    <h2 class="panel__title" id="top-artists">Most songs from one artist</h2>
                    <ol class="bars">
                        <?php foreach ($topArtists as $artist): ?>
                            <li class="bar">
                                <a class="bar__name" href="/songs?artist=<?= e($artist['id']) ?>"><?= e($artist['name']) ?></a>
                                <span class="bar__value mono"><?= e($artist['songs']) ?></span>
                                <progress class="bar__track" max="<?= e($topMax) ?>" value="<?= e($artist['songs']) ?>" aria-hidden="true"></progress>
                                <span class="bar__note"><?= e($plural((int) $artist['sets'], 'set', 'sets')) ?> seen</span>
                            </li>
                        <?php endforeach ?>
                    </ol>
                    <p class="panel__foot small muted">Different songs heard from each, all shows together.</p>
                </section>

                <section class="panel" aria-labelledby="nights">
                    <h2 class="panel__title" id="nights">Biggest nights</h2>
                    <ol class="nights">
                        <?php foreach ($nights as $night): ?>
                            <li class="night">
                                <span class="night__date mono"><?= e($date($night['start_date'])) ?></span>
                                <a class="night__event" href="/concerts#event-<?= e($night['id']) ?>"><?= e($night['event'] ?? 'Show') ?></a>
                                <span class="night__meta muted"><?= e($plural((int) $night['songs'], 'song', 'songs')) ?> · <?= e($plural((int) $night['acts'], 'act', 'acts')) ?><?= $night['city'] !== null ? ' · ' . e($night['city']) : '' ?></span>
                            </li>
                        <?php endforeach ?>
                    </ol>
                </section>

                <section class="panel" aria-labelledby="caught-once">
                    <h2 class="panel__title" id="caught-once">Caught only once</h2>
                    <?php if ($caughtOnce['total'] === 0): ?>
                        <p class="muted">Nothing yet: this lists songs you heard once from artists you’ve seen at least three times.</p>
                    <?php else: ?>
                        <p class="panel__lead"><?= e($plural($caughtOnce['total'], 'song', 'songs')) ?> you heard just once from artists you’ve seen three or more times.</p>
                        <ul class="plain-list">
                            <?php foreach ($caughtOnce['songs'] as $song): ?>
                                <li><a href="/songs/<?= e($song['id']) ?>"><?= e($song['title_display']) ?></a> <span class="muted">· <?= e($song['performer']) ?> · <?= e($date($song['heard_on'])) ?></span></li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                    <p class="panel__foot small muted">Not rarity: that needs each artist’s full setlist history, which comes later.</p>
                </section>

                <section class="panel" aria-labelledby="covers">
                    <h2 class="panel__title" id="covers">Covers you’ve heard</h2>
                    <?php if ($covers['total'] === 0): ?>
                        <p class="muted">No covers yet.</p>
                    <?php else: ?>
                        <p class="panel__lead"><?= e($plural($covers['total'], 'cover', 'covers')) ?><?php if ($covers['originals'] !== []): ?>. Most covered: <?= e($covers['originals'][0]['name']) ?> (<?= e($covers['originals'][0]['covers']) ?>)<?php endif ?>.</p>
                        <ul class="plain-list">
                            <?php foreach ($covers['songs'] as $song): ?>
                                <li><a href="/songs/<?= e($song['id']) ?>"><?= e($song['title_display']) ?></a> <span class="muted">· <?= e($song['performer']) ?>, original by <?= e($song['original_artist_name']) ?><?= $song['heard'] > 1 ? ' · ×' . e($song['heard']) : '' ?></span></li>
                            <?php endforeach ?>
                        </ul>
                        <a class="panel__more small" href="/songs?show=covers">All covers →</a>
                    <?php endif ?>
                </section>

                <section class="panel" aria-labelledby="openers">
                    <h2 class="panel__title" id="openers">From supporting sets</h2>
                    <?php if ($openers === []): ?>
                        <p class="muted">None found.</p>
                    <?php else: ?>
                        <p class="panel__lead"><?= e($plural($summary['opener_songs'], 'song', 'songs')) ?> you’ve only heard in a supporting set.</p>
                        <ol class="bars">
                            <?php foreach ($openers as $artist): ?>
                                <li class="bar">
                                    <a class="bar__name" href="/songs?artist=<?= e($artist['id']) ?>"><?= e($artist['name']) ?></a>
                                    <span class="bar__value mono"><?= e($artist['songs']) ?></span>
                                    <progress class="bar__track" max="<?= e($openerMax) ?>" value="<?= e($artist['songs']) ?>" aria-hidden="true"></progress>
                                </li>
                            <?php endforeach ?>
                        </ol>
                        <a class="panel__more small" href="/songs?show=openers">All of them →</a>
                    <?php endif ?>
                    <p class="panel__foot small muted">Probable: a supporting set is one shorter than the longest setlist that night. The CSV doesn’t say who headlined.</p>
                </section>

                <section class="panel" aria-labelledby="coverage">
                    <h2 class="panel__title" id="coverage">Setlist coverage</h2>
                    <p class="coverage__number"><?= e($coveragePct) ?>%</p>
                    <progress class="bar__track coverage__bar" max="<?= e(max(1, $coverage['sets'])) ?>" value="<?= e($coverage['matched']) ?>" aria-label="Sets with a setlist"></progress>
                    <p class="panel__lead"><?= e($n($coverage['matched'])) ?> of the <?= e($plural($coverage['sets'], 'set', 'sets')) ?> you saw have a setlist, so their songs count.</p>
                    <ul class="plain-list">
                        <?php foreach ($statusLabels as $status => $label): if (($coverage['by_status'][$status] ?? 0) === 0) continue; ?>
                            <li><span class="mono"><?= e($coverage['by_status'][$status]) ?></span> <?= e($label) ?></li>
                        <?php endforeach ?>
                    </ul>
                    <?php if ($coverage['missing'] !== []): ?>
                        <details class="shows">
                            <summary>Most recent without songs</summary>
                            <ol class="shows__list">
                                <?php foreach ($coverage['missing'] as $set): ?>
                                    <li><span class="mono"><?= e($date($set['start_date'])) ?></span> <?= e($set['artist']) ?> <span class="muted">· <a href="/concerts#event-<?= e($set['event_id']) ?>"><?= e($set['event'] ?? 'Show') ?></a></span></li>
                                <?php endforeach ?>
                            </ol>
                        </details>
                    <?php endif ?>
                    <a class="panel__more small" href="/scan">Setlists →</a>
                </section>
            </div>
        <?php endif ?>

        <p class="muted small footnote">Setlists from <a href="https://www.setlist.fm">setlist.fm</a>, which doesn’t have every show. Playlist length uses the studio recordings.</p>
    </div>
</section>
