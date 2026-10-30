<?php
/** @var array $event @var string $filter */
use HeardLive\Concerts\EventPresenter;
use HeardLive\Concerts\Flags;
use HeardLive\Http\Csrf;

$id = (int) $event['id'];
$performances = $event['performances'];
$listedSeen = array_values(array_filter($performances, fn ($p) => $p['listed_as'] === 'seen'));
$listedNotSeen = array_values(array_filter($performances, fn ($p) => $p['listed_as'] === 'not_seen'));
$attended = count(array_filter($performances, fn ($p) => $p['attended']));
$subtitle = EventPresenter::subtitle($event);

$row = function (array $p): string {
    $status = EventPresenter::setlistStatus($p);
    ob_start(); ?>
    <li class="lineup__item">
        <label class="check">
            <input type="checkbox" name="attended[]" value="<?= e($p['id']) ?>"<?= $p['attended'] ? ' checked' : '' ?>>
            <span class="check__box" aria-hidden="true"></span>
            <span class="lineup__name"><?= e($p['artist']) ?></span>
        </label>
        <span class="lineup__meta">
            <span class="lineup__note" data-note-for="<?= e($p['id']) ?>"><?= $p['attended_source'] === 'user' ? 'edited' : '' ?></span>
            <?php if ($status !== null): ?>
                <?php if ($status['url'] !== null): ?>
                    <a class="setlist-chip setlist-chip--<?= e($status['tone']) ?>" href="<?= e($status['url']) ?>" target="_blank" rel="noopener" title="Setlist on setlist.fm"><?= e($status['text']) ?>&nbsp;↗</a>
                <?php else: ?>
                    <span class="setlist-chip setlist-chip--<?= e($status['tone']) ?>"><?= e($status['text']) ?></span>
                <?php endif ?>
            <?php endif ?>
        </span>
    </li>
    <?php return (string) ob_get_clean();
};
?>
<article class="event<?= $event['needs_attention'] ? ' event--attention' : '' ?>" id="event-<?= e($id) ?>">
    <div class="event__main">
        <p class="event__date"><?= e(EventPresenter::date($event)) ?></p>
        <h3 class="event__title"><?= e(EventPresenter::title($event)) ?></h3>
        <p class="event__place">
            <?php if ($subtitle !== null): ?><span class="event__tour"><?= e($subtitle) ?></span> · <?php endif ?>
            <?= e(EventPresenter::place($event)) ?>
        </p>

        <?php if ($event['flags'] !== []): ?>
            <ul class="badges">
                <?php foreach ($event['flags'] as $flag): ?>
                    <li class="badge badge--warn" title="<?= e(Flags::label($flag, $event)) ?>"><?= e(Flags::short($flag)) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <form class="lineup-form js-attendance" method="post" action="/events/<?= e($id) ?>/attendance" aria-label="Who you saw">
            <?= Csrf::field() ?>
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <?php if ($listedSeen !== []): ?>
                <ul class="lineup"><?php foreach ($listedSeen as $p) { echo $row($p); } ?></ul>
            <?php endif ?>

            <?php if (count($listedNotSeen) > 3): ?>
                <details class="lineup-more">
                    <summary><?= count($listedNotSeen) ?> more on the lineup you didn’t see</summary>
                    <ul class="lineup lineup--muted"><?php foreach ($listedNotSeen as $p) { echo $row($p); } ?></ul>
                </details>
            <?php elseif ($listedNotSeen !== []): ?>
                <p class="lineup-label">Listed, not seen</p>
                <ul class="lineup lineup--muted"><?php foreach ($listedNotSeen as $p) { echo $row($p); } ?></ul>
            <?php endif ?>

            <div class="lineup-actions">
                <button class="button button--small button--ghost js-hide" type="submit">Save</button>
                <p class="lineup-status" role="status" aria-live="polite"></p>
            </div>
        </form>

        <?php foreach ($event['choices'] as $p): ?>
            <?php if (in_array($p['artist_resolution'], ['ambiguous', 'unresolved'], true)): ?>
                <form class="choice" method="post" action="/artists/<?= e($p['artist_id']) ?>/choose">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="filter" value="<?= e($filter) ?>">
                    <input type="hidden" name="event" value="<?= e($id) ?>">
                    <fieldset>
                        <?php if ($p['artist_resolution'] === 'ambiguous'): ?>
                            <legend class="choice__title">Which <?= e($p['artist']) ?> did you see?</legend>
                            <p class="choice__why">setlist.fm has more than one artist with this name. Your answer applies to every show with them.</p>
                        <?php else: ?>
                            <legend class="choice__title">Is “<?= e($p['artist']) ?>” listed under another name?</legend>
                            <p class="choice__why">setlist.fm has no exact match, but these are close. Your answer applies to every show with them.</p>
                        <?php endif ?>
                        <?php foreach ($p['artist_candidates'] as $c): ?>
                            <label class="choice__option">
                                <input type="radio" name="mbid" value="<?= e($c['mbid']) ?>" required>
                                <span class="choice__text">
                                    <strong><?= e($c['name']) ?></strong>
                                    <?php if ($c['disambiguation'] !== null): ?><span class="muted"> · <?= e($c['disambiguation']) ?></span><?php endif ?>
                                    <?php if (EventPresenter::setlistFmUrl($c['url']) !== null): ?> · <a href="<?= e($c['url']) ?>" target="_blank" rel="noopener">setlist.fm&nbsp;↗</a><?php endif ?>
                                </span>
                            </label>
                        <?php endforeach ?>
                        <label class="choice__option">
                            <input type="radio" name="mbid" value="none" required>
                            <span class="choice__text">None of these</span>
                        </label>
                    </fieldset>
                    <button class="button button--small button--primary" type="submit">Save choice</button>
                </form>
            <?php else: ?>
                <form class="choice" method="post" action="/performances/<?= e($p['id']) ?>/setlist">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="filter" value="<?= e($filter) ?>">
                    <input type="hidden" name="event" value="<?= e($id) ?>">
                    <fieldset>
                        <legend class="choice__title">Setlist for <?= e($p['artist']) ?></legend>
                        <p class="choice__why"><?= e(EventPresenter::choiceReason($p)) ?></p>
                        <?php foreach ($p['setlist_candidates'] as $c): ?>
                            <label class="choice__option">
                                <input type="radio" name="setlist" value="<?= e($c['id']) ?>" required>
                                <span class="choice__text">
                                    <strong><?= e(EventPresenter::shortDate($c['date'])) ?></strong>
                                    · <?= e(implode(', ', array_filter([$c['venue'], $c['city']]))) ?>
                                    · <?= e($c['song_count']) ?> <?= $c['song_count'] === 1 ? 'song' : 'songs' ?>
                                    <?php if ($c['first_songs'] !== []): ?>
                                        <span class="choice__songs">Opens with <?= e(implode(', ', $c['first_songs'])) ?></span>
                                    <?php endif ?>
                                    <?php if (EventPresenter::setlistFmUrl($c['url']) !== null): ?><a href="<?= e($c['url']) ?>" target="_blank" rel="noopener">View on setlist.fm&nbsp;↗</a><?php endif ?>
                                </span>
                            </label>
                        <?php endforeach ?>
                        <label class="choice__option">
                            <input type="radio" name="setlist" value="none" required>
                            <span class="choice__text">None of these is my show</span>
                        </label>
                    </fieldset>
                    <button class="button button--small button--primary" type="submit">Save choice</button>
                </form>
            <?php endif ?>
        <?php endforeach ?>
    </div>

    <div class="event__stub">
        <p class="event__count"><span data-attended><?= e($attended) ?></span><span class="event__count-of">/<?= count($performances) ?></span></p>
        <p class="event__count-label">acts seen</p>
        <?php if ($event['flags_pending']): ?>
            <form class="js-review" method="post" action="/events/<?= e($id) ?>/review">
                <?= Csrf::field() ?>
                <input type="hidden" name="filter" value="<?= e($filter) ?>">
                <button class="button button--small button--primary" type="submit">Looks right</button>
            </form>
        <?php endif ?>
        <?php if (preg_match('#^https://(www\.)?concertarchives\.org/#', $event['ca_url'])): ?>
            <a class="event__link" href="<?= e($event['ca_url']) ?>" rel="noopener noreferrer" target="_blank">Concert Archives&nbsp;↗</a>
        <?php endif ?>
    </div>
</article>
