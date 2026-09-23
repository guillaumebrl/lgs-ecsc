<?php

declare(strict_types=1);

function render_settings_page(): never
{
    global $settings;

    require_role(['admin']);
    header_html('Réglages');
    ?>
    <section class="panel narrow">
        <h2>Établissement et notation</h2>
        <form method="post">
            <input type="hidden" name="_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>
                Nom de l’établissement
                <input required name="school_name" value="<?= e($settings['school_name']) ?>">
            </label>
            <label>
                Adresse
                <textarea name="school_address"><?= e($settings['school_address']) ?></textarea>
            </label>
            <label>
                Mode de notation
                <select name="grading_mode">
                    <option value="flexible" <?= $settings['grading_mode'] === 'flexible' ? 'selected' : '' ?>>
                        Flexible : barème libre et normalisation
                    </option>
                    <option value="forced_20" <?= $settings['grading_mode'] === 'forced_20' ? 'selected' : '' ?>>
                        Toujours saisir sur 20
                    </option>
                </select>
            </label>
            <label>
                Décimales affichées
                <input type="number" min="0" max="3" name="decimals" value="<?= e($settings['decimals']) ?>">
            </label>
            <label>
                Nombre maximal de caractères d’une appréciation
                <input
                    type="number"
                    min="50"
                    max="2000"
                    name="comment_max_length"
                    value="<?= e($settings['comment_max_length'] ?? 500) ?>"
                >
            </label>
            <button>Enregistrer</button>
        </form>
    </section>
    <?php
    footer_html();
    exit;
}
