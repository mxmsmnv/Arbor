<?php namespace ProcessWire;

/** @var Arbor $arbor */
/** @var array $tree */
/** @var array $person */
/** @var int $id */
/** @var array $names */
/** @var array $events */
/** @var array $citizenships */
/** @var array $external_ids */
/** @var array $photos */
/** @var array $documents */
/** @var array $evidence */
/** @var array $associations */
/** @var array $tasks */
/** @var array $kits */
/** @var array|null $personContext */
/** @var string $baseUrl */

$otherNameTypes = ['AKA','IMMIGRANT','MAIDEN','MARRIED','PROFESSIONAL','OTHER'];
$otherNameLabels = [
    'AKA' => 'Also known as',
    'IMMIGRANT' => 'Immigration name',
    'MAIDEN' => 'Maiden name',
    'MARRIED' => 'Married name',
    'PROFESSIONAL' => 'Professional name',
    'OTHER' => 'Other name',
];
$scripts        = ['latin','cyrillic','hebrew','yiddish','arabic','other'];
$resnVals       = ['none','confidential','locked','privacy'];
$personContext = $personContext ?? [];
$personBackUrl = $personContext['backUrl'] ?? $baseUrl . 'persons/?tree=' . (int) $tree['id'];
$personBackLabel = $personContext['backLabel'] ?? 'All people';
$personCreateTitle = $personContext['title'] ?? 'Who are you adding?';
$personCreateIntro = $personContext['intro'] ?? 'Start with the basics. You can add photos, sources, DNA, and extra details after saving.';
$evidence = $evidence ?? ['byEvent' => [], 'byDocument' => [], 'personOnly' => []];

$renderEvidence = static function (array $rows): string {
    if (!$rows) return '';
    $html = '<div class="arbor-evidence-list arbor-person-evidence">';
    foreach ($rows as $row) {
        $links = [];
        if (!empty($row['document_url'])) {
            $links[] = '<a class="uk-link-muted" href="' . htmlspecialchars($row['document_url']) . '" target="_blank" rel="noopener">URL</a>';
        }
        if (!empty($row['document_file_url'])) {
            $links[] = '<a class="uk-link-muted" href="' . htmlspecialchars($row['document_file_url']) . '" target="_blank" rel="noopener">file</a>';
        }
        $label = $row['source_title'] ?? 'Source';
        if (!empty($row['document_title']) && (string) $row['document_title'] !== (string) ($row['source_title'] ?? '')) {
            $label .= ' · ' . $row['document_title'];
        }
        $meta = array_filter([$row['page_ref'] ?? null, $links ? implode(' · ', $links) : null]);
        $html .= '<div class="arbor-evidence-chip"><span><strong>' . htmlspecialchars($label) . '</strong>';
        if ($meta) $html .= '<br><em>' . implode(' · ', $meta) . '</em>';
        $html .= '</span></div>';
    }
    return $html . '</div>';
};

/* split loaded names into the primary BIRTH name and everything else */
$primary = [
    'id' => '', 'given' => '', 'surname' => '', 'patronymic' => '',
    'given_hebrew' => '', 'father_hebrew' => '', 'script' => 'latin', 'dm_soundex' => '',
];
$others = [];
foreach ($names as $n) {
    if ($n['name_type'] === 'BIRTH' && !$primary['id']) {
        $primary = array_merge($primary, $n);
    } else {
        $others[] = $n;
    }
}

/* extract birth/death/burial as quick-edit fields */
$birth  = ['date' => '', 'place' => '', 'approx' => 0];
$death  = ['date' => '', 'place' => '', 'approx' => 0, 'cause' => ''];
$burial = ['date' => '', 'place' => ''];
foreach ($events as $e) {
    if ($e['event_type'] === 'birth' && !$birth['date'] && !$birth['place']) {
        $birth['date']   = $e['event_date'] ?? '';
        $birth['place']  = $e['event_place_str'] ?? '';
        $birth['approx'] = (int) ($e['event_date_approx'] ?? 0);
    } elseif ($e['event_type'] === 'death' && !$death['date'] && !$death['place']) {
        $death['date']   = $e['event_date'] ?? '';
        $death['place']  = $e['event_place_str'] ?? '';
        $death['approx'] = (int) ($e['event_date_approx'] ?? 0);
        $death['cause']  = $e['cause'] ?? '';
    } elseif ($e['event_type'] === 'burial' && !$burial['date'] && !$burial['place']) {
        $burial['date']  = $e['event_date'] ?? '';
        $burial['place'] = $e['event_place_str'] ?? '';
    }
}
?>
<div class="pw-wrap Arbor">

<div class="arbor-toolbar">
    <a class="uk-button uk-button-default" href="<?= htmlspecialchars($personBackUrl) ?>">
        <span uk-icon="icon: arrow-left"></span> <?= htmlspecialchars($personBackLabel) ?>
    </a>
    <?php if ($id): ?>
        <a class="uk-button uk-button-danger" href="<?= $baseUrl ?>person-delete/?id=<?= $id ?>" style="margin-left:auto">
            <span uk-icon="icon: trash"></span> Delete person
        </a>
    <?php endif; ?>
</div>

<form class="InputfieldForm" method="post" enctype="multipart/form-data">
<?= $csrfInput ?>

