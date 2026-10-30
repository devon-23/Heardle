<section class="hero">
    <div class="container hero__inner">
        <div class="hero__copy">
            <p class="eyebrow">Your concert history, as a playlist</p>
            <h1 class="display">Every song you’ve heard live.</h1>
            <p class="lead">
                Upload your Concert Archives history. Heard Live finds the setlist for every
                performer you actually watched, folds repeat songs into one, and turns the
                whole thing into a playlist you can take to Apple Music.
            </p>
            <div class="actions">
                <?php if (!empty($hasEvents)): ?>
                    <a class="button button--primary" href="/concerts">Review your concerts</a>
                    <a class="button button--ghost" href="/import">Import again</a>
                <?php else: ?>
                    <a class="button button--primary" href="/import">Import your concerts</a>
                    <a class="button button--ghost" href="#how-it-works">How it works</a>
                <?php endif ?>
            </div>
        </div>

        <figure class="ticket" aria-label="Example: a show where you saw the headliner and the first opener, but missed the second">
            <div class="ticket__main">
                <p class="ticket__meta">Admit one · Sat 04.18 · 7:30 PM</p>
                <p class="ticket__headliner">The Midnight Arcade</p>
                <p class="ticket__venue">Harbor Pavilion · Portland, OR</p>
                <ul class="lineup">
                    <li class="lineup__item">
                        <span class="lineup__mark" aria-hidden="true">✓</span>
                        <span class="lineup__name">The Midnight Arcade</span>
                        <span class="lineup__note">21 songs</span>
                    </li>
                    <li class="lineup__item">
                        <span class="lineup__mark" aria-hidden="true">✓</span>
                        <span class="lineup__name">Paper Satellites</span>
                        <span class="lineup__note">9 songs</span>
                    </li>
                    <li class="lineup__item lineup__item--missed">
                        <span class="lineup__mark" aria-hidden="true">–</span>
                        <span class="lineup__name">Low Tide Choir</span>
                        <span class="lineup__note">missed it</span>
                    </li>
                </ul>
            </div>
            <div class="ticket__stub" aria-hidden="true">
                <span class="ticket__count">30</span>
                <span class="ticket__count-label">songs heard</span>
            </div>
            <figcaption class="visually-hidden">Songs from the two performers you saw count. The opener you missed doesn’t.</figcaption>
        </figure>
    </div>
</section>

<section class="section" id="how-it-works" aria-labelledby="how-title">
    <div class="container">
        <h2 class="section__title" id="how-title">How it works</h2>
        <ol class="steps">
            <li class="step">
                <span class="step__num">01</span>
                <h3 class="step__title">Export your history</h3>
                <p>In Concert Archives, choose Export To Spreadsheet from your user menu, then upload the CSV here.</p>
            </li>
            <li class="step">
                <span class="step__num">02</span>
                <h3 class="step__title">Find the setlists</h3>
                <p>Each performer you saw is matched to their setlist.fm setlist for that exact date and venue.</p>
            </li>
            <li class="step">
                <span class="step__num">03</span>
                <h3 class="step__title">Confirm what you saw</h3>
                <p>Your “bands seen” list is filled in already. You only weigh in where something’s unclear.</p>
            </li>
            <li class="step">
                <span class="step__num">04</span>
                <h3 class="step__title">Take it to Apple Music</h3>
                <p>Review the recordings, then export the list for a transfer service to build the playlist.</p>
            </li>
        </ol>
    </div>
</section>

<section class="section section--rules" aria-labelledby="rules-title">
    <div class="container">
        <h2 class="section__title" id="rules-title">Ground rules</h2>
        <div class="rules">
            <div class="rule">
                <h3 class="rule__title">Only who you saw</h3>
                <p>Openers you missed stay off the playlist, even if their setlist is right there.</p>
            </div>
            <div class="rule">
                <h3 class="rule__title">Nothing invented</h3>
                <p>No setlist for a show? It’s listed as missing. Another night’s setlist never stands in.</p>
            </div>
            <div class="rule">
                <h3 class="rule__title">One song, every show</h3>
                <p>Heard it eight times? It’s on the playlist once, and all eight nights are remembered.</p>
            </div>
        </div>
    </div>
</section>
