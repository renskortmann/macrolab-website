<?php
/**
 * The hub: the page signing in lands on, and what the brand link goes back to.
 *
 * @var \Macrolab\Actor                                                        $actor
 * @var list<array{href: string, label: string, icon: string, blurb: string}> $destinations
 */

/*
 * The tile icons, one per Navigation 'icon' name. Outline drawings on a 24x24
 * grid with a 2px round stroke, so they match each other; the shapes follow
 * the Lucide icon set (ISC licence). They take the heading's colour.
 */
$icons = [
    'wrench'   => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'sheet'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 9v12M15 9v12"/>',
    'user'     => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
];
?>
<section class="card">
    <h1>Macrolab</h1>

    <p class="muted">
        Signed in as <?= e($actor->label()) ?>. Choose where you are headed.
    </p>

    <div class="tiles">
        <?php foreach ($destinations as $destination): ?>
            <a class="tile" href="<?= e(path($destination['href'])) ?>">
                <h2>
                    <svg class="tile-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                         fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round"><?= $icons[$destination['icon']] ?? '' ?></svg>
                    <?= e($destination['label']) ?>
                </h2>
                <p><?= e($destination['blurb']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>
