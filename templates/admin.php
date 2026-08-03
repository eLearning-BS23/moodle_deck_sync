<?php
script('moodle_deck_sync', 'moodle-deck-sync-moodle-deck-sync-admin');
?>

<div id="moodle-deck-sync-admin" class="section">
    <h2><?php p($l->t('Moodle Deck Sync Administration')); ?></h2>
    <div
        class="moodle-deck-sync-settings-app"
        data-settings-url="<?php p($_['settingsUrl']); ?>">
    </div>
</div>
