<?php namespace ProcessWire;

/** @var array $tree */
/** @var array $counts */
/** @var array $recentPersons */
/** @var array $recentFamilies */
/** @var array $recentSources */
/** @var array $openTasks */
/** @var array $openQuestions */
/** @var array $qualityChecks */
/** @var string $baseUrl */
/** @var Arbor $arbor */

$tid       = (int) $tree['id'];
$hasAnyone = (int) ($counts['arbor_persons'] ?? 0) > 0;
$familyTypeLabels = [
    'married_civil' => 'Married',
    'married_religious_jewish' => 'Jewish religious marriage',
    'married_religious_christian' => 'Christian religious marriage',
    'married_religious_muslim' => 'Muslim religious marriage',
    'married_religious_other' => 'Other religious marriage',
    'common_law' => 'Common-law partners',
    'civil_union' => 'Civil union',
    'partnered' => 'Partners',
    'unmarried_with_children' => 'Unmarried with children',
    'engaged' => 'Engaged',
    'unknown' => 'Relationship unknown',
];
?>
<div class="pw-wrap Arbor">

<?php if ($tree['description']): ?>
    <p class="uk-text-muted uk-margin-small-top"><?= nl2br(htmlspecialchars($tree['description'])) ?></p>
<?php endif; ?>

<div class="arbor-toolbar">
    <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>person/?tree=<?= $tid ?>">
        <span uk-icon="icon: plus"></span> Add person
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>union/?tree=<?= $tid ?>">
        <span uk-icon="icon: users"></span> Add family
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>viewer/?tree=<?= $tid ?>">
        <span uk-icon="icon: image"></span> Tree viewer
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>import/?tree=<?= $tid ?>">
        <span uk-icon="icon: upload"></span> Import family file
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>export/?tree=<?= $tid ?>">
        <span uk-icon="icon: download"></span> Export family file
    </a>
    <a class="uk-button uk-button-text" href="<?= $baseUrl ?>tree-edit/?id=<?= $tid ?>">
        <span uk-icon="icon: pencil"></span> Edit
    </a>
    <a class="uk-button uk-button-danger" href="<?= $baseUrl ?>tree-delete/?id=<?= $tid ?>" style="margin-left:auto">
        <span uk-icon="icon: trash"></span> Delete tree
    </a>
</div>

