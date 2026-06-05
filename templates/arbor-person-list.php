<?php namespace ProcessWire;

/** @var array $tree */
/** @var array $persons */
/** @var string $search */
/** @var string $filter */
/** @var string $baseUrl */
$filterLabels = [
    'missing_parents' => 'Missing parents',
    'missing_birth_date' => 'Missing birth date',
];
$clearUrl = $baseUrl . 'persons/?tree=' . (int) $tree['id'];
?>
<div class="pw-wrap Arbor">
<h2>People in <?= htmlspecialchars($tree['name']) ?></h2>

<form class="arbor-search" method="get">
    <input type="hidden" name="tree" value="<?= (int) $tree['id'] ?>">
    <?php if ($filter): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
    <input class="uk-input uk-form-small" type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or family name">
    <button type="submit" class="uk-button uk-button-default">
        <span uk-icon="icon: search"></span> Search
    </button>
    <?php if ($search || $filter): ?>
        <a class="uk-button uk-button-text" href="<?= $clearUrl ?>">Clear</a>
    <?php endif; ?>
</form>

<?php if ($filter): ?>
    <div class="arbor-filter-note">
        Showing: <strong><?= htmlspecialchars($filterLabels[$filter] ?? $filter) ?></strong>
        <a href="<?= $clearUrl ?>">show all people</a>
    </div>
<?php endif; ?>

<div class="arbor-toolbar">
    <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>person/?tree=<?= $tree['id'] ?>">
        <span uk-icon="icon: plus"></span> Add person
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>viewer/?tree=<?= $tree['id'] ?>">
        <span uk-icon="icon: image"></span> Tree viewer
    </a>
</div>

<?php if (empty($persons)): ?>
    <div class="arbor-empty">
        <span uk-icon="icon: user; ratio: 3"></span>
        <?php if ($search): ?>
            <h4>No people match &ldquo;<?= htmlspecialchars($search) ?>&rdquo;</h4>
            <p>Try part of a first name, last name, or another spelling.</p>
            <a class="uk-button uk-button-text" href="<?= $clearUrl ?>">Clear search</a>
        <?php elseif ($filter): ?>
            <h4>No people in this check</h4>
            <p>This part looks clean.</p>
            <a class="uk-button uk-button-text" href="<?= $clearUrl ?>">Show all people</a>
        <?php else: ?>
            <h4>No people in this tree yet</h4>
            <p>Add yourself, a parent, or an ancestor.</p>
            <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>person/?tree=<?= $tree['id'] ?>">
                <span uk-icon="icon: plus"></span> Add first person
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <table class="uk-table uk-table-divider uk-table-hover uk-table-small">
        <thead>
            <tr>
                <th>Name</th>
                <th class="uk-width-small">Middle name</th>
                <th class="uk-width-small">Birth date</th>
                <th class="uk-width-small">Sex</th>
                <th class="uk-width-small">Reference</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($persons as $p): ?>
                <?php
                    $name = trim(($p['given'] ?? '') . ' ' . ($p['surname'] ?? ''));
                    $birthDate = (string) ($p['birth_date'] ?? '');
                    $birthLabel = $birthDate ? (!empty($p['birth_date_approx']) ? 'About ' . $birthDate : $birthDate) : '';
                ?>
                <tr>
                    <td>
                        <a class="uk-link-heading" href="<?= $baseUrl ?>person/?id=<?= $p['id'] ?>">
                            <?= htmlspecialchars($name) ?: '#' . $p['id'] ?>
                        </a>
                        <?php if (!empty($p['is_cohen'])): ?> <span class="uk-label">Cohen</span><?php endif; ?>
                        <?php if (!empty($p['is_levi'])): ?> <span class="uk-label">Levi</span><?php endif; ?>
                    </td>
                    <td class="uk-text-muted"><?= htmlspecialchars((string)($p['patronymic'] ?? '')) ?></td>
                    <td class="uk-text-muted"><?= htmlspecialchars($birthLabel) ?></td>
                    <td><?= htmlspecialchars($p['sex']) ?></td>
                    <td class="uk-text-small uk-text-muted"><?= htmlspecialchars($p['refn']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
