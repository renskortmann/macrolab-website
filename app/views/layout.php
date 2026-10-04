<?php
/**
 * The single page frame. Flash messages are rendered here and nowhere else.
 *
 * @var string $content
 * @var string $title
 */

use Macrolab\Auth;
use Macrolab\Config;
use Macrolab\Csrf;
use Macrolab\Navigation;
use Macrolab\Session;

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$flashes = Session::takeFlashes();
?>
<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= isset($title) ? e($title) . ' &middot; ' : '' ?><?= e(Config::string('app.name', 'Macrolab website')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= e(path('/')) ?>"><?= e(Config::string('app.name', 'Macrolab website')) ?></a>

    <nav>
        <?php if ($isAdmin || $user !== null): ?>
            <span class="who"><?= e($isAdmin ? 'Administrator' : $user->label()) ?></span>

            <?php /* One list, shared with the hub page. See Macrolab\Navigation. */ ?>
            <?php foreach (Navigation::destinations(Auth::actor()) as $destination): ?>
                <a href="<?= e(path($destination['href'])) ?>"><?= e($destination['label']) ?></a>
            <?php endforeach; ?>

            <form method="post" action="<?= e(path($isAdmin ? '/admin/logout' : '/logout')) ?>" class="inline">
                <?= Csrf::field() ?>
                <button type="submit" class="link">Sign out</button>
            </form>
        <?php endif; ?>
    </nav>
</header>

<main>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer>
    <p>
        Macrolab website.
        Times are shown in <?= e(Config::string('app.display_timezone', 'Europe/Amsterdam')) ?>.
    </p>
</footer>

<script src="<?= e(asset('/assets/app.js')) ?>"></script>
</body>
</html>
