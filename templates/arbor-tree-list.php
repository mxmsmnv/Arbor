<?php namespace ProcessWire;

/** @var array $trees */
/** @var array|null $tree */
/** @var array|null $counts */
/** @var bool|null $overview */
/** @var array|null $totals */
/** @var array|null $treeStats */
/** @var array|null $recentPersons */
/** @var array|null $openTasks */
/** @var array|null $openQuestions */
/** @var array|null $recentResearch */
/** @var string $baseUrl */

$entityLabels = [
    'arbor_persons' => 'People',
    'arbor_unions' => 'Families',
    'arbor_events' => 'Events',
    'arbor_places' => 'Places',
    'arbor_sources' => 'Sources',
    'arbor_repositories' => 'Archives',
    'arbor_photos' => 'Photos',
    'arbor_documents' => 'Documents',
];
?>
<div class="pw-wrap Arbor">

<?php if (!empty($overview) && !empty($tree)): /* ====================== single-tree overview ====================== */ ?>

    <?php if ($tree['description']): ?>
        <p class="uk-text-muted"><?= nl2br(htmlspecialchars($tree['description'])) ?></p>
    <?php endif; ?>

    <div class="arbor-toolbar">
        <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>person/?tree=<?= $tree['id'] ?>">
            <span uk-icon="icon: plus"></span> Add person
        </a>
        <a class="uk-button uk-button-default" href="<?= $baseUrl ?>viewer/?tree=<?= $tree['id'] ?>">
            <span uk-icon="icon: image"></span> Tree viewer
        </a>
        <a class="uk-button uk-button-default" href="<?= $baseUrl ?>tree-edit/?id=<?= $tree['id'] ?>">
            <span uk-icon="icon: pencil"></span> Edit
        </a>
        <a class="uk-button uk-button-default" href="<?= $baseUrl ?>import/?tree=<?= $tree['id'] ?>">
            <span uk-icon="icon: upload"></span> Import family file
        </a>
        <a class="uk-button uk-button-default" href="<?= $baseUrl ?>export/?tree=<?= $tree['id'] ?>">
            <span uk-icon="icon: download"></span> Export family file
        </a>
        <a class="uk-button uk-button-danger" href="<?= $baseUrl ?>tree-delete/?id=<?= $tree['id'] ?>" style="margin-left:auto">
            <span uk-icon="icon: trash"></span> Delete tree
        </a>
    </div>

    <div class="arbor-overview-grid">
        <?php
        $sections = [
            'persons'  => ['label' => 'People',       'count_key' => 'arbor_persons'],
            'families' => ['label' => 'Families',     'count_key' => 'arbor_unions'],
            'places'   => ['label' => 'Places',       'count_key' => 'arbor_places'],
            'sources'  => ['label' => 'Sources',      'count_key' => 'arbor_sources'],
            'repos'    => ['label' => 'Archives', 'count_key' => 'arbor_repositories'],
            'research' => ['label' => 'Research',     'count_key' => null],
            'dna'      => ['label' => 'DNA',          'count_key' => null],
        ];
        foreach ($sections as $slug => $cfg):
            $count = $cfg['count_key'] && isset($counts[$cfg['count_key']]) ? (int) $counts[$cfg['count_key']] : null;
        ?>
            <a class="arbor-overview-card" href="<?= $baseUrl ?><?= $slug ?>/?tree=<?= $tree['id'] ?>">
                <?php if ($count !== null): ?>
                    <div class="arbor-overview-count"><?= $count ?></div>
                <?php endif; ?>
                <div class="arbor-overview-label"><?= htmlspecialchars($cfg['label']) ?></div>
            </a>
        <?php endforeach; ?>
    </div>

