<?php namespace ProcessWire;

/** @var array $tree */
/** @var Arbor $arbor */
/** @var string $baseUrl */

$api = $this->wire('modules')->isInstalled('ArborApi') ? $this->wire('modules')->get('ArborApi') : null;
$apiBase = $api ? $api->apiBase : '/api/arbor/';
$graphUrl = rtrim($apiBase, '/') . '/trees/' . $tree['id'] . '/graph/';
$settings = [];
if (!empty($tree['settings'])) {
    $decoded = json_decode((string) $tree['settings'], true);
    if (is_array($decoded)) $settings = $decoded;
}
$rootPersonId = (int) ($settings['root_person_id'] ?? 0);
?>
<div class="pw-wrap Arbor">

<div class="arbor-toolbar">
    <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>person/?tree=<?= (int) $tree['id'] ?>">
        <span uk-icon="icon: plus"></span> Add person
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>union/?tree=<?= (int) $tree['id'] ?>">
        <span uk-icon="icon: users"></span> Add family
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>persons/?tree=<?= (int) $tree['id'] ?>">
        <span uk-icon="icon: list"></span> People
    </a>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>families/?tree=<?= (int) $tree['id'] ?>">
        <span uk-icon="icon: heart"></span> Families
    </a>
    <a class="uk-button uk-button-text" href="<?= $baseUrl ?>tree/?id=<?= (int) $tree['id'] ?>">
        ← Back to tree
    </a>
</div>

<div class="arbor-viewer-toolbar">
    <span class="uk-text-meta">View:</span>
    <label class="arbor-viewer-search">
        <span class="uk-text-meta">Find</span>
        <input class="uk-input uk-form-small" type="search" id="arbor-viewer-search" placeholder="Person name" list="arbor-viewer-search-list" autocomplete="off">
        <datalist id="arbor-viewer-search-list"></datalist>
        <span class="arbor-viewer-search-status" id="arbor-viewer-search-status" aria-live="polite"></span>
    </label>
    <button type="button" class="uk-button uk-button-default" data-zoom="in"  title="Zoom in"><span uk-icon="icon: plus"></span></button>
    <button type="button" class="uk-button uk-button-default" data-zoom="out" title="Zoom out"><span uk-icon="icon: minus"></span></button>
    <button type="button" class="uk-button uk-button-default" data-zoom="fit" title="Fit to window"><span uk-icon="icon: expand"></span> Fit</button>
    <button type="button" class="uk-button uk-button-default" id="arbor-show-main" <?= $rootPersonId ? '' : 'disabled' ?>>
        <span uk-icon="icon: star"></span> Show main
    </button>
    <button type="button" class="uk-button uk-button-default" id="arbor-show-all">
        <span uk-icon="icon: grid"></span> Show all
    </button>
    <label class="uk-margin-left"><input class="uk-checkbox" type="checkbox" id="arbor-toggle-living" checked> Show living</label>
    <label class="uk-margin-left">Around selected:
        <input class="uk-input uk-form-small" type="number" id="arbor-gen-filter" value="6" min="1" max="20" style="width:5em">
    </label>
    <label class="uk-margin-left">Direction:
        <select class="uk-select uk-form-small" id="arbor-direction">
            <option value="ancestors-up">Ancestors up</option>
            <option value="descendants-up">Descendants up</option>
        </select>
    </label>
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>tree-edit/?id=<?= (int) $tree['id'] ?>">
        <span uk-icon="icon: cog"></span> Main person
    </a>
    <details class="arbor-viewer-agenda">
        <summary>Agenda</summary>
        <div class="arbor-viewer-agenda-body">
            <p id="arbor-viewer-agenda-main">Main person: not set</p>
            <p id="arbor-viewer-agenda-selected">Select a person to see next actions.</p>
            <form class="arbor-viewer-set-main arbor-viewer-agenda-main-form" method="post">
                <?= $csrfInput ?>
                <input type="hidden" name="person_id" value="">
                <button class="uk-button uk-button-default uk-button-small" type="submit" name="set_root_person" value="1">
                    <span uk-icon="icon: star"></span> Make main
                </button>
            </form>
            <div class="arbor-viewer-agenda-actions" id="arbor-viewer-agenda-actions" hidden>
                <a href="#" class="arbor-viewer-agenda-profile"><span uk-icon="icon: pencil"></span> Profile</a>
                <a href="#" class="arbor-viewer-agenda-parents"><span uk-icon="icon: plus"></span> Parents</a>
                <a href="#" class="arbor-viewer-agenda-partner"><span uk-icon="icon: users"></span> Partner</a>
                <a href="#" class="arbor-viewer-agenda-child"><span uk-icon="icon: plus"></span> Child</a>
            </div>
        </div>
    </details>
    <details class="arbor-viewer-legend">
        <summary>Legend</summary>
        <div class="arbor-viewer-legend-body">
            <span><i class="arbor-legend-dot male"></i> Male</span>
            <span><i class="arbor-legend-dot female"></i> Female</span>
            <span><i class="arbor-legend-dot unknown"></i> Unknown</span>
            <span><i class="arbor-legend-line"></i> Parent / child</span>
            <span><i class="arbor-legend-line spouse"></i> Partners</span>
            <span><i class="arbor-legend-dot main"></i> Main person</span>
        </div>
    </details>
