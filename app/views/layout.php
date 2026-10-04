<?php
/**
 * The single page frame. Flash messages are rendered here and nowhere else.
 *
 * @var string $content
 * @var string $title
 */

use Macrolab\Auth;
use Macrolab\Config;
use Macrolab\Context;
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
            <?php
            $destinations = Navigation::destinations(Auth::actor());
            // The tab of the page being shown, marked so people see where they are.
            $currentTab = Navigation::current($destinations, Context::request()?->path ?? '');
            ?>
            <?php foreach ($destinations as $destination): ?>
                <a class="tab" href="<?= e(path($destination['href'])) ?>"
                   data-label="<?= e($destination['label']) ?>"
                   <?= $destination['href'] === $currentTab ? 'aria-current="page"' : '' ?>><?= e($destination['label']) ?></a>
            <?php endforeach; ?>

            <form method="post" action="<?= e(path($isAdmin ? '/admin/logout' : '/logout')) ?>" class="inline">
                <?= Csrf::field() ?>
                <button type="submit" class="link">Sign out</button>
            </form>
        <?php endif; ?>
    </nav>
</header>

<?php /* Under the Administration tab: its pages as a second row of tabs. */ ?>
<?php if ($isAdmin && ($currentTab ?? null) === '/admin'): ?>
    <?php
    $adminTabs = Navigation::adminTabs();
    $currentAdminTab = Navigation::current($adminTabs, Context::request()?->path ?? '');
    ?>
    <nav class="subtabs" aria-label="Administration">
        <?php foreach ($adminTabs as $tab): ?>
            <a class="tab" href="<?= e(path($tab['href'])) ?>"
               data-label="<?= e($tab['label']) ?>"
               <?= $tab['href'] === $currentAdminTab ? 'aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

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