<?php else: /* ====================== personal dashboard ====================== */ ?>

    <?php if (empty($trees)): /* -------- empty state -------- */ ?>
        <div class="uk-card uk-card-default uk-card-body arbor-welcome">
            <div class="uk-text-center">
                <span uk-icon="icon: tree; ratio: 3" class="uk-text-primary"></span>
                <h3>Welcome to Arbor</h3>
                <p class="uk-text-muted">
                    Build a family tree, keep notes about people and places, attach sources,
                    and come back to open questions when you are ready.
                </p>
                <p class="uk-margin-top">
                    <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>tree-edit/">
                        <span uk-icon="icon: plus"></span> Create your first tree
                    </a>
                </p>
                <p class="uk-text-meta">
                    A simple start is one tree for your family, or one tree per family line.
                </p>
            </div>
        </div>

    <?php else: /* -------- has trees -------- */ ?>

        <div uk-grid class="uk-grid-small arbor-dashboard-main">

            <!-- Trees column -->
            <div class="uk-width-2-3@m">
                <div class="arbor-section-head">
                    <h3 class="uk-h4 uk-margin-remove">Your trees</h3>
                    <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>tree-edit/">
                        <span uk-icon="icon: plus"></span> Add tree
                    </a>
                </div>

                <div class="arbor-tree-stack">
                    <?php foreach ($trees as $t):
                        $s = $treeStats[$t['id']] ?? [];
                        $recent = $recentByTree[$t['id']] ?? []; ?>
                        <article class="uk-card uk-card-default arbor-tree-card-lg">
                            <div class="arbor-tree-card-body">
                                <header class="arbor-tree-card-head">
                                    <div>
                                        <a class="arbor-tree-card-title" href="<?= $baseUrl ?>tree/?id=<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></a>
                                        <?php if ($t['description']): ?>
                                            <p class="uk-text-meta uk-margin-remove"><?= htmlspecialchars(substr($t['description'], 0, 180)) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($t['is_public']): ?>
                                        <span class="uk-label uk-label-success">Public</span>
                                    <?php endif; ?>
                                </header>

                                <ul class="arbor-tree-card-stats">
                                    <li><strong><?= (int)($s['persons']   ?? 0) ?></strong> <span>persons</span></li>
                                    <li><strong><?= (int)($s['unions']    ?? 0) ?></strong> <span>families</span></li>
                                    <li><strong><?= (int)($s['places']    ?? 0) ?></strong> <span>places</span></li>
                                    <li><strong><?= (int)($s['sources']   ?? 0) ?></strong> <span>sources</span></li>
                                    <li><strong><?= (int)($s['photos']    ?? 0) ?></strong> <span>photos</span></li>
                                    <li><strong><?= (int)($s['documents'] ?? 0) ?></strong> <span>documents</span></li>
                                </ul>

                                <?php if (!empty($recent)): ?>
                                    <div class="arbor-tree-card-recent">
                                        <span class="uk-text-meta">Recently edited:</span>
                                        <?php $links = []; foreach ($recent as $p):
                                            $name = trim(($p['given'] ?? '') . ' ' . ($p['surname'] ?? '')) ?: '#' . $p['id'];
                                            $links[] = '<a href="' . $baseUrl . 'person/?id=' . $p['id'] . '">' . htmlspecialchars($name) . '</a>';
                                        endforeach; ?>
                                        <?= implode(' · ', $links) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <footer class="arbor-tree-card-foot">
                                <div class="uk-text-meta">
                                    <?= $t['modified'] ? 'Updated ' . date('Y-m-d', $t['modified']) : 'Created today' ?>
                                </div>
                                <div class="arbor-tree-card-actions">
                                    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>person/?tree=<?= $t['id'] ?>">
                                        <span uk-icon="icon: plus"></span> Add person
                                    </a>
                                    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>viewer/?tree=<?= $t['id'] ?>">Viewer</a>
                                    <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>tree/?id=<?= $t['id'] ?>">Open</a>
                                </div>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Side panel -->
            <div class="uk-width-1-3@m">
                <?php if (!empty($openTasks)): ?>
                    <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                        <h3 class="uk-card-title"><span uk-icon="icon: bookmark"></span> Open tasks</h3>
                        <ul class="uk-list uk-list-divider uk-margin-remove">
                            <?php foreach ($openTasks as $t):
                                $cls = match ($t['priority']) {
                                    'urgent' => 'danger',
                                    'high'   => 'warning',
                                    default  => '',
                                }; ?>
                                <li>
                                    <?php if ($cls): ?><span class="uk-label uk-label-<?= $cls ?>"><?= htmlspecialchars($t['priority']) ?></span> <?php endif; ?>
                                    <?= htmlspecialchars($t['title']) ?>
                                    <?php if ($t['due_date']): ?><div class="uk-text-meta">due <?= $t['due_date'] ?></div><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <?php if (!empty($openQuestions)): ?>
                    <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                        <h3 class="uk-card-title"><span uk-icon="icon: question"></span> Open questions</h3>
                        <ul class="uk-list uk-list-divider uk-margin-remove">
                        <?php foreach ($openQuestions as $q): ?>
                            <li>
                                <a href="<?= $baseUrl ?>research/?tree=<?= $q['tree_id'] ?>">
                                    <?= htmlspecialchars(substr($q['question'], 0, 100)) ?>
                                </a>
                                <?php if ($q['opened_date']): ?><div class="uk-text-meta">opened <?= $q['opened_date'] ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
                    <h3 class="uk-card-title"><span uk-icon="icon: info"></span> What is Arbor?</h3>
                    <p class="uk-text-small uk-margin-remove">
                        A private workspace for family trees: people, families, places,
                        documents, photos, notes, and open questions in one place.
                    </p>
                </section>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($apiCatalog)): ?>
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card arbor-api-card">
            <details>
                <summary><span uk-icon="icon: code"></span> Developer tools</summary>
                <p class="uk-text-meta">
                    Base path <code><?= htmlspecialchars($apiCatalog['base']) ?></code>
                    · <a href="<?= htmlspecialchars($apiCatalog['base']) ?>" target="_blank" rel="noopener">open JSON</a>
                    · <?= htmlspecialchars($apiCatalog['auth']) ?>
                </p>

            <ul uk-accordion="multiple: true" class="arbor-api-accordion">
                <?php foreach ($apiCatalog['groups'] as $i => $group): ?>
                    <li class="<?= $i === 0 ? 'uk-open' : '' ?>">
                        <a class="uk-accordion-title" href="#"><?= htmlspecialchars($group['name']) ?>
                            <span class="uk-text-meta">(<?= count($group['endpoints']) ?>)</span>
                        </a>
                        <div class="uk-accordion-content">
                            <table class="uk-table uk-table-small uk-table-divider uk-margin-remove arbor-api-table">
                                <tbody>
                                <?php foreach ($group['endpoints'] as $ep):
                                    $cls = match ($ep['method']) {
                                        'GET'    => 'arbor-api-get',
                                        'POST'   => 'arbor-api-post',
                                        'PUT'    => 'arbor-api-put',
                                        'DELETE' => 'arbor-api-delete',
                                        default  => '',
                                    }; ?>
                                    <tr>
                                        <td class="uk-width-small"><span class="arbor-api-method <?= $cls ?>"><?= $ep['method'] ?></span></td>
                                        <td><code><?= htmlspecialchars($ep['path']) ?></code></td>
                                        <td class="uk-text-muted uk-text-small"><?= htmlspecialchars($ep['desc']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            </details>
        </section>
    <?php endif; ?>

<?php endif; ?>

</div>
