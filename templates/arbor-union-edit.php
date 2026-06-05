<?php namespace ProcessWire;

/** @var array $tree */
/** @var array $union */
/** @var int $id */
/** @var array $children */
/** @var array $persons */
/** @var array|null $familyWarnings */
/** @var bool|null $addingChild */
/** @var string $baseUrl */

$types = [
    'unknown' => 'Not sure / unknown',
    'partnered' => 'Partners',
    'married_civil' => 'Married',
    'unmarried_with_children' => 'Unmarried with children',
    'engaged' => 'Engaged',
    'common_law' => 'Common-law partners',
    'civil_union' => 'Civil union',
    'married_religious_jewish' => 'Jewish religious marriage',
    'married_religious_christian' => 'Christian religious marriage',
    'married_religious_muslim' => 'Muslim religious marriage',
    'married_religious_other' => 'Other religious marriage',
];
$childTypes = [
    'birth' => 'Biological child',
    'adopted' => 'Adopted',
    'foster' => 'Foster',
    'stepchild' => 'Stepchild',
    'guardian' => 'Guardian relationship',
    'sealing' => 'Religious sealing',
    'foundling' => 'Foundling or unknown parents',
    'birth_disputed' => 'Biological child, disputed',
    'birth_unknown' => 'Child, relationship unknown',
];
$personLabel = function (array $p): string {
    return trim(($p['given'] ?? '') . ' ' . ($p['surname'] ?? '')) ?: '#' . $p['id'];
};
$presetChildId = !$id && !empty($children[0]['person_id']) ? (int) $children[0]['person_id'] : 0;
$addingChild = !empty($addingChild);
$partner1Id = (int) ($union['partner1_id'] ?? 0);
$partner2Id = (int) ($union['partner2_id'] ?? 0);
$parentsMode = $addingChild || $presetChildId;
$partner1Label = $parentsMode ? 'Parent 1' : 'Partner 1';
$partner2Label = $addingChild ? 'Other parent' : ($presetChildId ? 'Parent 2' : 'Partner 2');
$partnerPlaceholder = $parentsMode ? 'Choose a parent' : 'Choose a person';
$backUrl = $parentsMode ? $baseUrl . 'viewer/?tree=' . (int) $tree['id'] : $baseUrl . 'families/?tree=' . (int) $tree['id'];
$backLabel = $parentsMode ? 'Back to tree viewer' : 'All families';
$showChildTable = !$parentsMode;
$detailsOpen = '';
$relationshipLabel = $parentsMode ? "Parents' relationship" : 'Relationship';
$saveLabel = $addingChild ? 'Save child' : ($presetChildId ? 'Save parents' : 'Save family');
$detailsSummary = $parentsMode ? 'Relationship, dates, and notes' : 'Children and notes';
$saveRequirement = $addingChild ? 'child' : ($presetChildId ? 'parent' : '');
$saveRequirementText = $addingChild ? 'Choose a child to save.' : ($presetChildId ? 'Choose at least one parent to save.' : '');
$availableParentCount = 0;
foreach ($persons as $p) {
    if ($presetChildId && (int) $p['id'] === $presetChildId) continue;
    $availableParentCount++;
}
$presetChildName = '';
if ($presetChildId) {
    foreach ($persons as $p) {
        if ((int) $p['id'] === $presetChildId) {
            $presetChildName = $personLabel($p);
            break;
        }
    }
}
$newPersonUrl = function (string $role) use ($baseUrl, $tree, $addingChild, $presetChildId, $partner1Id, $partner2Id): string {
    $params = ['tree' => (int) $tree['id']];
    if ($addingChild) {
        $params['add_child'] = 1;
        $params['return_role'] = $role;
        if ($presetChildId) $params['child'] = $presetChildId;
        if ($partner1Id && $role !== 'partner1') $params['partner1'] = $partner1Id;
        if ($partner2Id && $role !== 'partner2') $params['partner2'] = $partner2Id;
    } elseif ($presetChildId && in_array($role, ['partner1', 'partner2'], true)) {
        $params['return_child'] = $presetChildId;
        $params['return_partner'] = $role;
        if ($partner1Id && $role !== 'partner1') $params['partner1'] = $partner1Id;
        if ($partner2Id && $role !== 'partner2') $params['partner2'] = $partner2Id;
    }
    return $baseUrl . 'person/?' . http_build_query($params);
};
$newPersonText = function (string $role) use ($addingChild, $presetChildId): string {
    if ($role === 'child') return 'Create new child';
    if ($addingChild && $role === 'partner2') return 'Create other parent';
    if ($addingChild || $presetChildId) return 'Create new parent';
    return 'Create new person';
};
?>
<div class="pw-wrap Arbor">