<?php if (!$id): ?>
    <section class="arbor-person-create">
        <div class="arbor-create-main">
            <div class="arbor-create-card">
                <div class="arbor-create-head">
                    <span uk-icon="icon: user"></span>
                    <div>
                        <h3><?= htmlspecialchars($personCreateTitle) ?></h3>
                        <p><?= htmlspecialchars($personCreateIntro) ?></p>
                    </div>
                </div>

                <input type="hidden" name="primary_name[id]" value="">
                <input type="hidden" name="primary_name[script]" value="latin">
                <input type="hidden" name="resn" value="none">

                <div class="arbor-simple-grid arbor-simple-grid-name">
                    <label>
                        <span>First name <b>*</b></span>
                        <input id="primary_given" class="uk-input arbor-name-given" type="text"
                               name="primary_name[given]" value="<?= htmlspecialchars($primary['given']) ?>" autofocus>
                    </label>
                    <label>
                        <span>Last name</span>
                        <input class="uk-input" type="text" name="primary_name[surname]"
                               value="<?= htmlspecialchars($primary['surname']) ?>">
                    </label>
                    <label>
                        <span>Middle or father's name</span>
                        <input class="uk-input" type="text" name="primary_name[patronymic]"
                               value="<?= htmlspecialchars($primary['patronymic']) ?>">
                    </label>
                </div>

                <div class="arbor-simple-grid">
                    <label>
                        <span>Sex</span>
                        <select class="uk-select" name="sex">
                            <?php foreach (['U' => 'Unknown','F' => 'Female','M' => 'Male','X' => 'Non-binary'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $person['sex'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="arbor-simple-check">
                        <input class="uk-checkbox" type="checkbox" name="is_alive" value="1" <?= !empty($person['is_alive']) ? 'checked' : '' ?>>
                        <span>This person is still alive</span>
                    </label>
                </div>

                <div class="arbor-create-divider"></div>

                <h4>Birth</h4>
                <div class="arbor-simple-grid arbor-simple-grid-birth">
                    <label>
                        <span>Date</span>
                        <input class="uk-input" type="date" name="birth_date" value="<?= htmlspecialchars($birth['date']) ?>">
                    </label>
                    <label class="arbor-simple-check">
                        <input class="uk-checkbox" type="checkbox" name="birth_approx" value="1" <?= $birth['approx'] ? 'checked' : '' ?>>
                        <span>About this date</span>
                    </label>
                    <label>
                        <span>Place</span>
                        <input class="uk-input" type="text" name="birth_place"
                               value="<?= htmlspecialchars($birth['place']) ?>"
                               placeholder="City, region, country">
                    </label>
                </div>

                <details class="arbor-create-more">
                    <summary>More details</summary>
                    <div class="arbor-simple-grid">
                        <label>
                            <span>Hebrew name</span>
                            <input class="uk-input" type="text" dir="rtl"
                                   name="primary_name[given_hebrew]" value="<?= htmlspecialchars($primary['given_hebrew']) ?>">
                        </label>
                        <label>
                            <span>Ethnicity</span>
                            <input class="uk-input" type="text" name="ethnicity" value="<?= htmlspecialchars($person['ethnicity'] ?? '') ?>">
                        </label>
                        <label>
                            <span>Religion</span>
                            <input class="uk-input" type="text" name="religion" value="<?= htmlspecialchars($person['religion'] ?? '') ?>">
                        </label>
                    </div>
                    <label class="arbor-simple-full">
                        <span>Short note</span>
                        <textarea class="uk-textarea" name="bio" rows="3"><?= htmlspecialchars($person['bio'] ?? '') ?></textarea>
                    </label>
                </details>
            </div>
        </div>
    </section>
<?php else: ?>
<fieldset class="uk-grid-small arbor-edit-grid arbor-legacy-edit" hidden disabled>

    <!-- main column -->
    <div class="uk-width-2-3@l">

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card arbor-card-names">
            <h3 class="uk-card-title">
                <span uk-icon="icon: tag"></span> Name
                <span class="uk-text-meta uk-text-normal">— the main name shown in the tree</span>
            </h3>
            <input type="hidden" name="primary_name[id]" value="<?= htmlspecialchars($primary['id'] ?: '') ?>">
            <ul class="Inputfields">
                <li class="Inputfield InputfieldText InputfieldStateRequired" data-colwidth="33">
                    <label class="InputfieldHeader ui-widget-header" for="primary_given">
                        <i class="toggle-icon fa fa-fw fa-angle-down"></i>Given name
                    </label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="primary_given" class="uk-input arbor-name-given" type="text"
                               name="primary_name[given]" value="<?= htmlspecialchars($primary['given']) ?>"
                               <?= !$id ? 'autofocus' : '' ?>>
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="33">
                    <label class="InputfieldHeader ui-widget-header" for="primary_surname">
                        <i class="toggle-icon fa fa-fw fa-angle-down"></i>Last name (surname)
                    </label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="primary_surname" class="uk-input" type="text"
                               name="primary_name[surname]" value="<?= htmlspecialchars($primary['surname']) ?>">
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="34">
                    <label class="InputfieldHeader ui-widget-header" for="primary_patronymic">
                        <i class="toggle-icon fa fa-fw fa-angle-down"></i>Patronymic
                    </label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="primary_patronymic" class="uk-input" type="text"
                               name="primary_name[patronymic]" value="<?= htmlspecialchars($primary['patronymic']) ?>">
                        <p class="notes">Middle or father's name, if the person used one.</p>
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="50">
                    <label class="InputfieldHeader ui-widget-header" for="primary_hebrew">
                        <i class="toggle-icon fa fa-fw fa-angle-down"></i>Hebrew given name
                    </label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="primary_hebrew" class="uk-input" type="text" dir="rtl"
                               name="primary_name[given_hebrew]" value="<?= htmlspecialchars($primary['given_hebrew']) ?>">
                        <p class="notes">Use this when the person also had a Hebrew religious name.</p>
                    </div>
                </li>
                <li class="Inputfield InputfieldSelect" data-colwidth="25">
                    <label class="InputfieldHeader ui-widget-header" for="primary_script">
                        <i class="toggle-icon fa fa-fw fa-angle-down"></i>Script
                    </label>
                    <div class="InputfieldContent ui-widget-content">
                        <select id="primary_script" class="uk-select" name="primary_name[script]">
                            <?php foreach ($scripts as $s): ?>
                                <option value="<?= $s ?>" <?= ($primary['script'] ?: 'latin') === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </li>
                <li class="Inputfield" data-colwidth="25">
                    <label class="InputfieldHeader ui-widget-header">
                        <i class="toggle-icon fa fa-fw fa-angle-down"></i>Similar-name search
                    </label>
                    <div class="InputfieldContent ui-widget-content">
                        <code class="arbor-soundex-large"><?= htmlspecialchars($primary['dm_soundex'] ?: 'created after save') ?></code>
                    </div>
                </li>
            </ul>
        </section>

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: tag"></span> Other names
                <span class="uk-text-meta uk-text-normal">— name changes, nicknames, married names</span>
            </h3>
            <?php if (empty($others)): ?>
                <p class="uk-text-meta uk-margin-small">Add any other names this person used.</p>
            <?php endif; ?>
            <div class="arbor-repeater <?= empty($others) ? 'arbor-repeater-empty' : '' ?>">
                <table class="uk-table uk-table-small uk-table-divider uk-table-middle arbor-names">
                    <thead>
                        <tr>
                            <th>Kind</th>
                            <th>First name</th>
                            <th>Last name</th>
                            <th>Script</th>
                            <th>Match code</th>
                            <th class="arbor-del"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($others as $i => $n): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="other_names[<?= $i ?>][id]" value="<?= $n['id'] ?? '' ?>">
                                <select class="uk-select uk-form-small" name="other_names[<?= $i ?>][name_type]">
                                    <?php foreach ($otherNameTypes as $t): ?>
                                        <option value="<?= $t ?>" <?= ($n['name_type'] ?? '') === $t ? 'selected' : '' ?>><?= $otherNameLabels[$t] ?? $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input class="uk-input uk-form-small" type="text" name="other_names[<?= $i ?>][given]"   value="<?= htmlspecialchars($n['given']   ?? '') ?>"></td>
                            <td><input class="uk-input uk-form-small" type="text" name="other_names[<?= $i ?>][surname]" value="<?= htmlspecialchars($n['surname'] ?? '') ?>"></td>
                            <td>
                                <select class="uk-select uk-form-small" name="other_names[<?= $i ?>][script]">
                                    <?php foreach ($scripts as $s): ?>
                                        <option value="<?= $s ?>" <?= ($n['script'] ?? 'latin') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><code class="arbor-soundex"><?= htmlspecialchars($n['dm_soundex'] ?? '—') ?></code></td>
                            <td class="arbor-del"><label title="Mark for deletion"><input class="uk-checkbox" type="checkbox" name="other_names[<?= $i ?>][_delete]" value="1"></label></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($others)): ?>
                        <tr class="arbor-empty-row">
                            <td colspan="6" class="uk-text-center uk-text-muted uk-text-small">
                                No other names yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="uk-button uk-button-default uk-margin-small-top arbor-add-row" data-target=".arbor-person-profile-edit .arbor-names tbody"
                    data-template='<tr><td><input type="hidden" name="other_names[__i__][id]" value=""><select class="uk-select uk-form-small" name="other_names[__i__][name_type]"><?php foreach ($otherNameTypes as $t): ?><option value="<?= $t ?>"><?= htmlspecialchars($otherNameLabels[$t] ?? $t) ?></option><?php endforeach; ?></select></td><td><input class="uk-input uk-form-small" type="text" name="other_names[__i__][given]"></td><td><input class="uk-input uk-form-small" type="text" name="other_names[__i__][surname]"></td><td><select class="uk-select uk-form-small" name="other_names[__i__][script]"><?php foreach ($scripts as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select></td><td><code class="arbor-soundex">—</code></td><td class="arbor-del"><label><input class="uk-checkbox" type="checkbox" name="other_names[__i__][_delete]" value="1"></label></td></tr>'>
                <span uk-icon="icon: plus"></span> Add other name
            </button>
        </section>

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: calendar"></span> Birth, death &amp; burial
                <span class="uk-text-meta uk-text-normal">— key life details</span>
            </h3>
            <ul class="Inputfields">
                <li class="Inputfield InputfieldDatetime" data-colwidth="20">
                    <label class="InputfieldHeader ui-widget-header" for="birth_date"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Birth date</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="birth_date" class="uk-input" type="date" name="birth_date" value="<?= htmlspecialchars($birth['date']) ?>">
                    </div>
                </li>
                <li class="Inputfield InputfieldCheckbox" data-colwidth="15">
                    <label class="InputfieldHeader ui-widget-header"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Approximate</label>
                    <div class="InputfieldContent ui-widget-content">
                        <label><input class="uk-checkbox" type="checkbox" name="birth_approx" value="1" <?= $birth['approx'] ? 'checked' : '' ?>> About this date</label>
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="65">
                    <label class="InputfieldHeader ui-widget-header" for="birth_place"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Birth place</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="birth_place" class="uk-input" type="text" name="birth_place" value="<?= htmlspecialchars($birth['place']) ?>" placeholder="Berdichev, Kiev gubernia, Russian Empire">
                    </div>
                </li>

                <li class="Inputfield InputfieldCheckbox">
                    <label class="InputfieldHeader ui-widget-header"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Living status</label>
                    <div class="InputfieldContent ui-widget-content">
                        <label><input class="uk-checkbox" type="checkbox" name="is_alive" id="p_is_alive" value="1" <?= !empty($person['is_alive']) ? 'checked' : '' ?>>
                            This person is still alive
                        </label>
                        <p class="notes">Turn this off to enter death and burial details.</p>
                    </div>
                </li>

                <li class="Inputfield InputfieldDatetime arbor-death-field" data-colwidth="20">
                    <label class="InputfieldHeader ui-widget-header" for="death_date"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Death date</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="death_date" class="uk-input" type="date" name="death_date" value="<?= htmlspecialchars($death['date']) ?>">
                    </div>
                </li>
                <li class="Inputfield InputfieldCheckbox arbor-death-field" data-colwidth="15">
                    <label class="InputfieldHeader ui-widget-header"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Approximate</label>
                    <div class="InputfieldContent ui-widget-content">
                        <label><input class="uk-checkbox" type="checkbox" name="death_approx" value="1" <?= $death['approx'] ? 'checked' : '' ?>> About this date</label>
                    </div>
                </li>
                <li class="Inputfield InputfieldText arbor-death-field" data-colwidth="65">
                    <label class="InputfieldHeader ui-widget-header" for="death_place"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Death place</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="death_place" class="uk-input" type="text" name="death_place" value="<?= htmlspecialchars($death['place']) ?>" placeholder="Leningrad, USSR">
                    </div>
                </li>
                <li class="Inputfield InputfieldText arbor-death-field">
                    <label class="InputfieldHeader ui-widget-header" for="death_cause"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Cause of death</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="death_cause" class="uk-input" type="text" name="death_cause" value="<?= htmlspecialchars($death['cause']) ?>" placeholder="e.g. typhus, siege of Leningrad, natural causes">
                    </div>
                </li>

                <li class="Inputfield InputfieldDatetime arbor-death-field" data-colwidth="35">
                    <label class="InputfieldHeader ui-widget-header" for="burial_date"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Burial date</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="burial_date" class="uk-input" type="date" name="burial_date" value="<?= htmlspecialchars($burial['date']) ?>">
                    </div>
                </li>
                <li class="Inputfield InputfieldText arbor-death-field" data-colwidth="65">
                    <label class="InputfieldHeader ui-widget-header" for="burial_place"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Burial place</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="burial_place" class="uk-input" type="text" name="burial_place" value="<?= htmlspecialchars($burial['place']) ?>" placeholder="Cemetery, city, country">
                    </div>
                </li>
            </ul>
        </section>

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: user"></span> Identity &amp; origin
            </h3>
            <ul class="Inputfields">
                <li class="Inputfield InputfieldSelect" data-colwidth="25">
                    <label class="InputfieldHeader ui-widget-header" for="p_sex"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Sex</label>
                    <div class="InputfieldContent ui-widget-content">
                        <select id="p_sex" class="uk-select" name="sex">
                            <?php foreach (['M' => 'Male','F' => 'Female','X' => 'Non-binary','U' => 'Unknown'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $person['sex'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="25">
                    <label class="InputfieldHeader ui-widget-header" for="p_gender_text"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Gender (free text)</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="p_gender_text" class="uk-input" type="text" name="gender_text" value="<?= htmlspecialchars($person['gender_text'] ?? '') ?>" placeholder="optional">
                    </div>
                </li>
                <li class="Inputfield InputfieldSelect" data-colwidth="25">
                    <label class="InputfieldHeader ui-widget-header" for="p_resn"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Privacy</label>
                    <div class="InputfieldContent ui-widget-content">
                        <select id="p_resn" class="uk-select" name="resn">
                            <?php foreach ($resnVals as $r): ?>
                                <option value="<?= $r ?>" <?= ($person['resn'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="25">
                    <label class="InputfieldHeader ui-widget-header" for="p_refn"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Reference number</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="p_refn" class="uk-input" type="text" name="refn" value="<?= htmlspecialchars($person['refn'] ?? '') ?>" placeholder="e.g. Ahnentafel #">
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="50">
                    <label class="InputfieldHeader ui-widget-header" for="p_ethnicity"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Ethnicity</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="p_ethnicity" class="uk-input" type="text" name="ethnicity" value="<?= htmlspecialchars($person['ethnicity'] ?? '') ?>" placeholder="e.g. Jewish, Polish, Ukrainian">
                    </div>
                </li>
                <li class="Inputfield InputfieldText" data-colwidth="50">
                    <label class="InputfieldHeader ui-widget-header" for="p_religion"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Religion</label>
                    <div class="InputfieldContent ui-widget-content">
                        <input id="p_religion" class="uk-input" type="text" name="religion" value="<?= htmlspecialchars($person['religion'] ?? '') ?>" placeholder="e.g. Judaism, Orthodox Christianity">
                    </div>
                </li>
                <li class="Inputfield InputfieldCheckboxes">
                    <label class="InputfieldHeader ui-widget-header"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Jewish priestly lineage</label>
                    <div class="InputfieldContent ui-widget-content uk-form-controls-text">
                        <label class="uk-margin-small-right" title="Kohen — patrilineal descendant of Aaron, the priestly class">
                            <input class="uk-checkbox" type="checkbox" name="is_cohen" value="1" <?= !empty($person['is_cohen']) ? 'checked' : '' ?>> Cohen (Kohen)
                        </label>
                        <label class="uk-margin-small-right" title="Levite — descendant of the tribe of Levi, assists Kohanim">
                            <input class="uk-checkbox" type="checkbox" name="is_levi"  value="1" <?= !empty($person['is_levi'])  ? 'checked' : '' ?>> Levi (Levite)
                        </label>
                        <p class="notes">
                            Use these only when family tradition or records clearly say the person was Cohen/Kohen or Levi/Levite.
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: world"></span> Citizenships
                <span class="uk-text-meta uk-text-normal">— with optional date ranges</span>
            </h3>
            <div class="arbor-repeater">
                <table class="uk-table uk-table-small uk-table-divider uk-table-middle arbor-citizenships">
                    <thead>
                        <tr>
                            <th>Country</th>
                            <th class="uk-width-small">From</th>
                            <th class="uk-width-small">To</th>
                            <th class="uk-width-small">Current</th>
                            <th class="arbor-del"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $citRows = $citizenships ?: [[]]; foreach ($citRows as $i => $c): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="citizenships[<?= $i ?>][id]" value="<?= $c['id'] ?? '' ?>">
                                <input class="uk-input uk-form-small" type="text" name="citizenships[<?= $i ?>][country]" value="<?= htmlspecialchars($c['country'] ?? '') ?>">
                            </td>
                            <td><input class="uk-input uk-form-small" type="date" name="citizenships[<?= $i ?>][date_from]" value="<?= $c['date_from'] ?? '' ?>"></td>
                            <td><input class="uk-input uk-form-small" type="date" name="citizenships[<?= $i ?>][date_to]"   value="<?= $c['date_to']   ?? '' ?>"></td>
                            <td><label><input class="uk-checkbox" type="checkbox" name="citizenships[<?= $i ?>][is_current]" value="1" <?= !empty($c['is_current']) ? 'checked' : '' ?>></label></td>
                            <td class="arbor-del"><label><input class="uk-checkbox" type="checkbox" name="citizenships[<?= $i ?>][_delete]" value="1"></label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="uk-button uk-button-default uk-margin-small-top arbor-add-row" data-target=".arbor-person-profile-edit .arbor-citizenships tbody">
                <span uk-icon="icon: plus"></span> Add citizenship
            </button>
        </section>

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: link"></span> Links to other sites
                <span class="uk-text-meta uk-text-normal">— FamilySearch, MyHeritage, JewishGen, Yad Vashem</span>
            </h3>
            <div class="arbor-repeater">
                <table class="uk-table uk-table-small uk-table-divider uk-table-middle arbor-exids">
                    <thead><tr><th>Website or URL prefix</th><th>Person ID</th><th class="arbor-del"></th></tr></thead>
                    <tbody>
                    <?php $exRows = $external_ids ?: [[]]; foreach ($exRows as $i => $x): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="external_ids[<?= $i ?>][id]" value="<?= $x['id'] ?? '' ?>">
                                <input class="uk-input uk-form-small" type="text" name="external_ids[<?= $i ?>][id_type]" value="<?= htmlspecialchars($x['id_type'] ?? '') ?>" placeholder="https://www.familysearch.org/tree/person/">
                            </td>
                            <td><input class="uk-input uk-form-small" type="text" name="external_ids[<?= $i ?>][external_id]" value="<?= htmlspecialchars($x['external_id'] ?? '') ?>"></td>
                            <td class="arbor-del"><label><input class="uk-checkbox" type="checkbox" name="external_ids[<?= $i ?>][_delete]" value="1"></label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="uk-button uk-button-default uk-margin-small-top arbor-add-row" data-target=".arbor-person-profile-edit .arbor-exids tbody">
                <span uk-icon="icon: plus"></span> Add external ID
            </button>
        </section>

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: calendar"></span> Events
                <span class="uk-text-meta uk-text-normal">— birth, death, immigration, military service, metrical records</span>
            </h3>
            <?php if ($id && !empty($events)): ?>
                <table class="uk-table uk-table-small uk-table-divider">
                    <thead><tr><th>Type</th><th>Date</th><th>Place</th><th>Title / description</th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $e): ?>
                        <tr>
                            <td>
                                <span class="uk-label"><?= htmlspecialchars($e['event_type']) ?></span>
                                <?php if ($e['event_subtype']): ?><span class="uk-text-muted uk-text-small"><?= htmlspecialchars($e['event_subtype']) ?></span><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) $e['event_date']) ?: '<span class="uk-text-muted">' . htmlspecialchars($e['event_date_phrase']) . '</span>' ?></td>
                            <td class="uk-text-muted"><?= htmlspecialchars($e['event_place_str'] ?? '') ?></td>
                            <td>
                                <?= htmlspecialchars(($e['title'] ?: substr((string) $e['description'], 0, 80))) ?>
                                <?= $renderEvidence($evidence['byEvent'][(int) $e['id']] ?? []) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="uk-text-meta uk-margin-small-top">Detailed event editing will be added here later.</p>
            <?php else: ?>
                <div class="uk-text-center uk-text-muted uk-padding-small">
                    <span uk-icon="icon: clock; ratio: 1.5"></span>
                    <p class="uk-margin-remove"><?= $id ? 'No events recorded yet.' : 'Save the person first to add events.' ?></p>
                </div>
            <?php endif; ?>
        </section>

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: file-text"></span> Biography &amp; notes
            </h3>
            <ul class="Inputfields">
                <li class="Inputfield InputfieldTextarea">
                    <label class="InputfieldHeader ui-widget-header" for="p_bio"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Biography</label>
                    <div class="InputfieldContent ui-widget-content">
                        <textarea id="p_bio" class="uk-textarea" name="bio" rows="6"><?= htmlspecialchars($person['bio'] ?? '') ?></textarea>
                        <p class="notes">A short life story or summary.</p>
                    </div>
                </li>
                <li class="Inputfield InputfieldTextarea">
                    <label class="InputfieldHeader ui-widget-header" for="p_notes"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Private notes</label>
                    <div class="InputfieldContent ui-widget-content">
                        <textarea id="p_notes" class="uk-textarea" name="notes" rows="4"><?= htmlspecialchars($person['notes'] ?? '') ?></textarea>
                        <p class="notes">Working notes, questions, and source leads.</p>
                    </div>
                </li>
            </ul>
        </section>

        <?php if ($id): ?>
            <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                <h3 class="uk-card-title">
                    <span uk-icon="icon: file"></span> Documents
                </h3>
                <?php if (empty($documents)): ?>
                    <p class="uk-text-muted uk-text-small uk-margin-remove">No archival documents linked yet.</p>
                <?php else: ?>
                    <table class="uk-table uk-table-small uk-table-divider">
                        <thead><tr><th>Type</th><th>Title</th><th>Archive reference</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($documents as $d): ?>
                            <tr>
                                <td><span class="uk-label"><?= htmlspecialchars($d['doc_type']) ?></span></td>
                                <td>
                                    <?= htmlspecialchars($d['title']) ?>
                                    <?= $renderEvidence($evidence['byDocument'][(int) $d['id']] ?? []) ?>
                                </td>
                                <td class="uk-text-small"><?= htmlspecialchars(($d['fond'] ? 'ф. ' . $d['fond'] : '') . ($d['opis'] ? ', оп. ' . $d['opis'] : '') . ($d['delo'] ? ', д. ' . $d['delo'] : '')) ?></td>
                                <td class="uk-text-muted uk-text-small"><?= htmlspecialchars((string) $d['doc_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                <h3 class="uk-card-title">
                    <span uk-icon="icon: users"></span> Associations
                    <span class="uk-text-meta uk-text-normal">— people connected to this person</span>
                </h3>
                <?php if (empty($associations)): ?>
                    <p class="uk-text-muted uk-text-small uk-margin-remove">No associations recorded yet.</p>
                <?php else: ?>
                    <table class="uk-table uk-table-small uk-table-divider">
                        <thead><tr><th>Role</th><th>Related</th><th>Phrase</th></tr></thead>
                        <tbody>
                        <?php foreach ($associations as $a): ?>
                            <tr>
                                <td><span class="uk-label"><?= htmlspecialchars($a['role']) ?></span></td>
                                <td><a href="<?= $baseUrl ?>person/?id=<?= (int) $a['related_id'] ?>">#<?= (int) $a['related_id'] ?></a></td>
                                <td class="uk-text-muted"><?= htmlspecialchars($a['role_phrase']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                <h3 class="uk-card-title">
                    <span uk-icon="icon: bolt"></span> DNA kits
                </h3>
                <?php if (empty($kits)): ?>
                    <p class="uk-text-muted uk-text-small uk-margin-remove">No DNA kits linked yet.</p>
                <?php else: ?>
                    <table class="uk-table uk-table-small uk-table-divider">
                        <thead><tr><th>Company</th><th>Test</th><th>Kit ID</th><th>Y haplo</th><th>mtDNA</th></tr></thead>
                        <tbody>
                        <?php foreach ($kits as $k): ?>
                            <tr>
                                <td><?= htmlspecialchars($k['company']) ?></td>
                                <td><?= htmlspecialchars($k['test_type']) ?></td>
                                <td><code><?= htmlspecialchars($k['kit_id']) ?></code></td>
                                <td><?= htmlspecialchars($k['y_haplogroup']) ?></td>
                                <td><?= htmlspecialchars($k['mt_haplogroup']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <!-- side column -->
    <div class="uk-width-1-3@l">

        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: image"></span> Photos
            </h3>
            <?php if ($id && !empty($photos)): ?>
                <div class="arbor-photos">
                    <?php foreach ($photos as $ph): ?>
                        <div class="arbor-photo">
                            <img src="<?= htmlspecialchars($arbor->model('photos')->url($ph)) ?>" alt="">
                            <?php if (!empty($ph['is_profile'])): ?>
                                <span class="uk-label uk-label-success">profile</span>
                            <?php endif; ?>
                            <?php if ($ph['title']): ?>
                                <div class="arbor-photo-title"><?= htmlspecialchars($ph['title']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="uk-text-muted uk-text-small">No photos yet.</p>
            <?php endif; ?>
            <div class="uk-margin-small-top">
                <label class="uk-form-label" for="p_photo_upload">Upload photos</label>
                <div class="uk-form-controls">
                    <input id="p_photo_upload" type="file" name="photo_upload[]" multiple accept="image/*">
                    <p class="uk-text-meta uk-margin-remove-top">Drag &amp; drop multiple images. Size limit set in module config.</p>
                </div>
            </div>
        </section>

        <?php if ($id): ?>
            <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                <h3 class="uk-card-title">
                    <span uk-icon="icon: bookmark"></span> Tasks
                </h3>
                <?php if (empty($tasks)): ?>
                    <p class="uk-text-muted uk-text-small uk-margin-remove">No open tasks.</p>
                <?php else: ?>
                    <ul class="uk-list uk-list-divider uk-margin-remove">
                    <?php foreach ($tasks as $t):
                        $cls = match ($t['priority']) {
                            'urgent' => 'danger',
                            'high' => 'warning',
                            default => '',
                        }; ?>
                        <li>
                            <?php if ($cls): ?><span class="uk-label uk-label-<?= $cls ?>"><?= htmlspecialchars($t['priority']) ?></span><?php endif; ?>
                            <?= htmlspecialchars($t['title']) ?>
                            <?php if ($t['due_date']): ?><div class="uk-text-meta">due <?= $t['due_date'] ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ($arbor->aiEnabled): ?>
                <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                    <h3 class="uk-card-title">
                        <span uk-icon="icon: bolt"></span> AI assistance
                    </h3>
                    <div class="uk-flex uk-flex-column" style="gap:6px">
                        <button type="button" class="uk-button uk-button-secondary arbor-ai-context" data-id="<?= $id ?>">
                            <span uk-icon="icon: comment"></span> Suggest historical context
                        </button>
                        <button type="button" class="uk-button uk-button-secondary arbor-ai-duplicates" data-id="<?= $id ?>">
                            <span uk-icon="icon: copy"></span> Find duplicates
                        </button>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</fieldset>

<section class="arbor-person-profile-edit">
    <div class="arbor-profile-card">
        <div class="arbor-profile-head">
            <div class="arbor-profile-avatar">
                <?php if (!empty($photos[0])): ?>
                    <img src="<?= htmlspecialchars($arbor->model('photos')->url($photos[0])) ?>" alt="">
                <?php else: ?>
                    <span uk-icon="icon: user; ratio: 1.4"></span>
                <?php endif; ?>
            </div>
            <div>
                <h3><?= htmlspecialchars(trim(($primary['given'] ?? '') . ' ' . ($primary['surname'] ?? '')) ?: 'Person') ?></h3>
                <p><?= !empty($person['is_alive']) ? 'Living person' : 'Deceased person' ?></p>
            </div>
        </div>

        <input type="hidden" name="primary_name[id]" value="<?= htmlspecialchars($primary['id'] ?: '') ?>">
        <input type="hidden" name="primary_name[script]" value="<?= htmlspecialchars($primary['script'] ?: 'latin') ?>">
        <input type="hidden" name="gender_text" value="<?= htmlspecialchars($person['gender_text'] ?? '') ?>">
        <input type="hidden" name="death_approx" value="<?= (int) $death['approx'] ?>">
        <input type="hidden" name="death_cause" value="<?= htmlspecialchars($death['cause']) ?>">
        <input type="hidden" name="burial_date" value="<?= htmlspecialchars($burial['date']) ?>">
        <input type="hidden" name="burial_place" value="<?= htmlspecialchars($burial['place']) ?>">

        <div class="arbor-simple-grid arbor-simple-grid-name">
            <label>
                <span>First name <b>*</b></span>
                <input id="primary_given" class="uk-input arbor-name-given" type="text"
                       name="primary_name[given]" value="<?= htmlspecialchars($primary['given']) ?>">
            </label>
            <label>
                <span>Last name</span>
                <input class="uk-input" type="text" name="primary_name[surname]"
                       value="<?= htmlspecialchars($primary['surname']) ?>">
            </label>
            <label>
                <span>Middle or father's name</span>
                <input class="uk-input" type="text" name="primary_name[patronymic]"
                       value="<?= htmlspecialchars($primary['patronymic']) ?>">
            </label>
        </div>

        <div class="arbor-simple-grid">
            <label>
                <span>Sex</span>
                <select class="uk-select" name="sex">
                    <?php foreach (['U' => 'Unknown','F' => 'Female','M' => 'Male','X' => 'Non-binary'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $person['sex'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="arbor-simple-check">
                <input class="uk-checkbox" type="checkbox" name="is_alive" id="p_is_alive" value="1" <?= !empty($person['is_alive']) ? 'checked' : '' ?>>
                <span>This person is still alive</span>
            </label>
        </div>

        <div class="arbor-create-divider"></div>

        <h4>Life events</h4>
        <div class="arbor-simple-grid arbor-simple-grid-birth">
            <label>
                <span>Birth date</span>
                <input class="uk-input" type="date" name="birth_date" value="<?= htmlspecialchars($birth['date']) ?>">
            </label>
            <label class="arbor-simple-check">
                <input class="uk-checkbox" type="checkbox" name="birth_approx" value="1" <?= $birth['approx'] ? 'checked' : '' ?>>
                <span>About this date</span>
            </label>
            <label>
                <span>Birth place</span>
                <input class="uk-input" type="text" name="birth_place" value="<?= htmlspecialchars($birth['place']) ?>" placeholder="City, region, country">
            </label>
        </div>

        <div class="arbor-simple-grid arbor-death-field">
            <label>
                <span>Death date</span>
                <input class="uk-input" type="date" name="death_date" value="<?= htmlspecialchars($death['date']) ?>">
            </label>
            <label>
                <span>Death place</span>
                <input class="uk-input" type="text" name="death_place" value="<?= htmlspecialchars($death['place']) ?>" placeholder="City, region, country">
            </label>
        </div>

        <div class="arbor-create-divider"></div>

        <h4>Story</h4>
        <label class="arbor-simple-full">
            <span>Biography</span>
            <textarea class="uk-textarea" name="bio" rows="5"><?= htmlspecialchars($person['bio'] ?? '') ?></textarea>
        </label>
        <label class="arbor-simple-full">
            <span>Private notes</span>
            <textarea class="uk-textarea" name="notes" rows="4"><?= htmlspecialchars($person['notes'] ?? '') ?></textarea>
        </label>

        <details class="arbor-create-more">
            <summary>More details</summary>
            <div class="arbor-simple-grid">
                <label>
                    <span>Hebrew name</span>
                    <input class="uk-input" type="text" dir="rtl" name="primary_name[given_hebrew]" value="<?= htmlspecialchars($primary['given_hebrew']) ?>">
                </label>
                <label>
                    <span>Ethnicity</span>
                    <input class="uk-input" type="text" name="ethnicity" value="<?= htmlspecialchars($person['ethnicity'] ?? '') ?>">
                </label>
                <label>
                    <span>Religion</span>
                    <input class="uk-input" type="text" name="religion" value="<?= htmlspecialchars($person['religion'] ?? '') ?>">
                </label>
                <label>
                    <span>Reference number</span>
                    <input class="uk-input" type="text" name="refn" value="<?= htmlspecialchars($person['refn'] ?? '') ?>">
                </label>
                <label>
                    <span>Privacy</span>
                    <select class="uk-select" name="resn">
                        <?php foreach ($resnVals as $r): ?>
                            <option value="<?= $r ?>" <?= ($person['resn'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="arbor-line-checks">
                <label><input class="uk-checkbox" type="checkbox" name="is_cohen" value="1" <?= !empty($person['is_cohen']) ? 'checked' : '' ?>> Cohen/Kohen</label>
                <label><input class="uk-checkbox" type="checkbox" name="is_levi" value="1" <?= !empty($person['is_levi']) ? 'checked' : '' ?>> Levi/Levite</label>
            </div>
        </details>

        <details class="arbor-create-more arbor-profile-records-edit">
            <summary>Names, citizenships, and links</summary>

            <h4>Other names</h4>
            <div class="arbor-repeater <?= empty($others) ? 'arbor-repeater-empty' : '' ?>">
                <table class="uk-table uk-table-small uk-table-middle arbor-names">
                    <thead>
                        <tr>
                            <th>Kind</th>
                            <th>First name</th>
                            <th>Last name</th>
                            <th>Script</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($others as $i => $n): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="other_names[<?= $i ?>][id]" value="<?= $n['id'] ?? '' ?>">
                                <select class="uk-select uk-form-small" name="other_names[<?= $i ?>][name_type]">
                                    <?php foreach ($otherNameTypes as $t): ?>
                                        <option value="<?= $t ?>" <?= ($n['name_type'] ?? '') === $t ? 'selected' : '' ?>><?= $otherNameLabels[$t] ?? $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input class="uk-input uk-form-small" type="text" name="other_names[<?= $i ?>][given]" value="<?= htmlspecialchars($n['given'] ?? '') ?>"></td>
                            <td><input class="uk-input uk-form-small" type="text" name="other_names[<?= $i ?>][surname]" value="<?= htmlspecialchars($n['surname'] ?? '') ?>"></td>
                            <td>
                                <select class="uk-select uk-form-small" name="other_names[<?= $i ?>][script]">
                                    <?php foreach ($scripts as $s): ?>
                                        <option value="<?= $s ?>" <?= ($n['script'] ?? 'latin') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="arbor-del"><label><input class="uk-checkbox" type="checkbox" name="other_names[<?= $i ?>][_delete]" value="1"></label></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($others)): ?>
                        <tr class="arbor-empty-row"><td colspan="5" class="uk-text-center uk-text-muted uk-text-small">No other names yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="uk-button uk-button-default uk-margin-small-top arbor-add-row" data-target=".arbor-profile-records-edit .arbor-names tbody"
                    data-template='<tr><td><input type="hidden" name="other_names[__i__][id]" value=""><select class="uk-select uk-form-small" name="other_names[__i__][name_type]"><?php foreach ($otherNameTypes as $t): ?><option value="<?= $t ?>"><?= htmlspecialchars($otherNameLabels[$t] ?? $t) ?></option><?php endforeach; ?></select></td><td><input class="uk-input uk-form-small" type="text" name="other_names[__i__][given]"></td><td><input class="uk-input uk-form-small" type="text" name="other_names[__i__][surname]"></td><td><select class="uk-select uk-form-small" name="other_names[__i__][script]"><?php foreach ($scripts as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select></td><td class="arbor-del"><label><input class="uk-checkbox" type="checkbox" name="other_names[__i__][_delete]" value="1"></label></td></tr>'>
                <span uk-icon="icon: plus"></span> Add other name
            </button>

            <h4>Citizenships</h4>
            <div class="arbor-repeater">
                <table class="uk-table uk-table-small uk-table-middle arbor-citizenships">
                    <thead><tr><th>Country</th><th>From</th><th>To</th><th>Current</th><th></th></tr></thead>
                    <tbody>
                    <?php $citRows = $citizenships ?: [[]]; foreach ($citRows as $i => $c): ?>
                        <tr>
                            <td><input type="hidden" name="citizenships[<?= $i ?>][id]" value="<?= $c['id'] ?? '' ?>"><input class="uk-input uk-form-small" type="text" name="citizenships[<?= $i ?>][country]" value="<?= htmlspecialchars($c['country'] ?? '') ?>"></td>
                            <td><input class="uk-input uk-form-small" type="date" name="citizenships[<?= $i ?>][date_from]" value="<?= $c['date_from'] ?? '' ?>"></td>
                            <td><input class="uk-input uk-form-small" type="date" name="citizenships[<?= $i ?>][date_to]" value="<?= $c['date_to'] ?? '' ?>"></td>
                            <td><label><input class="uk-checkbox" type="checkbox" name="citizenships[<?= $i ?>][is_current]" value="1" <?= !empty($c['is_current']) ? 'checked' : '' ?>></label></td>
                            <td class="arbor-del"><label><input class="uk-checkbox" type="checkbox" name="citizenships[<?= $i ?>][_delete]" value="1"></label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="uk-button uk-button-default uk-margin-small-top arbor-add-row" data-target=".arbor-profile-records-edit .arbor-citizenships tbody">
                <span uk-icon="icon: plus"></span> Add citizenship
            </button>

            <h4>Links to other sites</h4>
            <div class="arbor-repeater">
                <table class="uk-table uk-table-small uk-table-middle arbor-exids">
                    <thead><tr><th>Website or URL prefix</th><th>Person ID</th><th></th></tr></thead>
                    <tbody>
                    <?php $exRows = $external_ids ?: [[]]; foreach ($exRows as $i => $x): ?>
                        <tr>
                            <td><input type="hidden" name="external_ids[<?= $i ?>][id]" value="<?= $x['id'] ?? '' ?>"><input class="uk-input uk-form-small" type="text" name="external_ids[<?= $i ?>][id_type]" value="<?= htmlspecialchars($x['id_type'] ?? '') ?>"></td>
                            <td><input class="uk-input uk-form-small" type="text" name="external_ids[<?= $i ?>][external_id]" value="<?= htmlspecialchars($x['external_id'] ?? '') ?>"></td>
                            <td class="arbor-del"><label><input class="uk-checkbox" type="checkbox" name="external_ids[<?= $i ?>][_delete]" value="1"></label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="uk-button uk-button-default uk-margin-small-top arbor-add-row" data-target=".arbor-profile-records-edit .arbor-exids tbody">
                <span uk-icon="icon: plus"></span> Add link
            </button>
        </details>
    </div>

    <div class="arbor-profile-side">
        <section class="arbor-profile-card">
            <h3><span uk-icon="icon: image"></span> Photos</h3>
            <?php if (!empty($photos)): ?>
                <div class="arbor-photos">
                    <?php foreach ($photos as $ph): ?>
                        <div class="arbor-photo">
                            <img src="<?= htmlspecialchars($arbor->model('photos')->url($ph)) ?>" alt="">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="uk-text-muted uk-text-small">No photos yet.</p>
            <?php endif; ?>
            <input id="p_photo_upload" type="file" name="photo_upload[]" multiple accept="image/*">
        </section>

        <section class="arbor-profile-card">
            <h3><span uk-icon="icon: list"></span> Linked info</h3>
            <div class="arbor-profile-stats">
                <span><?= count($events) ?> events</span>
                <span><?= count($documents) ?> documents</span>
                <span><?= count($associations) ?> links</span>
                <span><?= count($kits) ?> DNA kits</span>
            </div>
        </section>
    </div>
</section>
<?php endif; ?>

<div class="arbor-form-actions">
    <button type="submit" name="save" value="1" class="uk-button uk-button-primary">
        <span uk-icon="icon: check"></span> Save person
    </button>
    <a class="uk-button uk-button-text" href="<?= htmlspecialchars($personBackUrl) ?>">Cancel</a>
</div>

</form>
</div>
