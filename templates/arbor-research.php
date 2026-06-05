<?php namespace ProcessWire;

/** @var array $tree */
/** @var array $questions */
/** @var array $allQuestions */
/** @var array $logs */
/** @var array $proofs */
/** @var array $tasks */
/** @var array $persons */
/** @var array $sources */
/** @var array $repos */
/** @var bool $canCreatePlan */
/** @var string $taskView */
/** @var string $questionView */
/** @var string $logView */
/** @var string $proofView */
/** @var array $taskCounts */
/** @var array $questionCounts */
/** @var array $logCounts */
/** @var array $proofCounts */
/** @var array $questionActivity */
/** @var array $nextActions */
/** @var array $researchTotals */
/** @var array $logDefaults */
/** @var array $proofDefaults */
/** @var array $taskDefaults */
/** @var string $csrfInput */
$treeId = (int) $tree['id'];
$taskView = $taskView ?? 'open';
$questionView = $questionView ?? 'open';
$logView = $logView ?? 'all';
$proofView = $proofView ?? 'all';
$taskCounts = $taskCounts ?? ['all' => count($tasks), 'open' => 0, 'in_progress' => 0, 'done' => 0, 'cancelled' => 0];
$questionCounts = $questionCounts ?? ['all' => count($questions), 'open' => 0, 'answered' => 0, 'abandoned' => 0];
$logCounts = $logCounts ?? ['all' => count($logs), 'positive' => 0, 'negative' => 0, 'inconclusive' => 0];
$proofCounts = $proofCounts ?? ['all' => count($proofs), 'draft' => 0, 'final' => 0];
$questionActivity = $questionActivity ?? [];
$nextActions = $nextActions ?? ['questions_without_search' => 0, 'draft_conclusions' => 0, 'negative_searches' => 0, 'due_tasks' => 0];
$researchTotals = $researchTotals ?? ['hours' => 0, 'cost' => 0];
$allQuestions = $allQuestions ?? $questions;
$logDefaults = $logDefaults ?? ['question_id' => null, 'person_id' => null, 'repo_id' => null, 'source_id' => null, 'result' => 'inconclusive'];
$proofDefaults = $proofDefaults ?? ['question_id' => null, 'person_id' => null, 'status' => 'draft'];
$taskDefaults = $taskDefaults ?? ['title' => '', 'person_id' => null, 'source_id' => null, 'task_type' => 'general', 'priority' => 'medium'];
$researchUrl = fn(array $params = []) => $baseUrl . 'research/?' . http_build_query(array_merge([
    'tree' => $treeId,
    'task_view' => $taskView,
    'question_view' => $questionView,
    'log_view' => $logView,
    'proof_view' => $proofView,
], $params));
$personOptionsFor = function (?int $selected = null) use ($persons): string {
    $out = '<option value="">No person</option>';
    foreach ($persons as $p) {
        $personName = trim((string) ($p['given'] ?? '') . ' ' . (string) ($p['patronymic'] ?? '') . ' ' . (string) ($p['surname'] ?? '')) ?: 'Person #' . (int) $p['id'];
        $isSelected = $selected && (int) $p['id'] === $selected ? ' selected' : '';
        $out .= '<option value="' . (int) $p['id'] . '"' . $isSelected . '>' . htmlspecialchars($personName) . '</option>';
    }
    return $out;
};
$sourceOptionsFor = function (?int $selected = null) use ($sources): string {
    $out = '<option value="">No source</option>';
    foreach ($sources as $s) {
        $isSelected = $selected && (int) $s['id'] === $selected ? ' selected' : '';
        $out .= '<option value="' . (int) $s['id'] . '"' . $isSelected . '>' . htmlspecialchars($s['title']) . '</option>';
    }
    return $out;
};
$repoOptionsFor = function (?int $selected = null) use ($repos): string {
    $out = '<option value="">Not set</option>';
    foreach ($repos as $r) {
        $isSelected = $selected && (int) $r['id'] === $selected ? ' selected' : '';
        $out .= '<option value="' . (int) $r['id'] . '"' . $isSelected . '>' . htmlspecialchars($r['name']) . '</option>';
    }
    return $out;
};
$taskTypeLabels = [
    'general' => 'General',
    'parents' => 'Find parents',
    'source_review' => 'Review source',
    'document' => 'Find document',
    'dna' => 'DNA follow-up',
];
$priorityLabels = ['medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent', 'low' => 'Low'];
$taskStatusLabels = ['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];
$questionStatusLabels = ['open' => 'Open', 'answered' => 'Answered', 'abandoned' => 'Abandoned'];
$resultLabels = ['inconclusive' => 'Not sure yet', 'positive' => 'Found something', 'negative' => 'Nothing found'];
$sourceClassLabels = ['original' => 'Original', 'derivative' => 'Derivative', 'authored' => 'Authored'];
$infoClassLabels = ['primary' => 'Primary', 'secondary' => 'Secondary', 'indeterminate' => 'Not sure'];
$evidenceClassLabels = ['direct' => 'Direct', 'indirect' => 'Indirect', 'negative' => 'Negative'];
$personOptions = $personOptionsFor(null);
$sourceOptions = $sourceOptionsFor(null);
$repoOptions = $repoOptionsFor(null);
$questionOptionsFor = function (?int $selected = null) use ($allQuestions): string {
    $out = '<option value="">No question</option>';
    foreach ($allQuestions as $q) {
        $isSelected = $selected && (int) $q['id'] === $selected ? ' selected' : '';
        $out .= '<option value="' . (int) $q['id'] . '"' . $isSelected . '>' . htmlspecialchars(substr($q['question'], 0, 90)) . '</option>';
    }
    return $out;
};
$personNames = [];
foreach ($persons as $p) {
    $personNames[(int) $p['id']] = trim((string) ($p['given'] ?? '') . ' ' . (string) ($p['patronymic'] ?? '') . ' ' . (string) ($p['surname'] ?? '')) ?: 'Person #' . (int) $p['id'];
}
$sourceTitles = [];
foreach ($sources as $s) {
    $sourceTitles[(int) $s['id']] = (string) ($s['title'] ?? ('Source #' . (int) $s['id']));
}
$repoNames = [];
foreach ($repos as $r) {
    $repoNames[(int) $r['id']] = (string) ($r['name'] ?? ('Archive #' . (int) $r['id']));
}
$questionTitles = [];
foreach ($allQuestions as $q) {
    $questionTitles[(int) $q['id']] = (string) ($q['question'] ?? ('Question #' . (int) $q['id']));
}
$contextFor = function (array $row) use ($personNames, $sourceTitles, $repoNames, $questionTitles, $baseUrl): array {
    $context = [];
    if (!empty($row['question_id']) && isset($questionTitles[(int) $row['question_id']])) {
        $context[] = ['Question', substr($questionTitles[(int) $row['question_id']], 0, 90), ''];
    }
    if (!empty($row['person_id']) && isset($personNames[(int) $row['person_id']])) {
        $context[] = ['Person', $personNames[(int) $row['person_id']], $baseUrl . 'person/?id=' . (int) $row['person_id']];
    }
    if (!empty($row['repo_id']) && isset($repoNames[(int) $row['repo_id']])) {
        $context[] = ['Archive', $repoNames[(int) $row['repo_id']], $baseUrl . 'repo/?id=' . (int) $row['repo_id']];
    }
    if (!empty($row['source_id']) && isset($sourceTitles[(int) $row['source_id']])) {
        $context[] = ['Source', $sourceTitles[(int) $row['source_id']], $baseUrl . 'source/?id=' . (int) $row['source_id']];
    }
    return $context;
};
$renderContext = function (array $items): string {
    if (!$items) return '<span class="uk-text-muted">No context</span>';
    $parts = [];
    foreach ($items as $item) {
        [$label, $text, $url] = $item;
        $body = '<strong>' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars($text);
        $parts[] = $url
            ? '<a href="' . htmlspecialchars($url) . '">' . $body . '</a>'
            : '<span>' . $body . '</span>';
    }
    return implode(' <span class="uk-text-muted">·</span> ', $parts);
};
$logSearchUrl = function (array $row, ?int $questionId = null) use ($researchUrl): string {
    $params = [
        'log_view' => 'all',
        'log_result' => 'inconclusive',
    ];
    if ($questionId) $params['log_question'] = $questionId;
    if (!empty($row['person_id'])) $params['log_person'] = (int) $row['person_id'];
    if (!empty($row['repo_id'])) $params['log_repo'] = (int) $row['repo_id'];
    if (!empty($row['source_id'])) $params['log_source'] = (int) $row['source_id'];
    return $researchUrl($params) . '#arbor-log-form';
};
$writeConclusionUrl = function (array $row, ?int $questionId = null) use ($researchUrl): string {
    $params = [
        'proof_view' => 'draft',
        'proof_status' => 'draft',
    ];
    if ($questionId) $params['proof_question'] = $questionId;
    if (!empty($row['person_id'])) $params['proof_person'] = (int) $row['person_id'];
    return $researchUrl($params) . '#arbor-proof-form';
};
$createTaskUrl = function (array $row, string $title) use ($researchUrl): string {
    $params = [
        'task_view' => 'open',
        'task_title' => substr($title, 0, 180),
        'task_type' => !empty($row['source_id']) ? 'source_review' : 'general',
        'task_priority' => 'medium',
    ];
    if (!empty($row['person_id'])) $params['task_person'] = (int) $row['person_id'];
    if (!empty($row['source_id'])) $params['task_source'] = (int) $row['source_id'];
    return $researchUrl($params) . '#arbor-task-form';
};
?>
<div class="pw-wrap Arbor">

<p class="uk-text-muted">Keep the questions you are trying to answer, the searches you have already made, and the conclusions you trust.</p>

<div class="arbor-toolbar">
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>tree/?id=<?= $treeId ?>">
        <span uk-icon="icon: tree"></span> Tree overview
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>viewer/?tree=<?= $treeId ?>">
        <span uk-icon="icon: image"></span> Tree viewer
    </a>
    <?php if ($canCreatePlan): ?>
        <form method="post" class="arbor-inline-form">
            <?= $csrfInput ?>
            <button class="uk-button uk-button-primary" type="submit" name="create_research_plan" value="1">
                <span uk-icon="icon: list"></span> Create starter plan
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="arbor-research-summary">
    <a href="<?= htmlspecialchars($researchUrl(['task_view' => 'open'])) ?>">
        <strong><?= (int) ($taskCounts['open'] ?? 0) ?></strong>
        <span>Open tasks</span>
    </a>
    <a href="<?= htmlspecialchars($researchUrl(['question_view' => 'open'])) ?>">
        <strong><?= (int) ($questionCounts['open'] ?? 0) ?></strong>
        <span>Open questions</span>
    </a>
    <a href="<?= htmlspecialchars($researchUrl(['log_view' => 'all'])) ?>">
        <strong><?= (int) ($logCounts['all'] ?? 0) ?></strong>
        <span>Searches logged</span>
    </a>
    <a href="<?= htmlspecialchars($researchUrl(['log_view' => 'all'])) ?>">
        <strong><?= rtrim(rtrim(number_format((float) ($researchTotals['hours'] ?? 0), 1), '0'), '.') ?></strong>
        <span>Hours</span>
    </a>
    <a href="<?= htmlspecialchars($researchUrl(['log_view' => 'all'])) ?>">
        <strong>$<?= number_format((float) ($researchTotals['cost'] ?? 0), 2) ?></strong>
        <span>Cost</span>
    </a>
    <a href="<?= htmlspecialchars($researchUrl(['proof_view' => 'final'])) ?>">
        <strong><?= (int) ($proofCounts['final'] ?? 0) ?>/<?= (int) ($proofCounts['all'] ?? 0) ?></strong>
        <span>Final conclusions</span>
    </a>
</div>

<?php
$nextActionCards = array_filter([
    [
        'count' => (int) ($nextActions['questions_without_search'] ?? 0),
        'label' => 'Questions need first search',
        'text' => 'Start by logging at least one search.',
        'href' => $researchUrl(['question_view' => 'open']),
    ],
    [
        'count' => (int) ($nextActions['draft_conclusions'] ?? 0),
        'label' => 'Draft conclusions',
        'text' => 'Review evidence and mark final when ready.',
        'href' => $researchUrl(['proof_view' => 'draft']),
    ],
    [
        'count' => (int) ($nextActions['negative_searches'] ?? 0),
        'label' => 'Negative searches',
        'text' => 'Decide whether to retry elsewhere or create a follow-up task.',
        'href' => $researchUrl(['log_view' => 'negative']),
    ],
    [
        'count' => (int) ($nextActions['due_tasks'] ?? 0),
        'label' => 'Due tasks',
        'text' => 'Tasks due today or earlier.',
        'href' => $researchUrl(['task_view' => 'open']),
    ],
], fn($card) => $card['count'] > 0);
?>
<?php if ($nextActionCards): ?>
    <div class="arbor-next-actions">
        <strong>Next actions</strong>
        <div>
            <?php foreach ($nextActionCards as $card): ?>
                <a href="<?= htmlspecialchars($card['href']) ?>">
                    <span><?= (int) $card['count'] ?></span>
                    <b><?= htmlspecialchars($card['label']) ?></b>
                    <em><?= htmlspecialchars($card['text']) ?></em>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<span class="arbor-section-label">Tasks</span>
<?php
$taskTabs = [
    'open' => 'Open',
    'in_progress' => 'In progress',
    'done' => 'Done',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];
?>
<nav class="arbor-filter-tabs">
    <?php foreach ($taskTabs as $key => $label): ?>
        <a class="arbor-filter-tab<?= $taskView === $key ? ' is-active' : '' ?>" href="<?= htmlspecialchars($researchUrl(['task_view' => $key])) ?>">
            <span><?= htmlspecialchars($label) ?></span><strong><?= (int) ($taskCounts[$key] ?? 0) ?></strong>
        </a>
    <?php endforeach; ?>
</nav>
<details class="arbor-inline-editor" id="arbor-task-form" <?= !empty($taskDefaults['title']) || !empty($taskDefaults['person_id']) || !empty($taskDefaults['source_id']) ? 'open' : '' ?>>
    <summary>Add task</summary>
    <form class="arbor-document-edit" method="post">
        <?= $csrfInput ?>
        <label class="arbor-document-edit-full">
            <span>Task</span>
            <input class="uk-input uk-form-small" type="text" name="task_title" value="<?= htmlspecialchars((string) ($taskDefaults['title'] ?? ''), ENT_QUOTES) ?>" placeholder="What needs to happen?" required>
        </label>
        <label>
            <span>Type</span>
            <select class="uk-select uk-form-small" name="task_type">
                <?php foreach ($taskTypeLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= (string) ($taskDefaults['task_type'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Priority</span>
            <select class="uk-select uk-form-small" name="task_priority">
                <?php foreach ($priorityLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= (string) ($taskDefaults['priority'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <input type="hidden" name="task_status_edit" value="open">
        <label><span>Person</span><select class="uk-select uk-form-small" name="task_person_id"><?= $personOptionsFor(!empty($taskDefaults['person_id']) ? (int) $taskDefaults['person_id'] : null) ?></select></label>
        <label><span>Source</span><select class="uk-select uk-form-small" name="task_source_id"><?= $sourceOptionsFor(!empty($taskDefaults['source_id']) ? (int) $taskDefaults['source_id'] : null) ?></select></label>
        <label><span>Due date</span><input class="uk-input uk-form-small" type="date" name="task_due_date"></label>
        <label><span>Assigned to</span><input class="uk-input uk-form-small" type="text" name="task_assigned_to"></label>
        <label class="arbor-document-edit-full">
            <span>Notes</span>
            <textarea class="uk-textarea uk-form-small" rows="2" name="task_description" placeholder="Useful context, archive names, search terms, or next step."></textarea>
        </label>
        <button class="uk-button uk-button-primary uk-button-small" type="submit" name="add_research_task" value="1">
            <span uk-icon="icon: plus"></span> Add task
        </button>
    </form>
</details>
<?php if (empty($tasks)): ?>
    <div class="arbor-empty">
        <span uk-icon="icon: check; ratio: 3"></span>
        <h4><?= $taskView === 'open' ? 'No open tasks' : 'No tasks in this view' ?></h4>
        <p><?= $taskView === 'open' ? 'Everything actionable is clear right now.' : 'Choose another task status to review older work.' ?></p>
    </div>
<?php else: ?>
    <div class="arbor-list">
        <?php foreach ($tasks as $t):
            $meta = array_filter([
                ucfirst(str_replace('_', ' ', (string) $t['task_type'])),
                ucfirst((string) $t['priority']),
                ucfirst(str_replace('_', ' ', (string) $t['status'])),
                $t['due_date'] ?? null,
            ]);
            $taskContext = $contextFor($t);
        ?>
            <div class="arbor-list-row">
                <span class="arbor-list-main">
                    <?= htmlspecialchars($t['title']) ?>
                    <?php if ($taskContext): ?>
                        <br><span class="uk-text-small arbor-context-links"><?= $renderContext($taskContext) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($t['description'])): ?>
                        <br><em><?= htmlspecialchars(substr((string) $t['description'], 0, 180)) ?></em>
                    <?php endif; ?>
                    <details class="arbor-inline-editor">
                        <summary>Edit task</summary>
                        <form class="arbor-document-edit" method="post">
                            <?= $csrfInput ?>
                            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                            <label class="arbor-document-edit-full">
                                <span>Task</span>
                                <input class="uk-input uk-form-small" type="text" name="task_title" value="<?= htmlspecialchars((string) $t['title'], ENT_QUOTES) ?>" required>
                            </label>
                            <label>
                                <span>Type</span>
                                <select class="uk-select uk-form-small" name="task_type">
                                    <?php foreach ($taskTypeLabels as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= (string) $t['task_type'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Priority</span>
                                <select class="uk-select uk-form-small" name="task_priority">
                                    <?php foreach ($priorityLabels as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= (string) $t['priority'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Status</span>
                                <select class="uk-select uk-form-small" name="task_status_edit">
                                    <?php foreach ($taskStatusLabels as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= (string) $t['status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span>Person</span><select class="uk-select uk-form-small" name="task_person_id"><?= $personOptionsFor(!empty($t['person_id']) ? (int) $t['person_id'] : null) ?></select></label>
                            <label><span>Source</span><select class="uk-select uk-form-small" name="task_source_id"><?= $sourceOptionsFor(!empty($t['source_id']) ? (int) $t['source_id'] : null) ?></select></label>
                            <label><span>Due date</span><input class="uk-input uk-form-small" type="date" name="task_due_date" value="<?= htmlspecialchars((string) ($t['due_date'] ?? ''), ENT_QUOTES) ?>"></label>
                            <label><span>Assigned to</span><input class="uk-input uk-form-small" type="text" name="task_assigned_to" value="<?= htmlspecialchars((string) ($t['assigned_to'] ?? ''), ENT_QUOTES) ?>"></label>
                            <label class="arbor-document-edit-full">
                                <span>Notes</span>
                                <textarea class="uk-textarea uk-form-small" rows="2" name="task_description"><?= htmlspecialchars((string) ($t['description'] ?? '')) ?></textarea>
                            </label>
                            <button class="uk-button uk-button-default uk-button-small" type="submit" name="update_research_task" value="1">
                                <span uk-icon="icon: check"></span> Save task
                            </button>
                        </form>
                    </details>
                </span>
                <span class="arbor-list-meta"><?= htmlspecialchars(implode(' · ', $meta)) ?></span>
                <span class="arbor-row-actions">
                    <a class="uk-button uk-button-default uk-button-small" href="<?= htmlspecialchars($logSearchUrl($t)) ?>">
                        <span uk-icon="icon: history"></span> Log search
                    </a>
                    <?php if ($t['status'] === 'open'): ?>
                        <form method="post">
                            <?= $csrfInput ?>
                            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                            <button class="uk-button uk-button-default uk-button-small" type="submit" name="task_status" value="in_progress">Start</button>
                        </form>
                    <?php elseif ($t['status'] === 'in_progress'): ?>
                        <form method="post">
                            <?= $csrfInput ?>
                            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                            <button class="uk-button uk-button-default uk-button-small" type="submit" name="task_status" value="open">Pause</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($t['status'] !== 'done' && $t['status'] !== 'cancelled'): ?>
                        <form method="post">
                            <?= $csrfInput ?>
                            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                            <button class="uk-button uk-button-primary uk-button-small" type="submit" name="task_status" value="done">Done</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <?= $csrfInput ?>
                            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                            <button class="uk-button uk-button-default uk-button-small" type="submit" name="task_status" value="open">Reopen</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($t['status'], ['open', 'in_progress'], true)): ?>
                        <form method="post" onsubmit="return confirm('Cancel this task?')">
                            <?= $csrfInput ?>
                            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                            <button class="uk-button uk-button-text uk-text-muted" type="submit" name="task_status" value="cancelled">Cancel</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Delete this task?')">
                        <?= $csrfInput ?>
                        <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                        <button class="uk-button uk-button-text uk-text-danger" type="submit" name="delete_research_task" value="1" title="Delete task">
                            <span uk-icon="icon: trash"></span>
                        </button>
                    </form>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<span class="arbor-section-label">Questions</span>
<?php
$questionTabs = [
    'open' => 'Open',
    'answered' => 'Answered',
    'abandoned' => 'Abandoned',
    'all' => 'All',
];
?>
<nav class="arbor-filter-tabs">
    <?php foreach ($questionTabs as $key => $label): ?>
        <a class="arbor-filter-tab<?= $questionView === $key ? ' is-active' : '' ?>" href="<?= htmlspecialchars($researchUrl(['question_view' => $key])) ?>">
            <span><?= htmlspecialchars($label) ?></span><strong><?= (int) ($questionCounts[$key] ?? 0) ?></strong>
        </a>
    <?php endforeach; ?>
</nav>
<details class="arbor-inline-editor">
    <summary>Add question</summary>
    <form class="arbor-document-edit" method="post">
        <?= $csrfInput ?>
        <label class="arbor-document-edit-full">
            <span>Question</span>
            <textarea class="uk-textarea uk-form-small" rows="2" name="question" placeholder="What are you trying to prove?" required></textarea>
        </label>
        <label><span>Person</span><select class="uk-select uk-form-small" name="question_person_id"><?= $personOptions ?></select></label>
        <label><span>Opened</span><input class="uk-input uk-form-small" type="date" name="opened_date" value="<?= date('Y-m-d') ?>"></label>
        <input type="hidden" name="question_status_edit" value="open">
        <label class="arbor-document-edit-full">
            <span>Notes</span>
            <textarea class="uk-textarea uk-form-small" rows="2" name="question_notes" placeholder="Why this matters, what is known, and what would answer it."></textarea>
        </label>
        <button class="uk-button uk-button-primary uk-button-small" type="submit" name="add_research_question" value="1">
            <span uk-icon="icon: plus"></span> Add question
        </button>
    </form>
</details>
<?php if (empty($questions)): ?>
    <div class="arbor-empty">
        <span uk-icon="icon: question; ratio: 3"></span>
        <h4><?= $questionView === 'open' ? 'No open questions' : 'No questions in this view' ?></h4>
        <p><?= $questionView === 'open' ? 'Research questions are answered or closed.' : 'Choose another question status to review older work.' ?></p>
    </div>
<?php else: ?>
    <table class="uk-table uk-table-divider uk-table-small uk-table-hover">
        <thead><tr><th>Question</th><th>Context</th><th class="uk-width-small">Status</th><th class="uk-width-small">Opened</th><th class="uk-width-small">Closed</th><th class="uk-width-medium">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($questions as $q): ?>
            <?php
            $questionContext = $contextFor($q);
            $activity = $questionActivity[(int) $q['id']] ?? ['logs' => 0, 'proofs' => 0, 'finals' => 0];
            ?>
            <tr>
                <td>
                    <?= htmlspecialchars(substr($q['question'], 0, 200)) ?>
                    <div class="arbor-mini-metrics">
                        <span><?= (int) $activity['logs'] ?> searches</span>
                        <span><?= (int) $activity['proofs'] ?> conclusions</span>
                        <?php if ((int) $activity['finals'] > 0): ?>
                            <span><?= (int) $activity['finals'] ?> final</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($q['notes'])): ?>
                        <br><em><?= htmlspecialchars(substr((string) $q['notes'], 0, 180)) ?></em>
                    <?php endif; ?>
                    <details class="arbor-inline-editor">
                        <summary>Edit question</summary>
                        <form class="arbor-document-edit" method="post">
                            <?= $csrfInput ?>
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <label class="arbor-document-edit-full">
                                <span>Question</span>
                                <textarea class="uk-textarea uk-form-small" rows="2" name="question" required><?= htmlspecialchars((string) $q['question']) ?></textarea>
                            </label>
                            <label><span>Person</span><select class="uk-select uk-form-small" name="question_person_id"><?= $personOptionsFor(!empty($q['person_id']) ? (int) $q['person_id'] : null) ?></select></label>
                            <label>
                                <span>Status</span>
                                <select class="uk-select uk-form-small" name="question_status_edit">
                                    <?php foreach ($questionStatusLabels as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= (string) $q['status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span>Opened</span><input class="uk-input uk-form-small" type="date" name="opened_date" value="<?= htmlspecialchars((string) ($q['opened_date'] ?? ''), ENT_QUOTES) ?>"></label>
                            <label><span>Closed</span><input class="uk-input uk-form-small" type="date" name="closed_date" value="<?= htmlspecialchars((string) ($q['closed_date'] ?? ''), ENT_QUOTES) ?>"></label>
                            <label class="arbor-document-edit-full">
                                <span>Notes</span>
                                <textarea class="uk-textarea uk-form-small" rows="2" name="question_notes"><?= htmlspecialchars((string) ($q['notes'] ?? '')) ?></textarea>
                            </label>
                            <button class="uk-button uk-button-default uk-button-small" type="submit" name="update_research_question" value="1">
                                <span uk-icon="icon: check"></span> Save question
                            </button>
                        </form>
                    </details>
                </td>
                <td class="uk-text-small arbor-context-links">
                    <?= $renderContext($questionContext) ?>
                </td>
                <td>
                    <?php $cls = $q['status'] === 'answered' ? 'success' : ($q['status'] === 'abandoned' ? 'danger' : 'warning'); ?>
                    <span class="uk-label uk-label-<?= $cls ?>"><?= htmlspecialchars($q['status']) ?></span>
                </td>
                <td class="uk-text-muted uk-text-small"><?= htmlspecialchars((string)$q['opened_date']) ?></td>
                <td class="uk-text-muted uk-text-small"><?= htmlspecialchars((string)$q['closed_date']) ?></td>
                <td>
                    <div class="arbor-row-actions">
                        <a class="uk-button uk-button-default uk-button-small" href="<?= htmlspecialchars($logSearchUrl($q, (int) $q['id'])) ?>">
                            <span uk-icon="icon: history"></span> Log search
                        </a>
                        <a class="uk-button uk-button-default uk-button-small" href="<?= htmlspecialchars($writeConclusionUrl($q, (int) $q['id'])) ?>">
                            <span uk-icon="icon: file-edit"></span> Write conclusion
                        </a>
                        <a class="uk-button uk-button-default uk-button-small" href="<?= htmlspecialchars($createTaskUrl($q, 'Follow up: ' . (string) $q['question'])) ?>">
                            <span uk-icon="icon: plus"></span> Create task
                        </a>
                        <?php if ($q['status'] === 'open'): ?>
                            <form method="post">
                                <?= $csrfInput ?>
                                <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                                <button class="uk-button uk-button-primary uk-button-small" type="submit" name="question_status" value="answered">Answered</button>
                            </form>
                            <form method="post">
                                <?= $csrfInput ?>
                                <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                                <button class="uk-button uk-button-default uk-button-small" type="submit" name="question_status" value="abandoned">Abandon</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= $csrfInput ?>
                                <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                                <button class="uk-button uk-button-default uk-button-small" type="submit" name="question_status" value="open">Reopen</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Delete this question? Search log entries will stay, but will no longer point to this question.')">
                            <?= $csrfInput ?>
                            <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                            <button class="uk-button uk-button-text uk-text-danger" type="submit" name="delete_research_question" value="1" title="Delete question">
                                <span uk-icon="icon: trash"></span>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<span class="arbor-section-label">Search log</span>
<?php
$logTabs = [
    'all' => 'All',
    'positive' => 'Found',
    'negative' => 'Nothing found',
    'inconclusive' => 'Not sure',
];
?>
<nav class="arbor-filter-tabs">
    <?php foreach ($logTabs as $key => $label): ?>
        <a class="arbor-filter-tab<?= $logView === $key ? ' is-active' : '' ?>" href="<?= htmlspecialchars($researchUrl(['log_view' => $key])) ?>">
            <span><?= htmlspecialchars($label) ?></span><strong><?= (int) ($logCounts[$key] ?? 0) ?></strong>
        </a>
    <?php endforeach; ?>
</nav>
<form class="arbor-log-form" id="arbor-log-form" method="post">
    <?= $csrfInput ?>
    <div class="arbor-simple-grid">
        <label>
            <span>Date</span>
            <input class="uk-input" type="date" name="log_date" value="<?= date('Y-m-d') ?>">
        </label>
        <label>
            <span>Result</span>
            <select class="uk-select" name="result">
                <?php foreach ($resultLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= (string) ($logDefaults['result'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Question</span>
            <select class="uk-select" name="question_id">
                <?= $questionOptionsFor(!empty($logDefaults['question_id']) ? (int) $logDefaults['question_id'] : null) ?>
            </select>
        </label>
        <label>
            <span>Person</span>
            <select class="uk-select" name="person_id">
                <?= $personOptionsFor(!empty($logDefaults['person_id']) ? (int) $logDefaults['person_id'] : null) ?>
            </select>
        </label>
        <label>
            <span>Archive or website</span>
            <select class="uk-select" name="repo_id">
                <?= $repoOptionsFor(!empty($logDefaults['repo_id']) ? (int) $logDefaults['repo_id'] : null) ?>
            </select>
        </label>
        <label>
            <span>Source</span>
            <select class="uk-select" name="source_id">
                <?= $sourceOptionsFor(!empty($logDefaults['source_id']) ? (int) $logDefaults['source_id'] : null) ?>
            </select>
        </label>
    </div>
    <label class="arbor-simple-full">
        <span>What did you search?</span>
        <input class="uk-input" type="text" name="search_terms" placeholder="Example: Valentina Semenova birth record Odesa 1965" required>
    </label>
    <div class="arbor-simple-grid">
        <label>
            <span>Source class</span>
            <select class="uk-select" name="source_class">
                <?php foreach ($sourceClassLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Information</span>
            <select class="uk-select" name="info_class">
                <?php foreach ($infoClassLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Evidence</span>
            <select class="uk-select" name="evidence_class">
                <?php foreach ($evidenceClassLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Hours</span>
            <input class="uk-input" type="number" step="0.1" min="0" name="hours" value="0">
        </label>
        <label>
            <span>Cost</span>
            <input class="uk-input" type="number" step="0.01" min="0" name="cost" value="0">
        </label>
    </div>
    <label class="arbor-simple-full">
        <span>Notes</span>
        <textarea class="uk-textarea" name="notes" rows="2" placeholder="Where you searched, what you found, and what should happen next."></textarea>
    </label>
    <button class="uk-button uk-button-primary" type="submit" name="add_research_log" value="1">
        <span uk-icon="icon: plus"></span> Add log entry
    </button>
</form>

<?php if (empty($logs)): ?>
    <div class="arbor-empty">
        <span uk-icon="icon: history; ratio: 3"></span>
        <h4><?= $logView === 'all' ? 'No log entries yet' : 'No log entries in this view' ?></h4>
        <p><?= $logView === 'all' ? 'Save where you searched and what you found, even when the result was negative.' : 'Choose another result filter to review the rest of the log.' ?></p>
    </div>
<?php else: ?>
    <table class="uk-table uk-table-divider uk-table-small uk-table-hover">
        <thead>
            <tr>
                <th class="uk-width-small">Date</th>
                <th>What you searched</th>
                <th>Context</th>
                <th class="uk-width-small">Result</th>
                <th>Notes</th>
                <th>Comment</th>
                <th class="uk-width-small">Hours</th>
                <th class="uk-width-small">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
            <?php $logContext = $contextFor($l); ?>
            <tr>
                <td class="uk-text-muted"><?= htmlspecialchars((string)$l['log_date']) ?></td>
                <td><?= htmlspecialchars($l['search_terms']) ?></td>
                <td class="uk-text-small arbor-context-links">
                    <?= $renderContext($logContext) ?>
                </td>
                <td>
                    <?php $cls = $l['result'] === 'positive' ? 'success' : ($l['result'] === 'negative' ? 'danger' : 'warning'); ?>
                    <span class="uk-label uk-label-<?= $cls ?>"><?= htmlspecialchars($l['result']) ?></span>
                </td>
                <td class="uk-text-small">
                    <?= htmlspecialchars(trim(($l['source_class'] ?? '') . ' ' . ($l['info_class'] ?? '') . ' ' . ($l['evidence_class'] ?? ''))) ?>
                </td>
                <td class="uk-text-small"><?= htmlspecialchars(substr((string) ($l['notes'] ?? ''), 0, 180)) ?></td>
                <td><?= (float) $l['hours'] ?></td>
                <td>
                    <div class="arbor-row-actions">
                        <a class="uk-button uk-button-default uk-button-small" href="<?= htmlspecialchars($writeConclusionUrl($l, !empty($l['question_id']) ? (int) $l['question_id'] : null)) ?>">
                            <span uk-icon="icon: file-edit"></span> Write conclusion
                        </a>
                        <a class="uk-button uk-button-default uk-button-small" href="<?= htmlspecialchars($createTaskUrl($l, 'Follow up search: ' . (string) $l['search_terms'])) ?>">
                            <span uk-icon="icon: plus"></span> Create task
                        </a>
                        <details class="arbor-inline-editor">
                            <summary>Edit</summary>
                            <form class="arbor-document-edit" method="post">
                                <?= $csrfInput ?>
                                <input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                                <label><span>Date</span><input class="uk-input uk-form-small" type="date" name="log_date" value="<?= htmlspecialchars((string) $l['log_date'], ENT_QUOTES) ?>"></label>
                                <label>
                                    <span>Result</span>
                                    <select class="uk-select uk-form-small" name="result">
                                        <?php foreach ($resultLabels as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= (string) $l['result'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><span>Question</span><select class="uk-select uk-form-small" name="question_id"><?= $questionOptionsFor(!empty($l['question_id']) ? (int) $l['question_id'] : null) ?></select></label>
                                <label><span>Person</span><select class="uk-select uk-form-small" name="person_id"><?= $personOptionsFor(!empty($l['person_id']) ? (int) $l['person_id'] : null) ?></select></label>
                                <label><span>Archive or website</span><select class="uk-select uk-form-small" name="repo_id"><?= $repoOptionsFor(!empty($l['repo_id']) ? (int) $l['repo_id'] : null) ?></select></label>
                                <label><span>Source</span><select class="uk-select uk-form-small" name="source_id"><?= $sourceOptionsFor(!empty($l['source_id']) ? (int) $l['source_id'] : null) ?></select></label>
                                <label class="arbor-document-edit-full"><span>What did you search?</span><input class="uk-input uk-form-small" type="text" name="search_terms" value="<?= htmlspecialchars((string) $l['search_terms'], ENT_QUOTES) ?>" required></label>
                                <label>
                                    <span>Source class</span>
                                    <select class="uk-select uk-form-small" name="source_class">
                                        <?php foreach ($sourceClassLabels as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= (string) $l['source_class'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Information</span>
                                    <select class="uk-select uk-form-small" name="info_class">
                                        <?php foreach ($infoClassLabels as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= (string) $l['info_class'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Evidence</span>
                                    <select class="uk-select uk-form-small" name="evidence_class">
                                        <?php foreach ($evidenceClassLabels as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= (string) $l['evidence_class'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><span>Hours</span><input class="uk-input uk-form-small" type="number" step="0.1" min="0" name="hours" value="<?= htmlspecialchars((string) ($l['hours'] ?? 0), ENT_QUOTES) ?>"></label>
                                <label><span>Cost</span><input class="uk-input uk-form-small" type="number" step="0.01" min="0" name="cost" value="<?= htmlspecialchars((string) ($l['cost'] ?? 0), ENT_QUOTES) ?>"></label>
                                <label class="arbor-document-edit-full"><span>Notes</span><textarea class="uk-textarea uk-form-small" rows="2" name="notes"><?= htmlspecialchars((string) ($l['notes'] ?? '')) ?></textarea></label>
                                <button class="uk-button uk-button-default uk-button-small" type="submit" name="update_research_log" value="1"><span uk-icon="icon: check"></span> Save log</button>
                            </form>
                        </details>
                        <form method="post" onsubmit="return confirm('Delete this search log entry?')">
                            <?= $csrfInput ?>
                            <input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                            <button class="uk-button uk-button-text uk-text-danger" type="submit" name="delete_research_log" value="1" title="Delete log entry">
                                <span uk-icon="icon: trash"></span>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<span class="arbor-section-label">Conclusions</span>
<?php
$proofTabs = [
    'all' => 'All',
    'draft' => 'Draft',
    'final' => 'Final',
];
?>
<nav class="arbor-filter-tabs">
    <?php foreach ($proofTabs as $key => $label): ?>
        <a class="arbor-filter-tab<?= $proofView === $key ? ' is-active' : '' ?>" href="<?= htmlspecialchars($researchUrl(['proof_view' => $key])) ?>">
            <span><?= htmlspecialchars($label) ?></span><strong><?= (int) ($proofCounts[$key] ?? 0) ?></strong>
        </a>
    <?php endforeach; ?>
</nav>
<form class="arbor-log-form" id="arbor-proof-form" method="post">
    <?= $csrfInput ?>
    <div class="arbor-simple-grid">
        <label>
            <span>Title</span>
            <input class="uk-input" type="text" name="proof_title" placeholder="Example: Parents of Valentina Semenova" required>
        </label>
        <label>
            <span>Status</span>
            <select class="uk-select" name="proof_status">
                <option value="draft" <?= (string) ($proofDefaults['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="final" <?= (string) ($proofDefaults['status'] ?? '') === 'final' ? 'selected' : '' ?>>Final</option>
            </select>
            <small class="uk-text-muted">Final closes the linked question as answered.</small>
        </label>
        <label>
            <span>Question</span>
            <select class="uk-select" name="proof_question_id">
                <?= $questionOptionsFor(!empty($proofDefaults['question_id']) ? (int) $proofDefaults['question_id'] : null) ?>
            </select>
        </label>
        <label>
            <span>Person</span>
            <select class="uk-select" name="proof_person_id">
                <?= $personOptionsFor(!empty($proofDefaults['person_id']) ? (int) $proofDefaults['person_id'] : null) ?>
            </select>
        </label>
    </div>
    <label class="arbor-simple-full">
        <span>Evidence summary</span>
        <textarea class="uk-textarea" name="proof_argument" rows="3" placeholder="List the evidence and how the pieces connect."></textarea>
    </label>
    <label class="arbor-simple-full">
        <span>Conclusion</span>
        <textarea class="uk-textarea" name="proof_conclusion" rows="3" placeholder="State the conclusion in plain language."></textarea>
    </label>
    <label class="arbor-simple-full">
        <span>Conflicts or doubts</span>
        <textarea class="uk-textarea" name="proof_conflicts" rows="2" placeholder="Mention conflicting records, missing evidence, or why this stays a draft."></textarea>
    </label>
    <button class="uk-button uk-button-primary" type="submit" name="add_proof" value="1">
        <span uk-icon="icon: plus"></span> Add conclusion
    </button>
</form>

<?php if (empty($proofs)): ?>
    <div class="arbor-empty">
        <span uk-icon="icon: file-edit; ratio: 3"></span>
        <h4><?= $proofView === 'all' ? 'No conclusions yet' : 'No conclusions in this view' ?></h4>
        <p><?= $proofView === 'all' ? 'Write a conclusion when you have enough evidence to explain why a fact or relationship is correct.' : 'Choose another conclusion status to review the rest.' ?></p>
    </div>
<?php else: ?>
    <table class="uk-table uk-table-divider uk-table-small uk-table-hover">
        <thead><tr><th>Title</th><th class="uk-width-small">Status</th><th>Context</th><th>Conclusion</th><th class="uk-width-small">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($proofs as $p): ?>
            <?php $proofContext = $contextFor($p); ?>
            <tr>
                <td><strong><?= htmlspecialchars($p['title']) ?></strong></td>
                <td>
                    <?php $cls = $p['status'] === 'final' ? 'success' : 'warning'; ?>
                    <span class="uk-label uk-label-<?= $cls ?>"><?= htmlspecialchars($p['status']) ?></span>
                </td>
                <td class="uk-text-small arbor-context-links">
                    <?= $renderContext($proofContext) ?>
                </td>
                <td class="uk-text-small"><?= htmlspecialchars(substr((string)$p['conclusion'], 0, 240)) ?></td>
                <td>
                    <div class="arbor-row-actions">
                        <details class="arbor-inline-editor">
                            <summary>Edit</summary>
                            <form class="arbor-document-edit" method="post">
                                <?= $csrfInput ?>
                                <input type="hidden" name="proof_id" value="<?= (int) $p['id'] ?>">
                                <label class="arbor-document-edit-full"><span>Title</span><input class="uk-input uk-form-small" type="text" name="proof_title" value="<?= htmlspecialchars((string) $p['title'], ENT_QUOTES) ?>" required></label>
                                <label>
                                    <span>Status</span>
                                    <select class="uk-select uk-form-small" name="proof_status">
                                        <option value="draft" <?= (string) $p['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                        <option value="final" <?= (string) $p['status'] === 'final' ? 'selected' : '' ?>>Final</option>
                                    </select>
                                </label>
                                <label><span>Question</span><select class="uk-select uk-form-small" name="proof_question_id"><?= $questionOptionsFor(!empty($p['question_id']) ? (int) $p['question_id'] : null) ?></select></label>
                                <label><span>Person</span><select class="uk-select uk-form-small" name="proof_person_id"><?= $personOptionsFor(!empty($p['person_id']) ? (int) $p['person_id'] : null) ?></select></label>
                                <label class="arbor-document-edit-full"><span>Evidence summary</span><textarea class="uk-textarea uk-form-small" rows="3" name="proof_argument"><?= htmlspecialchars((string) ($p['argument'] ?? '')) ?></textarea></label>
                                <label class="arbor-document-edit-full"><span>Conclusion</span><textarea class="uk-textarea uk-form-small" rows="3" name="proof_conclusion"><?= htmlspecialchars((string) ($p['conclusion'] ?? '')) ?></textarea></label>
                                <label class="arbor-document-edit-full"><span>Conflicts or doubts</span><textarea class="uk-textarea uk-form-small" rows="2" name="proof_conflicts"><?= htmlspecialchars((string) ($p['conflicts'] ?? '')) ?></textarea></label>
                                <button class="uk-button uk-button-default uk-button-small" type="submit" name="update_proof" value="1"><span uk-icon="icon: check"></span> Save conclusion</button>
                            </form>
                        </details>
                        <?php if ($p['status'] === 'draft'): ?>
                            <form method="post">
                                <?= $csrfInput ?>
                                <input type="hidden" name="proof_id" value="<?= (int) $p['id'] ?>">
                                <button class="uk-button uk-button-primary uk-button-small" type="submit" name="proof_status" value="final">Final</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= $csrfInput ?>
                                <input type="hidden" name="proof_id" value="<?= (int) $p['id'] ?>">
                                <button class="uk-button uk-button-default uk-button-small" type="submit" name="proof_status" value="draft">Draft</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Delete this conclusion?')">
                            <?= $csrfInput ?>
                            <input type="hidden" name="proof_id" value="<?= (int) $p['id'] ?>">
                            <button class="uk-button uk-button-text uk-text-danger" type="submit" name="delete_proof" value="1" title="Delete conclusion">
                                <span uk-icon="icon: trash"></span>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>