</div>

<div class="arbor-viewer" id="arbor-viewer"
     data-graph-url="<?= htmlspecialchars($graphUrl) ?>"
     data-tree-id="<?= (int) $tree['id'] ?>"
     data-root-person-id="<?= $rootPersonId ?>"
     data-add-person-url="<?= $baseUrl ?>person/?tree=<?= (int) $tree['id'] ?>"
     data-add-family-url="<?= $baseUrl ?>union/?tree=<?= (int) $tree['id'] ?>&amp;person="
     data-add-parents-url="<?= $baseUrl ?>union/?tree=<?= (int) $tree['id'] ?>&amp;child="
     data-add-child-url="<?= $baseUrl ?>union/?tree=<?= (int) $tree['id'] ?>&amp;add_child=1&amp;partner1=">
    <svg id="arbor-viewer-svg" width="100%" height="600"></svg>
    <div class="arbor-viewer-note" id="arbor-viewer-note" hidden></div>
    <aside class="arbor-viewer-person" id="arbor-viewer-person" hidden>
        <button type="button" class="arbor-viewer-person-close" aria-label="Close details">&times;</button>
        <div class="arbor-viewer-person-kicker">Selected person</div>
        <h3></h3>
        <p class="arbor-viewer-person-meta"></p>
        <p class="arbor-viewer-person-relationship"></p>
        <div class="arbor-viewer-person-path" hidden></div>
        <dl class="arbor-viewer-person-relatives"></dl>
        <div class="arbor-viewer-person-gaps" hidden></div>
        <div class="arbor-viewer-person-actions">
        <a class="uk-button uk-button-primary uk-button-small arbor-viewer-open-profile" href="#">
            <span uk-icon="icon: pencil"></span> Open profile
        </a>
        <a class="uk-button uk-button-default uk-button-small arbor-viewer-add-family" href="#">
            <span uk-icon="icon: users"></span> Add partner
        </a>
        <a class="uk-button uk-button-default uk-button-small arbor-viewer-add-parents" href="#">
            <span uk-icon="icon: plus"></span> Add parents
        </a>
        <a class="uk-button uk-button-default uk-button-small arbor-viewer-add-child" href="#">
            <span uk-icon="icon: plus"></span> Add child
        </a>
        <form class="arbor-viewer-set-main arbor-viewer-person-main-form" method="post">
            <?= $csrfInput ?>
            <input type="hidden" name="person_id" value="">
            <button class="uk-button uk-button-default uk-button-small" type="submit" name="set_root_person" value="1">
                <span uk-icon="icon: star"></span> Make main
            </button>
        </form>
        </div>
    </aside>
    <div class="arbor-viewer-empty" hidden>
        <div class="arbor-viewer-empty-inner">
            <span uk-icon="icon: tree; ratio: 3"></span>
            <h3>This tree is empty</h3>
            <p class="uk-text-muted">Start by adding the first person: yourself, a parent, or an ancestor.<br>
                Then create a family to link parents and children.</p>
            <p>
                <a class="uk-button uk-button-primary" href="<?= $baseUrl ?>person/?tree=<?= (int) $tree['id'] ?>">
                    <span uk-icon="icon: plus"></span> Add first person
                </a>
            </p>
            <p class="uk-text-small uk-text-muted">
                Already have data?
                <a href="<?= $baseUrl ?>import/?tree=<?= (int) $tree['id'] ?>">Import a family file</a>
                from Ancestry, MyHeritage, RootsMagic or another genealogy tool.
            </p>
        </div>
    </div>
</div>

<script src="https://d3js.org/d3.v7.min.js"></script>
<script src="<?= $this->wire('config')->urls->siteModules ?>Arbor/assets/arbor-viewer.js?v=<?= filemtime($this->wire('config')->paths->siteModules . 'Arbor/assets/arbor-viewer.js') ?>"></script>
</div>
