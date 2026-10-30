<?php /** @var ?string $error @var bool $hasEvents */ use HeardLive\Http\Csrf; ?>
<section class="page">
    <div class="container narrow">
        <p class="eyebrow">Step 1 · Import</p>
        <h1 class="page-title">Import your concerts</h1>
        <p class="lead">Upload the CSV from Concert Archives. You’ll see a summary before anything is saved.</p>

        <?php if ($error !== null): ?>
            <p class="alert alert--error" role="alert"><?= e($error) ?></p>
        <?php endif ?>

        <?php if ($hasEvents): ?>
            <p class="alert">You’ve imported before. Uploading a newer export updates your concerts and keeps any attendance changes you made here.</p>
        <?php endif ?>

        <form class="upload" method="post" action="/import" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <label class="dropzone" data-dropzone>
                <input class="dropzone__input" type="file" name="csv" accept=".csv,text/csv" required data-file-input>
                <span class="dropzone__title">Drop your CSV here</span>
                <span class="dropzone__hint">or click to choose a file · up to 5 MB</span>
                <span class="dropzone__file" data-file-name hidden></span>
            </label>
            <button class="button button--primary" type="submit">Check this file</button>
        </form>

        <details class="howto">
            <summary>Where do I get the CSV?</summary>
            <ol>
                <li>Sign in at <a href="https://www.concertarchives.org" rel="noopener" target="_blank">concertarchives.org</a>.</li>
                <li>Open your user menu and choose <strong>Export To Spreadsheet</strong>.</li>
                <li>Download the file Concert Archives gives you (it may arrive by email), then upload it here.</li>
            </ol>
        </details>
    </div>
</section>
