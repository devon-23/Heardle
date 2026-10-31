<?php /** @var string $title @var string $content */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$nav = ['/import' => 'Import', '/concerts' => 'Concerts', '/scan' => 'Setlists', '/songs' => 'Songs', '/matches' => 'Apple Music', '/export' => 'Export', '/stats' => 'Stats'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= e($title) ?> · Heard Live</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="wordmark" href="/" aria-label="Heard Live home">
                <span class="wordmark__dot" aria-hidden="true"></span>Heard Live
            </a>
            <nav class="site-nav" aria-label="Main">
                <?php foreach ($nav as $href => $label): ?>
                    <a href="<?= e($href) ?>"<?= str_starts_with($path, $href) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach ?>
            </nav>
        </div>
    </header>

    <main id="main">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="container site-footer__inner">
            <p>A personal project. Setlists from <a href="https://www.setlist.fm">setlist.fm</a>. Recording matches from the iTunes Search API.</p>
        </div>
    </footer>
</body>
</html>
