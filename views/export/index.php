<?php
/**
 * @var array{name: string, min: int, order: string, size: int} $options @var int $tracks @var int $minutes
 * @var list<array{part: int, from: int, to: int}> $parts @var array<string, int> $counts @var int $missing
 */
$download = fn (array $changes) => '/export/playlist.csv?' . http_build_query($changes + $options);
$waiting = $counts['review'] + $counts['unsearched'];
$hours = intdiv($minutes, 60);
$length = $hours > 0 ? $hours . 'h ' . ($minutes % 60) . 'm' : $minutes . 'm';
$minLabels = [1 => 'Every song', 2 => 'Heard 2+ times', 3 => 'Heard 3+ times', 5 => 'Heard 5+ times'];
$sizeLabels = [200 => '200 (Soundiiz free plan)', 500 => '500', 0 => 'All in one file'];
?>
<section class="page">
    <div class="container">
        <p class="eyebrow">Step 6 · Export</p>
        <h1 class="page-title">Make the playlist</h1>
        <p class="lead">Download your songs as a CSV, then let a transfer service build the playlist in your Apple Music library. The file uses Apple Music’s own titles and artist names, so the service finds the same recordings.</p>

        <?php if ($waiting > 0): ?>
            <div class="callout">
                <p><strong><?= e($waiting) ?> <?= $waiting === 1 ? 'song isn’t' : 'songs aren’t' ?></strong> on the playlist yet: <?= $counts['review'] > 0 ? e($counts['review']) . ' waiting for your review' : '' ?><?= $counts['review'] > 0 && $counts['unsearched'] > 0 ? ', ' : '' ?><?= $counts['unsearched'] > 0 ? e($counts['unsearched']) . ' not searched for yet' : '' ?>.</p>
                <a class="button button--ghost button--small" href="/matches">Review matches</a>
            </div>
        <?php endif ?>
        <?php if ($counts['auto'] > 0): ?>
            <p class="muted small export-note">Includes <?= e(number_format($counts['auto'])) ?> automatic <?= $counts['auto'] === 1 ? 'match' : 'matches' ?> you haven’t confirmed. <a href="/matches?tab=auto">Check them</a> if you like; they’re the same artist and title, studio versions.</p>
        <?php endif ?>

        <dl class="stats">
            <div class="stat"><dt>Songs on the playlist</dt><dd><?= e(number_format($tracks)) ?></dd></div>
            <div class="stat"><dt>Length</dt><dd><?= e($length) ?></dd></div>
            <div class="stat"><dt><?= count($parts) === 1 ? 'File' : 'Files' ?></dt><dd><?= e(count($parts)) ?></dd></div>
            <div class="stat stat--muted"><dt>Not on the playlist</dt><dd><?= e(number_format($missing)) ?></dd></div>
        </dl>

        <form class="export-options" method="get" action="/export">
            <label class="field field--grow">
                <span class="field__label">Playlist name</span>
                <input type="text" name="name" value="<?= e($options['name']) ?>" maxlength="80">
            </label>
            <label class="field">
                <span class="field__label">Songs</span>
                <select name="min">
                    <?php foreach ($minLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $options['min'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <label class="field">
                <span class="field__label">Order</span>
                <select name="order">
                    <option value="first"<?= $options['order'] === 'first' ? ' selected' : '' ?>>When you first heard them</option>
                    <option value="heard"<?= $options['order'] === 'heard' ? ' selected' : '' ?>>Most heard first</option>
                </select>
            </label>
            <label class="field">
                <span class="field__label">Songs per file</span>
                <select name="size">
                    <?php foreach ($sizeLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $options['size'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <button class="button button--ghost button--small" type="submit">Update</button>
        </form>

        <h2 class="section-heading">Download</h2>
        <?php if ($parts === []): ?>
            <p class="empty">No songs with an Apple Music recording yet. <a href="/matches">Match your songs</a> first.</p>
        <?php else: ?>
            <ol class="parts">
                <?php foreach ($parts as $part): ?>
                    <li class="part">
                        <span class="part__name"><?= e($options['name']) ?><?= count($parts) > 1 ? ' (' . e($part['part']) . ' of ' . e(count($parts)) . ')' : '' ?></span>
                        <span class="part__range mono">songs <?= e($part['from']) ?>–<?= e($part['to']) ?></span>
                        <a class="button button--primary button--small part__button" href="<?= e($download(['part' => $part['part'], 'format' => 'transfer'])) ?>" download>Download CSV</a>
                    </li>
                <?php endforeach ?>
            </ol>
            <p class="downloads muted small">
                Also: <a href="<?= e($download(['size' => 0, 'part' => 1, 'format' => 'details'])) ?>" download>everything in one spreadsheet</a>
                (times heard, first and last heard, who played it) ·
                <a href="/export/missing.csv" download>the <?= e(number_format($missing)) ?> <?= $missing === 1 ? 'song' : 'songs' ?> not on the playlist</a>, with why.
            </p>
        <?php endif ?>

        <h2 class="section-heading">Import into Apple Music</h2>
        <div class="rules">
            <article class="rule">
                <h3 class="rule__title">Soundiiz</h3>
                <ol class="howto-steps">
                    <li>Sign in at <a href="https://soundiiz.com" target="_blank" rel="noopener">soundiiz.com</a> and connect Apple Music.</li>
                    <li>Choose <strong>Import playlist</strong> → <strong>From file</strong> and upload one part.</li>
                    <li>Check the songs, name the playlist, and import it.</li>
                    <li>Transfer that playlist to Apple Music. Repeat for each part.</li>
                </ol>
                <p class="small">Free plan: up to 200 songs per playlist, one playlist at a time.</p>
            </article>
            <article class="rule">
                <h3 class="rule__title">TuneMyMusic</h3>
                <ol class="howto-steps">
                    <li>Open <a href="https://www.tunemymusic.com/transfer/csv-to-apple-music" target="_blank" rel="noopener">CSV to Apple Music</a> on tunemymusic.com.</li>
                    <li>Choose <strong>Upload file</strong> as the source and upload a part.</li>
                    <li>Pick Apple Music as the destination and sign in there.</li>
                    <li>Start the transfer; its report lists anything it couldn’t find.</li>
                </ol>
                <p class="small">Its free limit isn’t published; if it stops partway, use smaller files.</p>
            </article>
            <article class="rule">
                <h3 class="rule__title">If songs go missing</h3>
                <p>Services search Apple Music again from the title, artist and album, so now and then they pick a different version or miss one. Each row also carries the Apple Music link, so you can add a straggler by hand.</p>
            </article>
        </div>

        <p class="muted small footnote">Files are UTF-8 CSV. Column names follow Soundiiz’s sample playlist file.</p>
    </div>
</section>
