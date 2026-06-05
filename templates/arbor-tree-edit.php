<?php namespace ProcessWire;

/** @var array $tree */
/** @var int $id */
/** @var array|null $settings */
/** @var array|null $persons */
/** @var string $baseUrl */
$settings = $settings ?? [];
$persons = $persons ?? [];
$rootPersonId = (int) ($settings['root_person_id'] ?? 0);
$personLabel = function (array $p): string {
    return trim(($p['given'] ?? '') . ' ' . ($p['surname'] ?? '')) ?: '#' . $p['id'];
};
?>
<div class="pw-wrap Arbor">

<?php if ($id): ?>
<div class="arbor-toolbar">
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>tree/?id=<?= $id ?>">
        <span uk-icon="icon: arrow-left"></span> Back to tree
    </a>
    <a class="uk-button uk-button-danger" href="<?= $baseUrl ?>tree-delete/?id=<?= $id ?>" style="margin-left:auto">
        <span uk-icon="icon: trash"></span> Delete tree
    </a>
</div>
<?php endif; ?>

<form class="InputfieldForm" method="post">
<?= $csrfInput ?>
<section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
    <h3 class="uk-card-title"><span uk-icon="icon: tree"></span> Tree</h3>
    <ul class="Inputfields">
        <li class="Inputfield InputfieldText InputfieldStateRequired">
            <label class="InputfieldHeader ui-widget-header" for="tree_name"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Name</label>
            <div class="InputfieldContent ui-widget-content">
                <input id="tree_name" class="uk-input" type="text" name="name" value="<?= htmlspecialchars($tree['name']) ?>" required>
                <p class="description">The name shown in Arbor, for example “Semenovs”.</p>
            </div>
        </li>
        <li class="Inputfield InputfieldTextarea">
            <label class="InputfieldHeader ui-widget-header" for="tree_description"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Description</label>
            <div class="InputfieldContent ui-widget-content">
                <textarea id="tree_description" class="uk-textarea" name="description" rows="4"><?= htmlspecialchars((string) $tree['description']) ?></textarea>
                <p class="notes">A short note about this family tree or research project.</p>
            </div>
        </li>
        <li class="Inputfield InputfieldCheckbox">
            <label class="InputfieldHeader ui-widget-header" for="tree_is_public"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Public tree</label>
            <div class="InputfieldContent ui-widget-content">
                <label>
                    <input id="tree_is_public" class="uk-checkbox" type="checkbox" name="is_public" value="1" <?= !empty($tree['is_public']) ? 'checked' : '' ?>>
                    Let visitors view this tree
                </label>
                <p class="notes">Living people and private records stay protected.</p>
            </div>
        </li>
        <?php if ($id): ?>
            <li class="Inputfield InputfieldSelect">
                <label class="InputfieldHeader ui-widget-header" for="tree_root_person_id"><i class="toggle-icon fa fa-fw fa-angle-down"></i>Main person</label>
                <div class="InputfieldContent ui-widget-content">
                    <select id="tree_root_person_id" class="uk-select" name="root_person_id">
                        <option value="">No main person yet</option>
                        <?php foreach ($persons as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= $rootPersonId === (int) $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($personLabel($p)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="notes">The Tree viewer opens around this person first.</p>
                </div>
            </li>
        <?php endif; ?>
    </ul>
</section>

<div class="arbor-form-actions">
    <button type="submit" name="save" value="1" class="uk-button uk-button-primary">
        <span uk-icon="icon: check"></span> Save tree
    </button>
    <a class="uk-button uk-button-text" href="<?= $baseUrl ?>">Cancel</a>
</div>
</form>
</div>