<div class="arbor-toolbar">
    <a class="uk-button uk-button-default" href="<?= htmlspecialchars($backUrl) ?>">
        <span uk-icon="icon: arrow-left"></span> <?= htmlspecialchars($backLabel) ?>
    </a>
    <?php if ($id): ?>
        <form method="post" style="margin-left:auto" onsubmit="return confirm('Delete this family?')">
            <?= $csrfInput ?>
            <button type="submit" name="delete_union" value="1" class="uk-button uk-button-danger">
                <span uk-icon="icon: trash"></span> Delete family
            </button>
        </form>
    <?php endif; ?>
</div>

<form class="InputfieldForm" method="post">
<?= $csrfInput ?>

<section class="arbor-person-create">
    <div class="arbor-create-main">
        <div class="arbor-create-card">
            <div class="arbor-create-head">
                <span uk-icon="icon: heart"></span>
                <div>
                    <h3><?= $id ? 'Edit family' : ($addingChild ? 'Add child' : ($presetChildId ? 'Add parents' : 'Add family')) ?></h3>
                    <p>
                        <?php if ($addingChild): ?>
                            Choose the child and add the other parent if you know them.
                        <?php elseif ($presetChildId): ?>
                            Choose one or both parents for <?= htmlspecialchars($presetChildName ?: 'this person') ?>, or mark the parents as unknown.
                        <?php else: ?>
                            Choose the people first. Children, dates, and notes can be added below.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($presetChildId && !$addingChild): ?>
                <div class="arbor-inline-note">
                    <span uk-icon="icon: info"></span>
                    <span><?= htmlspecialchars($presetChildName ?: 'This person') ?> will be connected to the parents you choose. If this is a top-generation person, set relationship to "Foundling or unknown parents" and save without selecting parents.</span>
                </div>
            <?php endif; ?>
            <?php if (!empty($familyWarnings)): ?>
                <div class="arbor-inline-note arbor-inline-warning">
                    <span uk-icon="icon: warning"></span>
                    <span><?= htmlspecialchars(implode(' ', $familyWarnings)) ?></span>
                </div>
            <?php endif; ?>
            <div class="arbor-inline-note arbor-inline-warning arbor-union-client-warning" hidden>
                <span uk-icon="icon: warning"></span>
                <span></span>
            </div>

            <div class="arbor-simple-grid">
                <label>
                    <span><?= $partner1Label ?></span>
                    <select id="u_partner1" class="uk-select" name="partner1_id">
                        <option value=""><?= $partnerPlaceholder ?></option>
                        <?php foreach ($persons as $p): ?>
                            <?php if ($presetChildId && (int) $p['id'] === $presetChildId) continue; ?>
                            <option value="<?= $p['id'] ?>" <?= ($union['partner1_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($personLabel($p)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a class="arbor-field-link" data-return-role="partner1" href="<?= htmlspecialchars($newPersonUrl('partner1')) ?>"><?= $newPersonText('partner1') ?></a>
                </label>
                <label>
                    <span><?= $partner2Label ?></span>
                    <select id="u_partner2" class="uk-select" name="partner2_id">
                        <option value=""><?= $partnerPlaceholder ?></option>
                        <?php foreach ($persons as $p): ?>
                            <?php if ($presetChildId && (int) $p['id'] === $presetChildId) continue; ?>
                            <option value="<?= $p['id'] ?>" <?= ($union['partner2_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($personLabel($p)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a class="arbor-field-link" data-return-role="partner2" href="<?= htmlspecialchars($newPersonUrl('partner2')) ?>"><?= $newPersonText('partner2') ?></a>
                </label>
            </div>
            <?php if ($presetChildId && !$addingChild && $availableParentCount < 2): ?>
                <div class="arbor-inline-note">
                    <span uk-icon="icon: info"></span>
                    <span>Only one possible parent is in this tree right now. Use "Create new parent" if you need to add another one.</span>
                </div>
            <?php endif; ?>

            <?php if ($addingChild): ?>
                <?php $childRow = $children[0] ?? ['person_id' => $presetChildId, 'pedigree' => 'birth', 'birth_order' => 0]; ?>
                <div class="arbor-simple-grid arbor-union-child-summary">
                    <input type="hidden" name="children[0][id]" value="<?= htmlspecialchars($childRow['id'] ?? '') ?>">
                    <input type="hidden" name="children[0][birth_order]" value="<?= (int) ($childRow['birth_order'] ?? 0) ?>">
                    <label>
                        <span>Child</span>
                        <select class="uk-select" name="children[0][person_id]">
                            <option value="">Choose a child</option>
                            <?php foreach ($persons as $p): ?>
                                <?php if (in_array((int) $p['id'], [$partner1Id, $partner2Id], true)) continue; ?>
                                <option value="<?= $p['id'] ?>" <?= ($childRow['person_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($personLabel($p)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a class="arbor-field-link" data-return-role="child" href="<?= htmlspecialchars($newPersonUrl('child')) ?>"><?= $newPersonText('child') ?></a>
                    </label>
                    <label>
                        <span>Relationship to parents</span>
                        <select class="uk-select" name="children[0][pedigree]">
                            <?php foreach ($childTypes as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($childRow['pedigree'] ?? 'birth') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            <?php elseif ($presetChildId): ?>
                <?php $presetChild = $children[0] ?? ['person_id' => $presetChildId, 'pedigree' => 'birth', 'birth_order' => 0]; ?>
                <div class="arbor-simple-grid arbor-union-child-summary">
                    <input type="hidden" name="children[0][id]" value="<?= htmlspecialchars($presetChild['id'] ?? '') ?>">
                    <input type="hidden" name="children[0][person_id]" value="<?= (int) ($presetChild['person_id'] ?? $presetChildId) ?>">
                    <input type="hidden" name="children[0][birth_order]" value="<?= (int) ($presetChild['birth_order'] ?? 0) ?>">
                    <label>
                        <span>Child</span>
                        <div class="arbor-static-field"><?= htmlspecialchars($presetChildName ?: 'Selected child') ?></div>
                    </label>
                    <label>
                        <span>Relationship to parents</span>
                        <select class="uk-select" name="children[0][pedigree]">
                            <?php foreach ($childTypes as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($presetChild['pedigree'] ?? 'birth') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            <?php endif; ?>

            <?php if (!$parentsMode): ?>
                <div class="arbor-simple-grid">
                    <label>
                        <span><?= htmlspecialchars($relationshipLabel) ?></span>
                        <select id="u_type" class="uk-select" name="union_type">
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($union['union_type'] ?? 'unknown') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Marriage date</span>
                        <input id="u_date" class="uk-input" type="date" name="married_date" value="<?= htmlspecialchars((string)($union['married_date'] ?? '')) ?>">
                    </label>
                </div>

                <div class="arbor-line-checks">
                    <label><input class="uk-checkbox" type="checkbox" name="married_date_approx" value="1" <?= !empty($union['married_date_approx']) ? 'checked' : '' ?>> Marriage date is approximate</label>
                    <label><input class="uk-checkbox" type="checkbox" name="divorced" value="1" <?= !empty($union['divorced']) ? 'checked' : '' ?>> Divorced</label>
                </div>
            <?php endif; ?>

            <?php if ($addingChild): ?>
                <div class="arbor-inline-note">
                    <span uk-icon="icon: info"></span>
                    <span><?= $partner1Label ?> is already selected. Choose the child above or create a new one.</span>
                </div>
            <?php endif; ?>

            <details class="arbor-create-more" <?= $detailsOpen ?>>
                <summary><?= htmlspecialchars($detailsSummary) ?></summary>

                <?php if ($parentsMode): ?>
                    <div class="arbor-simple-grid">
                        <label>
                            <span><?= htmlspecialchars($relationshipLabel) ?></span>
                            <select id="u_type" class="uk-select" name="union_type">
                                <?php foreach ($types as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= ($union['union_type'] ?? 'unknown') === $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Marriage date</span>
                            <input id="u_date" class="uk-input" type="date" name="married_date" value="<?= htmlspecialchars((string)($union['married_date'] ?? '')) ?>">
                        </label>
                    </div>

                    <div class="arbor-line-checks">
                        <label><input class="uk-checkbox" type="checkbox" name="married_date_approx" value="1" <?= !empty($union['married_date_approx']) ? 'checked' : '' ?>> Marriage date is approximate</label>
                        <label><input class="uk-checkbox" type="checkbox" name="divorced" value="1" <?= !empty($union['divorced']) ? 'checked' : '' ?>> Divorced</label>
                    </div>
                <?php endif; ?>

                <?php if ($showChildTable): ?>
                    <h4>Children</h4>
                    <?php if ($addingChild): ?>
                        <p class="uk-text-meta uk-margin-small-top">
                            <a class="arbor-field-link" data-return-role="child" href="<?= htmlspecialchars($newPersonUrl('child')) ?>"><?= $newPersonText('child') ?></a>
                        </p>
                    <?php endif; ?>
                    <div class="arbor-repeater">
                        <table class="uk-table uk-table-small uk-table-middle arbor-children">
                            <thead>
                                <tr>
                                    <th>Child</th>
                                    <th>Relationship</th>
                                    <th>Order</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $rows = $children ?: [[]]; foreach ($rows as $i => $c): ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="children[<?= $i ?>][id]" value="<?= $c['id'] ?? '' ?>">
                                        <select class="uk-select uk-form-small" name="children[<?= $i ?>][person_id]">
                                            <option value="">Choose a child</option>
                                            <?php foreach ($persons as $p): ?>
                                                <?php if ($addingChild && in_array((int) $p['id'], [$partner1Id, $partner2Id], true)) continue; ?>
                                                <option value="<?= $p['id'] ?>" <?= ($c['person_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($personLabel($p)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="uk-select uk-form-small" name="children[<?= $i ?>][pedigree]">
                                            <?php foreach ($childTypes as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= ($c['pedigree'] ?? 'birth') === $value ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input class="uk-input uk-form-small" type="number" min="0" name="children[<?= $i ?>][birth_order]" value="<?= (int)($c['birth_order'] ?? 0) ?>"></td>
                                    <td class="arbor-del">
                                        <label><input class="uk-checkbox" type="checkbox" name="children[<?= $i ?>][_delete]" value="1"></label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="uk-button uk-button-default uk-margin-small-top arbor-add-row" data-target=".arbor-children tbody">
                        <span uk-icon="icon: plus"></span> Add child
                    </button>
                <?php endif; ?>

                <div class="arbor-simple-grid uk-margin-top">
                    <label>
                        <span>Divorce date</span>
                        <input id="u_div_date" class="uk-input" type="date" name="divorced_date" value="<?= htmlspecialchars((string)($union['divorced_date'] ?? '')) ?>">
                    </label>
                </div>
                <label class="arbor-simple-full">
                    <span>Notes</span>
                    <textarea id="u_notes" class="uk-textarea" name="notes" rows="4"><?= htmlspecialchars((string)($union['notes'] ?? '')) ?></textarea>
                </label>
            </details>
        </div>
    </div>
</section>

<div class="arbor-form-actions">
    <button type="submit" name="save" value="1" class="uk-button uk-button-primary"<?= $saveRequirement ? ' data-requires="' . htmlspecialchars($saveRequirement) . '"' : '' ?>>
        <span uk-icon="icon: check"></span> <?= htmlspecialchars($saveLabel) ?>
    </button>
    <?php if ($saveRequirementText): ?>
        <span class="arbor-save-hint"><?= htmlspecialchars($saveRequirementText) ?></span>
    <?php endif; ?>
    <a class="uk-button uk-button-text" href="<?= htmlspecialchars($backUrl) ?>">Cancel</a>
</div>
</form>
</div>