<div class="arbor-overview-grid">
    <?php
    $sections = [
        ['slug' => 'persons',  'label' => 'People',       'icon' => 'user',     'key' => 'arbor_persons'],
        ['slug' => 'families', 'label' => 'Families',     'icon' => 'heart',    'key' => 'arbor_unions'],
        ['slug' => 'places',   'label' => 'Places',       'icon' => 'location', 'key' => 'arbor_places'],
        ['slug' => 'sources',  'label' => 'Sources',      'icon' => 'file-text','key' => 'arbor_sources'],
        ['slug' => 'repos',    'label' => 'Archives',     'icon' => 'album',    'key' => 'arbor_repositories'],
        ['slug' => 'documents','label' => 'Documents',    'icon' => 'copy',     'key' => 'arbor_documents'],
        ['slug' => 'photos',   'label' => 'Photos',       'icon' => 'image',    'key' => 'arbor_photos'],
        ['slug' => 'research', 'label' => 'Research',     'icon' => 'question', 'key' => 'arbor_research_questions'],
        ['slug' => 'research', 'label' => 'Search log',   'icon' => 'history',  'key' => 'arbor_research_log'],
        ['slug' => 'research', 'label' => 'Conclusions',  'icon' => 'file-edit','key' => 'arbor_proof_arguments'],
        ['slug' => 'dna',      'label' => 'DNA',          'icon' => 'bolt',     'key' => 'arbor_dna_kits'],
    ];
    foreach ($sections as $s):
        $count = (int) ($counts[$s['key']] ?? 0);
    ?>
        <a class="arbor-overview-card" href="<?= $baseUrl ?><?= $s['slug'] ?>/?tree=<?= $tid ?>">
            <div class="arbor-overview-icon"><span uk-icon="icon: <?= $s['icon'] ?>; ratio: 1.2"></span></div>
            <div class="arbor-overview-count"><?= $count ?></div>
            <div class="arbor-overview-label"><?= htmlspecialchars($s['label']) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<div uk-grid class="uk-grid-small arbor-overview-cols">

    <!-- main column -->
    <div class="uk-width-2-3@l">

        <!-- Recent persons -->
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: user"></span> People
                <?php if ($hasAnyone): ?>
                    <a class="uk-text-meta uk-text-normal arbor-section-link" href="<?= $baseUrl ?>persons/?tree=<?= $tid ?>">view all <?= (int) $counts['arbor_persons'] ?></a>
                <?php endif; ?>
            </h3>
            <?php if (empty($recentPersons)): ?>
                <div class="arbor-empty">
                    <span uk-icon="icon: user; ratio: 2.5"></span>
                    <h4>No people yet</h4>
                    <p>Start with yourself, a parent or an ancestor. You can always add relations later.</p>
                    <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>person/?tree=<?= $tid ?>">
                        <span uk-icon="icon: plus"></span> Add first person
                    </a>
                </div>
            <?php else: ?>
                <ul class="uk-list uk-list-divider uk-margin-remove">
                <?php foreach ($recentPersons as $p):
                    $name = trim(($p['given'] ?? '') . ' ' . ($p['surname'] ?? '')) ?: '#' . $p['id']; ?>
                    <li class="arbor-person-row">
                        <span class="arbor-person-sex arbor-sex-<?= strtolower($p['sex']) ?>" title="<?= $p['sex'] === 'M' ? 'Male' : ($p['sex'] === 'F' ? 'Female' : 'Unknown') ?>"></span>
                        <a class="uk-link-heading arbor-person-name" href="<?= $baseUrl ?>person/?id=<?= $p['id'] ?>"><?= htmlspecialchars($name) ?></a>
                        <span class="uk-text-meta arbor-person-meta">
                            <?= $p['modified'] ? date('Y-m-d', $p['modified']) : '' ?>
                            <?php if (!$p['is_alive']): ?> · <span class="uk-text-muted">†</span><?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- Families -->
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: heart"></span> Families
                <?php if (!empty($recentFamilies)): ?>
                    <a class="uk-text-meta uk-text-normal arbor-section-link" href="<?= $baseUrl ?>families/?tree=<?= $tid ?>">view all <?= (int) $counts['arbor_unions'] ?></a>
                <?php endif; ?>
            </h3>
            <?php if (empty($recentFamilies)): ?>
                <div class="arbor-empty">
                    <span uk-icon="icon: heart; ratio: 2.5"></span>
                    <h4>No families yet</h4>
                    <p>A family links two partners and their children. <?= $hasAnyone ? 'Connect existing people into families.' : 'Add at least two people first.' ?></p>
                    <?php if ($hasAnyone): ?>
                        <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>union/?tree=<?= $tid ?>">
                            <span uk-icon="icon: plus"></span> Add first family
                        </a>
                    <?php else: ?>
                        <a class="uk-button uk-button-default" href="<?= $baseUrl ?>person/?tree=<?= $tid ?>">
                            <span uk-icon="icon: plus"></span> Add person first
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <ul class="uk-list uk-list-divider uk-margin-remove">
                <?php foreach ($recentFamilies as $u): ?>
                    <li class="arbor-family-row">
                        <a class="uk-link-heading" href="<?= $baseUrl ?>union/?id=<?= $u['id'] ?>">
                            <?= htmlspecialchars($u['partner1_name'] ?: '?') ?>
                            <span class="uk-text-muted">×</span>
                            <?= htmlspecialchars($u['partner2_name'] ?: '?') ?>
                        </a>
                        <span class="uk-text-meta">
                            <?= htmlspecialchars($familyTypeLabels[$u['union_type']] ?? str_replace('_', ' ', (string) $u['union_type'])) ?>
                            <?php if ($u['married_date']): ?> · m. <?= htmlspecialchars((string) $u['married_date']) ?><?php endif; ?>
                            <?php if (!empty($u['divorced'])): ?> · <span class="uk-text-danger">div.</span><?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- Sources -->
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title">
                <span uk-icon="icon: file-text"></span> Sources
                <?php if (!empty($recentSources)): ?>
                    <a class="uk-text-meta uk-text-normal arbor-section-link" href="<?= $baseUrl ?>sources/?tree=<?= $tid ?>">view all <?= (int) $counts['arbor_sources'] ?></a>
                <?php endif; ?>
            </h3>
            <?php if (empty($recentSources)): ?>
                <div class="arbor-empty">
                    <span uk-icon="icon: file-text; ratio: 2.5"></span>
                    <h4>No sources yet</h4>
                    <p>Add the documents, books, websites, or letters that support what you know.</p>
                    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>source/?tree=<?= $tid ?>">
                        <span uk-icon="icon: plus"></span> Add first source
                    </a>
                </div>
            <?php else: ?>
                <ul class="uk-list uk-list-divider uk-margin-remove">
                <?php foreach ($recentSources as $s):
                    $archive = trim((string) ($s['archive_abbrev'] ?: '')); ?>
                    <li>
                        <a class="uk-link-heading" href="<?= $baseUrl ?>source/?id=<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?: 'Untitled #' . $s['id'] ?></a>
                        <span class="uk-text-meta">
                            <span><?= htmlspecialchars(str_replace('_', ' ', (string) $s['source_type'])) ?></span>
                            <?php if ($archive): ?> · <?= htmlspecialchars($archive) ?><?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <!-- side column -->
    <div class="uk-width-1-3@l">

        <?php if (!$hasAnyone): ?>
            <!-- onboarding guide for empty tree -->
            <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card arbor-quickstart">
                <h3 class="uk-card-title"><span uk-icon="icon: bolt"></span> Quick start</h3>
                <ol class="uk-margin-remove">
                    <li><strong>Add yourself</strong> as the first person, with birth date and place.</li>
                    <li><strong>Add a parent</strong>, then connect them as a family.</li>
                    <li><strong>Add a source</strong> for important facts: certificate, book, website, or scan.</li>
                    <li><strong>Open the viewer</strong> to see the graph grow.</li>
                </ol>
                <p class="uk-text-meta uk-margin-small-top">Already have a family file from another tool?
                    <a href="<?= $baseUrl ?>import/?tree=<?= $tid ?>">Import it</a>.
                </p>
            </section>
        <?php endif; ?>

        <!-- Data quality -->
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title"><span uk-icon="icon: warning"></span> Next checks</h3>
            <?php if (empty($qualityChecks)): ?>
                <div class="arbor-empty arbor-empty-mini">
                    <span uk-icon="icon: check"></span>
                    <p>No checks yet.</p>
                </div>
            <?php else: ?>
                <ul class="uk-list uk-list-divider uk-margin-remove arbor-check-list">
                <?php foreach ($qualityChecks as $check):
                    $count = (int) ($check['count'] ?? 0);
                    $route = (string) ($check['route'] ?? 'tree');
                    $params = array_merge(['tree' => $tid], (array) ($check['params'] ?? []));
                    $href = $baseUrl . $route . '/?' . http_build_query($params);
                    $isDone = $count === 0;
                ?>
                    <li class="arbor-check-row <?= $isDone ? 'is-done' : '' ?>">
                        <a href="<?= htmlspecialchars($href) ?>">
                            <span class="arbor-check-count"><?= $count ?></span>
                            <span class="arbor-check-text">
                                <strong><?= htmlspecialchars((string) $check['label']) ?></strong>
                                <?php if (!empty($check['hint'])): ?>
                                    <small><?= htmlspecialchars((string) $check['hint']) ?></small>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- Open tasks -->
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title"><span uk-icon="icon: bookmark"></span> Open tasks</h3>
            <?php if (empty($openTasks)): ?>
                <div class="arbor-empty arbor-empty-mini">
                    <span uk-icon="icon: check"></span>
                    <p>No open tasks.</p>
                </div>
            <?php else: ?>
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
            <?php endif; ?>
        </section>

        <!-- Open research questions -->
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title"><span uk-icon="icon: question"></span> Open research questions</h3>
            <?php if (empty($openQuestions)): ?>
                <div class="arbor-empty arbor-empty-mini">
                    <span uk-icon="icon: question"></span>
                    <p>No open questions.</p>
                </div>
            <?php else: ?>
                <ul class="uk-list uk-list-divider uk-margin-remove">
                <?php foreach ($openQuestions as $q): ?>
                    <li>
                        <a href="<?= $baseUrl ?>research/?tree=<?= $tid ?>">
                            <?= htmlspecialchars(substr($q['question'], 0, 100)) ?>
                        </a>
                        <?php if ($q['opened_date']): ?><div class="uk-text-meta">opened <?= $q['opened_date'] ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- Quick links to other sections -->
        <section class="uk-card uk-card-default uk-card-body uk-card-small arbor-card">
            <h3 class="uk-card-title"><span uk-icon="icon: nut"></span> More</h3>
            <ul class="uk-list uk-margin-remove arbor-quicklinks">
                <li><a href="<?= $baseUrl ?>places/?tree=<?= $tid ?>"><span uk-icon="icon: location"></span> Places (<?= (int)$counts['arbor_places'] ?>)</a></li>
                <li><a href="<?= $baseUrl ?>repos/?tree=<?= $tid ?>"><span uk-icon="icon: album"></span> Archives (<?= (int)$counts['arbor_repositories'] ?>)</a></li>
                <li><a href="<?= $baseUrl ?>documents/?tree=<?= $tid ?>"><span uk-icon="icon: copy"></span> Documents (<?= (int)$counts['arbor_documents'] ?>)</a></li>
                <li><a href="<?= $baseUrl ?>photos/?tree=<?= $tid ?>"><span uk-icon="icon: image"></span> Photos (<?= (int)$counts['arbor_photos'] ?>)</a></li>
                <li><a href="<?= $baseUrl ?>research/?tree=<?= $tid ?>"><span uk-icon="icon: question"></span> Questions (<?= (int)$counts['arbor_research_questions'] ?>)</a></li>
                <li><a href="<?= $baseUrl ?>research/?tree=<?= $tid ?>"><span uk-icon="icon: history"></span> Search log (<?= (int)$counts['arbor_research_log'] ?>)</a></li>
                <li><a href="<?= $baseUrl ?>research/?tree=<?= $tid ?>"><span uk-icon="icon: file-edit"></span> Conclusions (<?= (int)$counts['arbor_proof_arguments'] ?>)</a></li>
                <li><a href="<?= $baseUrl ?>dna/?tree=<?= $tid ?>"><span uk-icon="icon: bolt"></span> DNA kits (<?= (int)$counts['arbor_dna_kits'] ?>)</a></li>
            </ul>
        </section>
    </div>
</div>

</div>
