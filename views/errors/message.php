<?php /** @var string $title @var string $message */ ?>
<section class="section">
    <div class="container message">
        <h1 class="message__title"><?= e($title) ?></h1>
        <p class="lead"><?= e($message) ?></p>
        <a class="button button--ghost" href="/">Back to start</a>
    </div>
</section>
