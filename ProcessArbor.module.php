<?php namespace ProcessWire;

/**
 * Admin process for the Arbor genealogy module.
 *
 * Routes follow standard ProcessWire conventions: one `executeXyz` method per
 * screen, identifiers passed via `?id=N` and tree context via `?tree=N`.
 *
 *   /                        list of trees                       (execute)
 *   /tree/?id=N              tree overview                       (executeTree)
 *   /tree-edit/?id=N         edit existing tree (omit id = new)  (executeTreeEdit)
 *   /persons/?tree=N         persons in a tree                   (executePersons)
 *   /person/?id=N            edit person                         (executePerson)
 *                            ?tree=N (no id) = new person
 *   /person-delete/?id=N     confirm + delete                    (executePersonDelete)
 *   /families/?tree=N        unions in a tree                    (executeFamilies)
 *   /union/?id=N             edit union (or ?tree=N for new)     (executeUnion)
 *   /places/?tree=N          places list                         (executePlaces)
 *   /place/?id=N             edit place                          (executePlace)
 *   /sources/?tree=N         sources list                        (executeSources)
 *   /source/?id=N            edit source                         (executeSource)
 *   /repos/?tree=N           repositories list                   (executeRepos)
 *   /repositories/?tree=N    alias for repositories list         (executeRepositories)
 *   /repo/?id=N              edit repository                     (executeRepo)
 *   /research/?tree=N        notes and questions                 (executeResearch)
 *   /dna/?tree=N             DNA kits                            (executeDna)
 *   /documents/?tree=N       archival documents                  (executeDocuments)
 *   /photos/?tree=N          photo gallery                       (executePhotos)
 *   /viewer/?tree=N          interactive tree viewer             (executeViewer)
 *   /import/?tree=N          family file import                  (executeImport)
 *   /export/?tree=N          family file export                  (executeExport)
 */
class ProcessArbor extends Process
{
    public static function getModuleInfo(): array
    {
        return [
            'title' => 'Arbor',
            'version'  => 100,
            'summary' => 'Admin interface for the Arbor genealogy module',
            'icon' => 'tree',
            'permission' => 'arbor-view',
            'requires' => ['Arbor'],
            'page' => [
                'name' => 'arbor',
                'parent' => 'setup',
                'title' => 'Arbor',
            ],
            'useNavJSON' => false,
        ];
    }

    /**
     * On upgrade fix the page title that older installs created as "Arbor Admin".
     */
    public function ___upgrade($fromVersion, $toVersion): void
    {
        $this->wire('pages')->uncacheAll();
        $p = $this->wire('pages')->get('template=admin, name=arbor, include=all');
        if ($p->id && $p->title !== 'Arbor') {
            $p->of(false);
            $p->title = 'Arbor';
            $p->save(['quiet' => true]);
        }
    }

    protected Arbor $arbor;

    public function init(): void
    {
        parent::init();
        $this->arbor = $this->wire('modules')->get('Arbor');
        $url = $this->wire('config')->urls->siteModules . 'Arbor/assets/';
        $this->wire('config')->styles->add($url . 'arbor-admin.css');
        $this->wire('config')->scripts->add($url . 'arbor-admin.js');
    }

    /* ============== entry points ============== */

    public function ___execute(): string
    {
        $this->headline('Genealogy');
        $this->browserTitle('Arbor');
        return $this->renderTemplate('arbor-tree-list', $this->dashboardData());
    }

    /**
     * Collect aggregated counters, per-tree counts, recent activity, and open
     * Open tasks and questions across the whole installation for the admin dashboard.
     */
    /**
     * Collect just enough data for the personal dashboard: trees with per-tree
     * counts, the few most recently edited persons in each, and any open
     * research tasks/questions. Skip global totals — Arbor is designed for one
     * researcher with 1-3 trees, not a multi-tenant encyclopedia.
     */
    protected function dashboardData(): array
    {
        $db = $this->wire('database');
        $trees = $this->arbor->model('trees')->all();

        $treeStats = [];
        $recentByTree = [];
        $statStmts = [];
        foreach ([
            'persons' => 'arbor_persons',
            'unions'  => 'arbor_unions',
            'places'  => 'arbor_places',
            'sources' => 'arbor_sources',
            'photos'  => 'arbor_photos',
            'documents' => 'arbor_documents',
        ] as $alias => $table) {
            $statStmts[$alias] = $db->prepare("SELECT COUNT(*) FROM $table WHERE tree_id = :t");
        }
        $recentStmt = $db->prepare(
            "SELECT p.id, p.modified, n.given, n.surname
             FROM arbor_persons p
             LEFT JOIN arbor_names n ON n.person_id = p.id AND n.name_type = 'BIRTH'
             WHERE p.tree_id = :t
             ORDER BY p.modified DESC, p.id DESC
             LIMIT 5"
        );

        foreach ($trees as $t) {
            $stat = [];
            foreach ($statStmts as $alias => $stmt) {
                $stmt->execute([':t' => $t['id']]);
                $stat[$alias] = (int) $stmt->fetchColumn();
            }
            $treeStats[$t['id']] = $stat;

            $recentStmt->execute([':t' => $t['id']]);
            $recentByTree[$t['id']] = $recentStmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $stmt = $db->query("SELECT t.id, t.tree_id, t.title, t.priority, t.due_date, t.person_id
                            FROM arbor_tasks t
                            WHERE status IN ('open','in_progress')
                            ORDER BY FIELD(priority,'urgent','high','medium','low'), due_date
                            LIMIT 6");
        $openTasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $db->query("SELECT id, tree_id, question, opened_date
                            FROM arbor_research_questions
                            WHERE status = 'open'
                            ORDER BY opened_date DESC
                            LIMIT 4");
        $openQuestions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $api = $this->wire('modules')->get('ArborApi');
        $apiCatalog = $api ? $api->endpointCatalog() : null;

        return [
            'trees'         => $trees,
            'treeStats'     => $treeStats,
            'recentByTree'  => $recentByTree,
            'openTasks'     => $openTasks,
            'openQuestions' => $openQuestions,
            'apiCatalog'    => $apiCatalog,
        ];
    }

    public function ___executeTree(): string
    {
        $treeId = (int) $this->wire('input')->get('id');
        if (!$treeId) throw new Wire404Exception('Missing tree id');
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        $this->headline($tree['name']);
        $this->browserTitle($tree['name'] . ' — Arbor');
        return $this->treeOverview($tree);
    }

    public function ___executeTreeEdit(): string
    {
        $treeId = (int) $this->wire('input')->get('id');
        $tree = $treeId ? $this->arbor->model('trees')->get($treeId) : null;
        $this->headline($treeId ? 'Edit tree' : 'New tree');
        $this->browserTitle(($treeId ? 'Edit ' : 'New ') . 'tree — Arbor');
        if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
        return $this->editTree($treeId);
    }

    public function ___executeTreeDelete(): string
    {
        $arbor = $this->arbor;
        $id = (int) $this->wire('input')->get('id');
        $tree = $id ? $arbor->model('trees')->get($id) : null;
        if (!$tree) throw new Wire404Exception('Tree not found');
        $this->requireDeleteTree($tree);
        $this->headline('Delete tree: ' . $tree['name']);
        $this->browserTitle('Delete ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $id]), $tree['name']);

        $expected = strtolower($tree['name']);
        if ($this->wire('input')->post('confirm')) {
            $this->requireValidPost();
            $typed = strtolower($this->wire('input')->post->text('confirm_name') ?? '');
            if ($typed !== $expected) {
                $this->error('The typed tree name does not match. Deletion cancelled.');
            } else {
                $arbor->model('trees')->delete($id);
                $this->message("Tree \"{$tree['name']}\" deleted with all its data.");
                $this->wire('session')->redirect($this->page->url);
            }
        }

        $name = htmlspecialchars($tree['name']);
        $back = $this->url('tree', ['id' => $id]);
        $csrf = $this->csrfInput();
        return "<div class='pw-wrap Arbor'>
            <div class='uk-alert uk-alert-danger'>
                <p><strong>This action cannot be undone.</strong> All people, families, names, events, places, sources, archives, citations, documents, DNA kits, research and tasks attached to this tree will be permanently removed.</p>
            </div>
            <form class='InputfieldForm' method='post'>
                $csrf
                <ul class='Inputfields'>
                    <li class='Inputfield InputfieldText InputfieldStateRequired'>
                        <label class='InputfieldHeader ui-widget-header'><i class='toggle-icon fa fa-fw fa-angle-down'></i>Type the tree name to confirm</label>
                        <div class='InputfieldContent ui-widget-content'>
                            <input class='uk-input' type='text' name='confirm_name' autocomplete='off' required>
                            <p class='notes'>Expected: <code>$name</code></p>
                        </div>
                    </li>
                </ul>
                <div class='uk-margin'>
                    <button type='submit' name='confirm' value='1' class='uk-button uk-button-danger'>
                        <span uk-icon='icon: trash'></span> Permanently delete tree
                    </button>
                    <a class='uk-button uk-button-text' href='$back'>Cancel</a>
                </div>
            </form>
        </div>";
    }

    public function ___executePersons(): string
    {
        $tree = $this->requireTree();
        $search = $this->wire('input')->get->text('q') ?? '';
        $filter = (string) $this->wire('input')->get('filter');
        if (!in_array($filter, ['missing_parents', 'missing_birth_date'], true)) $filter = '';
        $persons = $this->arbor->model('persons')->findByTree((int) $tree['id'], [
            'search' => $search,
            'filter' => $filter,
        ]);
        $this->headline('People');
        $this->browserTitle('People - ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->renderTemplate('arbor-person-list', [
            'tree' => $tree, 'persons' => $persons, 'search' => $search, 'filter' => $filter,
        ]);
    }

    public function ___executePerson(): string
    {
        $input = $this->wire('input');
        $id = (int) $input->get('id');
        if ($id) {
            $person = $this->arbor->model('persons')->get($id);
            if (!$person) throw new Wire404Exception('Person not found');
            $treeId = (int) $person['tree_id'];
            $tree = $this->arbor->model('trees')->get($treeId);
            $primary = $this->arbor->model('names')->primary($id);
            if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
            $display = $primary ? trim(($primary['given'] ?? '') . ' ' . ($primary['surname'] ?? '')) : '';
            $this->headline($display ?: 'Person #' . $id);
            $this->browserTitle(($display ?: 'Person') . ' — ' . $tree['name']);
            if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
            $this->breadcrumb($this->url('persons', ['tree' => $treeId]), 'People');
            return $this->editPerson($id, $treeId);
        }
        $treeId = (int) $input->get('tree');
        if (!$treeId) throw new Wire404Exception('Missing tree id for new person');
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        $personContext = $this->newPersonContext($treeId);
        $this->headline($personContext['title']);
        $this->browserTitle($personContext['title'] . ' — ' . ($tree['name'] ?? ''));
        if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
        $this->breadcrumb($this->url('persons', ['tree' => $treeId]), 'People');
        return $this->editPerson(0, $treeId);
    }

    public function ___executePersonDelete(): string
    {
        $id = (int) $this->wire('input')->get('id');
        $person = $id ? $this->arbor->model('persons')->get($id) : null;
        if (!$person) throw new Wire404Exception();
        $treeId = (int) $person['tree_id'];
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        $this->headline('Delete person');
        if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
        $this->breadcrumb($this->url('persons', ['tree' => $treeId]), 'People');
        return $this->deletePerson($id, $treeId);
    }

    public function ___executeFamilies(): string
    {
        $tree = $this->requireTree();
        $this->headline('Families');
        $this->browserTitle('Families — ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->familiesList($tree);
    }

    public function ___executeUnion(): string
    {
        $input = $this->wire('input');
        $id = (int) $input->get('id');
        if ($id) {
            $u = $this->arbor->model('unions')->get($id);
            if (!$u) throw new Wire404Exception('Family not found');
            $treeId = (int) $u['tree_id'];
            $tree = $this->arbor->model('trees')->get($treeId);
            if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
            $this->headline('Family #' . $id);
            if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
            $this->breadcrumb($this->url('families', ['tree' => $treeId]), 'Families');
            return $this->editUnion($id, $treeId);
        }
        $treeId = (int) $input->get('tree');
        if (!$treeId) throw new Wire404Exception('Missing tree id for new union');
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        if ((int) $input->get('add_child') === 1) {
            $this->headline('Add child');
        } elseif ((int) $input->get('child')) {
            $this->headline('Add parents');
        } else {
            $this->headline('New family');
        }
        if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
        $this->breadcrumb($this->url('families', ['tree' => $treeId]), 'Families');
        return $this->editUnion(0, $treeId);
    }

    public function ___executePlaces(): string
    {
        $tree = $this->requireTree();
        if ($this->wire('input')->post('import_event_places')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $result = $this->importEventPlaces((int) $tree['id']);
            $this->message(sprintf('Places imported: %d created, %d events linked', $result['created'], $result['linked']));
            $this->wire('session')->redirect($this->url('places', ['tree' => $tree['id']]));
        }
        $this->headline('Places');
        $this->browserTitle('Places — ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->placesList($tree);
    }

    public function ___executePlace(): string
    {
        $input = $this->wire('input');
        $id = (int) $input->get('id');
        if ($id) {
            $p = $this->arbor->model('places')->get($id);
            if (!$p) throw new Wire404Exception();
            $treeId = (int) $p['tree_id'];
            $tree = $this->arbor->model('trees')->get($treeId);
            if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
            $this->headline($p['canonical_name']);
            if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
            $this->breadcrumb($this->url('places', ['tree' => $treeId]), 'Places');
            return $this->editPlace($id, $treeId);
        }
        $treeId = (int) $input->get('tree');
        if (!$treeId) throw new Wire404Exception();
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        $this->headline('New place');
        if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
        $this->breadcrumb($this->url('places', ['tree' => $treeId]), 'Places');
        return $this->editPlace(0, $treeId);
    }

    public function ___executeSources(): string
    {
        $tree = $this->requireTree();
        if ($this->wire('input')->post('create_birth_source')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $result = $this->createBirthFactSource($tree);
            $this->message(sprintf('Source created: %d citations linked', $result['linked']));
            $this->wire('session')->redirect($this->url('sources', ['tree' => $tree['id']]));
        }
        if ($this->wire('input')->post('create_starter_document_leads')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $result = $this->createStarterDocumentLeads((int) $tree['id']);
            $this->message(sprintf('Document leads created: %d, citations linked: %d', $result['created'], $result['linked']));
            $this->wire('session')->redirect($this->url('documents', ['tree' => $tree['id'], 'filter' => 'missing_file']));
        }
        $this->headline('Sources');
        $this->browserTitle('Sources — ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->sourcesList($tree);
    }

    public function ___executeSource(): string
    {
        $input = $this->wire('input');
        $id = (int) $input->get('id');
        if ($id) {
            $s = $this->arbor->model('sources')->get($id);
            if (!$s) throw new Wire404Exception();
            $treeId = (int) $s['tree_id'];
            $tree = $this->arbor->model('trees')->get($treeId);
            if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
            $this->headline($s['title'] ?: 'Source #' . $id);
            if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
            $this->breadcrumb($this->url('sources', ['tree' => $treeId]), 'Sources');
            return $this->editSource($id, $treeId);
        }
        $treeId = (int) $input->get('tree');
        if (!$treeId) throw new Wire404Exception();
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        $this->headline('New source');
        if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
        $this->breadcrumb($this->url('sources', ['tree' => $treeId]), 'Sources');
        return $this->editSource(0, $treeId);
    }

    public function ___executeRepos(): string
    {
        $tree = $this->requireTree();
        if ($this->wire('input')->post('create_family_archive')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $result = $this->createFamilyArchive($tree);
            $this->message(sprintf('Archive created: %d sources linked', $result['linked']));
            $this->wire('session')->redirect($this->url('repos', ['tree' => $tree['id']]));
        }
        $this->headline('Archives and websites');
        $this->browserTitle('Archives and websites — ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->reposList($tree);
    }

    public function ___executeRepositories(): string
    {
        return $this->___executeRepos();
    }

    public function ___executeRepo(): string
    {
        $input = $this->wire('input');
        $id = (int) $input->get('id');
        if ($id) {
            $r = $this->arbor->model('repositories')->get($id);
            if (!$r) throw new Wire404Exception();
            $treeId = (int) $r['tree_id'];
            $tree = $this->arbor->model('trees')->get($treeId);
            if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
            $this->headline($r['name']);
            if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
            $this->breadcrumb($this->url('repos', ['tree' => $treeId]), 'Archives and websites');
            return $this->editRepo($id, $treeId);
        }
        $treeId = (int) $input->get('tree');
        if (!$treeId) throw new Wire404Exception();
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree || !$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        $this->headline('New archive or website');
        if ($tree) $this->breadcrumb($this->url('tree', ['id' => $treeId]), $tree['name']);
        $this->breadcrumb($this->url('repos', ['tree' => $treeId]), 'Archives and websites');
        return $this->editRepo(0, $treeId);
    }

    public function ___executeResearch(): string
    {
        $tree = $this->requireTree();
        $returnUrl = $this->url('research', $this->returnParams((int) $tree['id'], [
            'task_view' => ['all', 'open', 'in_progress', 'done', 'cancelled'],
            'question_view' => ['all', 'open', 'answered', 'abandoned'],
            'log_view' => ['all', 'positive', 'negative', 'inconclusive'],
            'proof_view' => ['all', 'draft', 'final'],
        ]));
        $openTasksUrl = $this->url('research', $this->returnParams((int) $tree['id'], [
            'question_view' => ['all', 'open', 'answered', 'abandoned'],
            'log_view' => ['all', 'positive', 'negative', 'inconclusive'],
            'proof_view' => ['all', 'draft', 'final'],
        ]) + ['task_view' => 'open']);
        $openQuestionsUrl = $this->url('research', $this->returnParams((int) $tree['id'], [
            'task_view' => ['all', 'open', 'in_progress', 'done', 'cancelled'],
            'log_view' => ['all', 'positive', 'negative', 'inconclusive'],
            'proof_view' => ['all', 'draft', 'final'],
        ]) + ['question_view' => 'open']);
        if ($this->wire('input')->post('task_status')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateResearchTaskStatus((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('question_status')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateResearchQuestionStatus((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('add_research_task')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addResearchTask((int) $tree['id']);
            $this->wire('session')->redirect($openTasksUrl);
        }
        if ($this->wire('input')->post('update_research_task')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateResearchTask((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_research_task')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteResearchTask((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('add_research_question')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addResearchQuestion((int) $tree['id']);
            $this->wire('session')->redirect($openQuestionsUrl);
        }
        if ($this->wire('input')->post('update_research_question')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateResearchQuestion((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_research_question')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteResearchQuestion((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('add_research_log')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addResearchLog((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_research_log')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateResearchLog((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_research_log')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteResearchLog((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('add_proof')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addProofArgument((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_proof')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateProofArgument((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_proof')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteProofArgument((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('proof_status')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateProofStatus((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('create_research_plan')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $result = $this->createStarterResearchPlan($tree);
            $this->message(sprintf('Research plan created: %d questions, %d tasks', $result['questions'], $result['tasks']));
            $this->wire('session')->redirect($returnUrl);
        }
        $this->headline('Notes and questions');
        $this->browserTitle('Notes and questions - ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->researchList($tree);
    }

    public function ___executeDna(): string
    {
        $tree = $this->requireTree();
        $returnUrl = $this->url('dna', $this->returnParams((int) $tree['id'], [
            'filter' => ['no_segments'],
        ]));
        if ($this->wire('input')->post('add_dna_kit')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addDnaKit((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('add_dna_match')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addDnaMatch((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('add_dna_segment')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addDnaSegment((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_dna_kit')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateDnaKit((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_dna_match')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateDnaMatch((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_dna_segment')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateDnaSegment((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('import_dna_csv')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->importDnaCsv((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_dna_kit')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteDnaKit((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_dna_match')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteDnaMatch((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_dna_segment')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteDnaSegment((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        $this->headline('DNA');
        $this->browserTitle('DNA — ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->dnaList($tree);
    }

    public function ___executeDocuments(): string
    {
        $tree = $this->requireTree();
        $returnUrl = $this->url('documents', $this->returnParams((int) $tree['id'], [
            'filter' => ['missing_file', 'no_evidence', 'leads', 'found', 'attached', 'dismissed'],
            'person' => 'person',
        ]));
        if ($this->wire('input')->post('add_document')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addDocument((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_document')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateDocument((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_document_status')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateDocumentStatus((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_document_url')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateDocumentUrl((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('resolve_document_lead')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->resolveDocumentLeadWithUrl((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('add_document_citation')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addDocumentCitation((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_document_citation')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteDocumentCitation((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_document_citation_event')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateDocumentCitationEvent((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('create_document_source')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->createSourceFromDocument((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_document')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteDocument((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        $this->headline('Documents');
        $this->browserTitle('Documents — ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->documentsList($tree);
    }

    public function ___executePhotos(): string
    {
        $tree = $this->requireTree();
        $returnUrl = $this->url('photos', $this->returnParams((int) $tree['id'], [
            'person' => 'person',
        ]));
        if ($this->wire('input')->post('add_photo')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->addTreePhoto((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('update_photo')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->updateTreePhoto((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('delete_photo')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->deleteTreePhoto((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        if ($this->wire('input')->post('set_profile_photo')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $this->setProfilePhoto((int) $tree['id']);
            $this->wire('session')->redirect($returnUrl);
        }
        $this->headline('Photos');
        $this->browserTitle('Photos — ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->photosList($tree);
    }

    public function ___executeViewer(): string
    {
        $tree = $this->requireTree();
        $input = $this->wire('input');
        if ($input->post('set_root_person')) {
            $this->requireEditTree($tree);
            $this->requireValidPost();
            $personId = (int) $input->post('person_id');
            if (!$personId) throw new WireException('Choose a person first');
            $this->requireRecordInTree('persons', $personId, (int) $tree['id']);
            $settings = [];
            if (!empty($tree['settings'])) {
                $decoded = json_decode((string) $tree['settings'], true);
                if (is_array($decoded)) $settings = $decoded;
            }
            $settings['root_person_id'] = $personId;
            $tree['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);
            $this->arbor->model('trees')->save($tree, (int) $tree['id']);
            $this->message('Main person updated');
            $this->wire('session')->redirect($this->url('viewer', ['tree' => (int) $tree['id']]));
        }
        $this->headline('Tree viewer');
        $this->browserTitle('Viewer - ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->viewer($tree);
    }

    public function ___executeImport(): string
    {
        $tree = $this->requireTree();
        $this->headline('Import family file');
        $this->browserTitle('Import family file - ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->importGedcom($tree);
    }

    public function ___executeExport(): string
    {
        $tree = $this->requireTree();
        $this->headline('Export family file');
        $this->browserTitle('Export family file - ' . $tree['name']);
        $this->breadcrumb($this->url('tree', ['id' => $tree['id']]), $tree['name']);
        return $this->exportGedcom($tree);
    }

    /* ============== helpers ============== */

    /**
     * Read a POSTed associative array safely.
     * `$input->post('name', ['array'])` runs through `Sanitizer::array()` which
     * drops associative keys; we want them, so go via raw $_POST.
     */
    protected function postArray(string $name): array
    {
        $v = $_POST[$name] ?? null;
        return is_array($v) ? $v : [];
    }

    protected function requireTree(): array
    {
        $treeId = (int) $this->wire('input')->get('tree');
        if (!$treeId) $treeId = (int) $this->wire('input')->get('id');
        if (!$treeId) throw new Wire404Exception('Missing tree id');
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        if (!$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        return $tree;
    }

    protected function requireCreateTree(): void
    {
        if (!$this->arbor->canCreateTree()) {
            throw new WirePermissionException('You do not have permission to create Arbor trees');
        }
    }

    protected function requireEditTree(array $tree): void
    {
        if (!$this->arbor->canEditTree($tree)) {
            throw new WirePermissionException('You do not have permission to edit this tree');
        }
    }

    protected function requireDeleteTree(array $tree): void
    {
        if (!$this->arbor->canDeleteTree($tree)) {
            throw new WirePermissionException('Tree deletion requires arbor-admin permission');
        }
    }

    protected function csrfInput(): string
    {
        $csrf = $this->wire('session')->CSRF;
        $name = method_exists($csrf, 'getTokenName') ? $csrf->getTokenName() : 'csrf';
        $value = method_exists($csrf, 'getTokenValue') ? $csrf->getTokenValue() : '';
        return '<input type="hidden" name="' . htmlspecialchars((string) $name) . '" value="' . htmlspecialchars((string) $value) . '">';
    }

    protected function requireValidPost(): void
    {
        $csrf = $this->wire('session')->CSRF;
        if (method_exists($csrf, 'hasValidToken') && !$csrf->hasValidToken()) {
            throw new WirePermissionException('Invalid CSRF token');
        }
    }

    protected function requireRecordInTree(string $entity, int $id, int $treeId): void
    {
        $recordTreeId = $this->arbor->treeIdForRecord($entity, $id);
        if (!$recordTreeId || $recordTreeId !== $treeId) {
            throw new WirePermissionException('Record does not belong to this tree');
        }
    }

    protected function returnParams(int $treeId, array $allowed): array
    {
        $input = $this->wire('input');
        $params = ['tree' => $treeId];
        foreach ($allowed as $name => $rule) {
            $value = (string) $input->get($name);
            if ($value === '') continue;
            if ($rule === 'person') {
                $personId = (int) $value;
                if ($personId && $this->arbor->treeIdForRecord('persons', $personId) === $treeId) {
                    $params[$name] = $personId;
                }
            } elseif (is_array($rule) && in_array($value, $rule, true)) {
                $params[$name] = $value;
            }
        }
        return $params;
    }

    protected function url(string $action, array $params = []): string
    {
        $u = $this->page->url . ($action !== '' ? trim($action, '/') . '/' : '');
        if ($params) $u .= '?' . http_build_query($params);
        return $u;
    }

    /* ============== views ============== */

    protected function editTree(int $id): string
    {
        $arbor = $this->arbor;
        $input = $this->wire('input');
        $row = $id
            ? $arbor->model('trees')->get($id)
            : ['name' => '', 'description' => '', 'is_public' => $arbor->defaultPublic, 'settings' => ''];
        if (!$row) throw new Wire404Exception();
        if ($id) $this->requireEditTree($row);
        else $this->requireCreateTree();
        $settings = [];
        if (!empty($row['settings'])) {
            $decoded = json_decode((string) $row['settings'], true);
            if (is_array($decoded)) $settings = $decoded;
        }
        $persons = $id ? $arbor->model('persons')->findByTree($id, ['limit' => 500]) : [];

        if ($input->post('save')) {
            $this->requireValidPost();
            $row['name']        = $input->post->text('name');
            $row['description'] = $input->post->textarea('description');
            $row['is_public']   = (int) $input->post('is_public');
            $row['owner_id']    = $this->wire('user')->id;
            $rootPersonId = (int) $input->post('root_person_id');
            if ($rootPersonId) $this->requireRecordInTree('persons', $rootPersonId, $id);
            $settings['root_person_id'] = $rootPersonId ?: null;
            $row['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);
            $newId = $arbor->model('trees')->save($row, $id ?: null);
            $this->message($id ? 'Tree updated' : 'Tree created');
            $this->wire('session')->redirect($this->url('tree', ['id' => $newId]));
        }
        return $this->renderTemplate('arbor-tree-edit', [
            'tree' => $row,
            'id' => $id,
            'settings' => $settings,
            'persons' => $persons,
        ]);
    }

    protected function treeOverview(array $tree): string
    {
        $arbor = $this->arbor;
        $db = $this->wire('database');
        $tid = (int) $tree['id'];

        $counts = [];
        foreach (['arbor_persons','arbor_unions','arbor_events','arbor_places',
                  'arbor_sources','arbor_repositories','arbor_photos','arbor_documents',
                  'arbor_dna_kits','arbor_research_questions','arbor_research_log',
                  'arbor_proof_arguments','arbor_tasks'] as $t) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM $t WHERE tree_id = :t");
            $stmt->execute([':t' => $tid]);
            $counts[$t] = (int) $stmt->fetchColumn();
        }

        // recently edited persons (with primary name)
        $stmt = $db->prepare("SELECT p.id, p.sex, p.is_alive, p.modified, n.given, n.surname
                              FROM arbor_persons p
                              LEFT JOIN arbor_names n ON n.person_id = p.id AND n.name_type = 'BIRTH'
                              WHERE p.tree_id = :t
                              ORDER BY p.modified DESC, p.id DESC
                              LIMIT 8");
        $stmt->execute([':t' => $tid]);
        $recentPersons = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // recent families (unions) with partner names
        $unions = $arbor->model('unions')->forTree($tid);
        $recentFamilies = array_slice($unions, 0, 6);
        foreach ($recentFamilies as &$u) {
            $u['partner1_name'] = $u['partner1_id'] ? $this->personSummary((int) $u['partner1_id']) : null;
            $u['partner2_name'] = $u['partner2_id'] ? $this->personSummary((int) $u['partner2_id']) : null;
        }
        unset($u);

        // recent sources
        $stmt = $db->prepare("SELECT id, title, source_type, archive_abbrev, fond, opis, delo
                              FROM arbor_sources WHERE tree_id = :t ORDER BY id DESC LIMIT 6");
        $stmt->execute([':t' => $tid]);
        $recentSources = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // open tasks + questions (for this tree only)
        $stmt = $db->prepare("SELECT id, title, priority, due_date
                              FROM arbor_tasks
                              WHERE tree_id = :t AND status IN ('open','in_progress')
                              ORDER BY FIELD(priority,'urgent','high','medium','low'), due_date
                              LIMIT 5");
        $stmt->execute([':t' => $tid]);
        $openTasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT id, question, opened_date
                              FROM arbor_research_questions
                              WHERE tree_id = :t AND status = 'open'
                              ORDER BY opened_date DESC LIMIT 5");
        $stmt->execute([':t' => $tid]);
        $openQuestions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $countSql = function (string $sql) use ($db, $tid): int {
            $stmt = $db->prepare($sql);
            $stmt->execute([':t' => $tid]);
            return (int) $stmt->fetchColumn();
        };
        $qualityChecks = [
            [
                'label' => 'People missing parents',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_persons p
                                      LEFT JOIN arbor_union_children uc ON uc.person_id = p.id
                                      WHERE p.tree_id = :t AND uc.id IS NULL"),
                'route' => 'persons',
                'params' => ['filter' => 'missing_parents'],
                'hint' => 'Link parents where you know them.',
            ],
            [
                'label' => 'People missing birth date',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_persons p
                                      WHERE p.tree_id = :t
                                        AND NOT EXISTS (
                                            SELECT 1 FROM arbor_events e
                                            WHERE e.person_id = p.id
                                              AND e.event_type = 'birth'
                                              AND e.event_date IS NOT NULL
                                        )"),
                'route' => 'persons',
                'params' => ['filter' => 'missing_birth_date'],
                'hint' => 'Add an estimated date when exact date is unknown.',
            ],
            [
                'label' => 'Birth facts without source',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_events e
                                      LEFT JOIN arbor_citations c ON c.event_id = e.id
                                      WHERE e.tree_id = :t
                                        AND e.event_type = 'birth'
                                        AND c.id IS NULL"),
                'route' => 'sources',
                'params' => ['filter' => 'unsourced_births'],
                'hint' => 'Attach a source or document for each birth fact.',
            ],
            [
                'label' => 'Documents without file or URL',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_documents
                                      WHERE tree_id = :t
                                        AND status NOT IN ('lead','dismissed')
                                        AND filename = ''
                                        AND external_url = ''"),
                'route' => 'documents',
                'params' => ['filter' => 'missing_file'],
                'hint' => 'Upload a scan or add an online link.',
            ],
            [
                'label' => 'Document leads to resolve',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_documents
                                      WHERE tree_id = :t
                                        AND status = 'lead'"),
                'route' => 'documents',
                'params' => ['filter' => 'leads'],
                'hint' => 'Find the real record, then attach a scan, URL, and source.',
            ],
            [
                'label' => 'Documents not linked as evidence',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_documents d
                                      WHERE d.tree_id = :t
                                        AND NOT EXISTS (
                                            SELECT 1
                                            FROM arbor_citations c
                                            JOIN arbor_sources s ON s.id = c.source_id
                                            WHERE c.document_id = d.id
                                              AND s.tree_id = d.tree_id
                                        )"),
                'route' => 'documents',
                'params' => ['filter' => 'no_evidence'],
                'hint' => 'Link each document to a person or fact it supports.',
            ],
            [
                'label' => 'DNA matches without segments',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_dna_matches m
                                      JOIN arbor_dna_kits k ON k.id = m.kit_a_id
                                      LEFT JOIN arbor_dna_segments s ON s.match_id = m.id
                                      WHERE k.tree_id = :t AND s.id IS NULL"),
                'route' => 'dna',
                'params' => ['filter' => 'no_segments'],
                'hint' => 'Add chromosome segments when the test site provides them.',
            ],
            [
                'label' => 'Leads still using starter source',
                'count' => $countSql("SELECT COUNT(*)
                                      FROM arbor_documents d
                                      JOIN arbor_citations c ON c.document_id = d.id
                                      JOIN arbor_sources s ON s.id = c.source_id
                                      WHERE d.tree_id = :t
                                        AND d.status IN ('lead','found')
                                        AND s.title LIKE 'Existing family data for %'"),
                'route' => 'documents',
                'params' => ['filter' => 'leads'],
                'hint' => 'Open each lead, add the real record, then create a source from it.',
            ],
        ];

        return $this->renderTemplate('arbor-tree-overview', [
            'tree'           => $tree,
            'counts'         => $counts,
            'recentPersons'  => $recentPersons,
            'recentFamilies' => $recentFamilies,
            'recentSources'  => $recentSources,
            'openTasks'      => $openTasks,
            'openQuestions'  => $openQuestions,
            'qualityChecks'  => $qualityChecks,
        ]);
    }

    protected function editPerson(int $id, int $treeId): string
    {
        $arbor = $this->arbor;
        $input = $this->wire('input');
        $person = $id ? $arbor->model('persons')->get($id) : null;
        if ($id && !$person) throw new Wire404Exception();
        if (!$id) {
            $person = ['tree_id' => $treeId, 'sex' => 'U', 'is_alive' => 1, 'resn' => 'none'];
        }
        $tree = $arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        if (!$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');

        if ($input->post('save')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $person = array_merge($person, [
                'tree_id'     => $treeId,
                'sex'         => $input->post('sex') ?: 'U',
                'gender_text' => $input->post->text('gender_text') ?? '',
                'is_alive'    => (int) $input->post('is_alive'),
                'ethnicity'   => $input->post->text('ethnicity') ?? '',
                'religion'    => $input->post->text('religion') ?? '',
                'is_cohen'    => (int) $input->post('is_cohen'),
                'is_levi'     => (int) $input->post('is_levi'),
                'bio'         => $input->post->textarea('bio') ?? '',
                'notes'       => $input->post->textarea('notes') ?? '',
                'resn'        => $input->post('resn') ?: 'none',
                'refn'        => $input->post->text('refn') ?? '',
            ]);
            $newId = $arbor->model('persons')->save($person, $id ?: null);

            $this->savePostedPrimaryName($newId, $treeId, $this->postArray('primary_name'));
            $this->savePostedOtherNames($newId, $treeId, $this->postArray('other_names'));
            $this->savePostedVitalEvents($newId, $treeId, [
                'birth_date'   => $input->post->text('birth_date') ?? '',
                'birth_approx' => (int) $input->post('birth_approx'),
                'birth_place'  => $input->post->text('birth_place') ?? '',
                'death_date'   => $input->post->text('death_date') ?? '',
                'death_approx' => (int) $input->post('death_approx'),
                'death_place'  => $input->post->text('death_place') ?? '',
                'death_cause'  => $input->post->text('death_cause') ?? '',
                'burial_date'  => $input->post->text('burial_date') ?? '',
                'burial_place' => $input->post->text('burial_place') ?? '',
            ]);
            $this->savePostedCitizenships($newId, $treeId, $this->postArray('citizenships'));
            $this->savePostedExternalIds($newId, $treeId, $this->postArray('external_ids'));
            $this->handlePhotoUploads($newId, $treeId);
            if (!$id) $this->ensureTreeRootPerson($tree, $newId);

            $this->message($id ? 'Person updated' : 'Person created');
            if (!$id) {
                $returnChild = (int) $input->get('return_child');
                $returnRole = (string) ($input->get('return_role') ?: $input->get('return_partner'));
                if ($returnRole === 'child') {
                    $params = ['tree' => $treeId, 'add_child' => 1, 'child' => $newId];
                    foreach (['partner1', 'partner2'] as $partnerKey) {
                        $partnerId = (int) $input->get($partnerKey);
                        if ($partnerId) {
                            $this->requireRecordInTree('persons', $partnerId, $treeId);
                            $params[$partnerKey] = $partnerId;
                        }
                    }
                    $this->wire('session')->redirect($this->url('union', $params));
                }
                if ((int) $input->get('add_child') === 1 && in_array($returnRole, ['partner1', 'partner2'], true)) {
                    $params = ['tree' => $treeId, 'add_child' => 1, $returnRole => $newId];
                    $otherPartner = $returnRole === 'partner1' ? (int) $input->get('partner2') : (int) $input->get('partner1');
                    if ($otherPartner) {
                        $this->requireRecordInTree('persons', $otherPartner, $treeId);
                        $params[$returnRole === 'partner1' ? 'partner2' : 'partner1'] = $otherPartner;
                    }
                    $childId = (int) $input->get('child');
                    if ($childId) {
                        $this->requireRecordInTree('persons', $childId, $treeId);
                        $params['child'] = $childId;
                    }
                    $this->wire('session')->redirect($this->url('union', $params));
                }
                if ($returnChild && in_array($returnRole, ['partner1', 'partner2'], true)) {
                    $this->requireRecordInTree('persons', $returnChild, $treeId);
                    $params = ['tree' => $treeId, 'child' => $returnChild];
                    $otherPartner = $returnRole === 'partner1' ? (int) $input->get('partner2') : (int) $input->get('partner1');
                    if ($otherPartner) {
                        $this->requireRecordInTree('persons', $otherPartner, $treeId);
                        $params[$returnRole === 'partner1' ? 'partner2' : 'partner1'] = $otherPartner;
                    }
                    $params[$returnRole] = $newId;
                    $this->wire('session')->redirect($this->url('union', $params));
                }
            }
            $this->wire('session')->redirect($this->url('person', ['id' => $newId]));
        }

        $data = [
            'tree'   => $tree,
            'person' => $person,
            'id'     => $id,
            'personContext' => !$id ? $this->newPersonContext($treeId) : [],
            'names'         => $id ? $arbor->model('names')->forPerson($id) : [],
            'events'        => $id ? $arbor->model('events')->forPerson($id) : [],
            'citizenships'  => $id ? $arbor->model('citizenships')->forPerson($id) : [],
            'external_ids'  => $id ? $arbor->model('persons')->externalIds($id) : [],
            'photos'        => $id ? $arbor->model('photos')->forPerson($id) : [],
            'documents'     => $id ? $arbor->model('documents')->forPerson($id) : [],
            'evidence'      => $id ? $this->personEvidence($id, $treeId) : ['byEvent' => [], 'byDocument' => [], 'personOnly' => []],
            'associations'  => $id ? $arbor->model('associations')->forPerson($id) : [],
            'tasks'         => $id ? $arbor->model('tasks')->forPerson($id) : [],
            'kits'          => $id ? $arbor->model('dna')->kitsForPerson($id) : [],
        ];
        return $this->renderTemplate('arbor-person-edit', $data);
    }

    protected function ensureTreeRootPerson(array $tree, int $personId): void
    {
        $treeId = (int) ($tree['id'] ?? 0);
        if (!$treeId || !$personId) return;

        $settings = [];
        if (!empty($tree['settings'])) {
            $decoded = json_decode((string) $tree['settings'], true);
            if (is_array($decoded)) $settings = $decoded;
        }
        if (!empty($settings['root_person_id'])) return;

        $settings['root_person_id'] = $personId;
        $tree['settings'] = json_encode($settings, JSON_UNESCAPED_SLASHES);
        $this->arbor->model('trees')->save($tree, $treeId);
    }

    protected function personEvidence(int $personId, int $treeId): array
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT c.*, s.title AS source_title,
                    d.title AS document_title, d.filename AS document_filename,
                    d.external_url AS document_url, d.tree_id AS document_tree_id,
                    d.person_id AS document_person_id
             FROM arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             LEFT JOIN arbor_documents d ON d.id = c.document_id
             WHERE c.person_id = :p
               AND s.tree_id = :t
             ORDER BY c.id"
        );
        $stmt->execute([':p' => $personId, ':t' => $treeId]);
        $out = ['byEvent' => [], 'byDocument' => [], 'personOnly' => []];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['document_file_url'] = !empty($row['document_filename'])
                ? $this->arbor->uploadUrl((int) $row['document_tree_id'], (int) $row['document_person_id']) . $row['document_filename']
                : '';
            if (!empty($row['event_id'])) $out['byEvent'][(int) $row['event_id']][] = $row;
            if (!empty($row['document_id'])) $out['byDocument'][(int) $row['document_id']][] = $row;
            if (empty($row['event_id']) && empty($row['document_id'])) $out['personOnly'][] = $row;
        }
        return $out;
    }

    protected function newPersonContext(int $treeId): array
    {
        $input = $this->wire('input');
        $returnRole = (string) ($input->get('return_role') ?: $input->get('return_partner'));
        $returnChild = (int) $input->get('return_child');
        $addingChild = (int) $input->get('add_child') === 1;
        $partner1 = (int) $input->get('partner1');
        $partner2 = (int) $input->get('partner2');
        $child = (int) $input->get('child');

        $context = [
            'title' => 'New person',
            'intro' => 'Start with the basics. You can add photos, sources, DNA, and extra details after saving.',
            'backUrl' => $this->url('persons', ['tree' => $treeId]),
            'backLabel' => 'All people',
        ];

        if ($addingChild || ($returnChild && in_array($returnRole, ['partner1', 'partner2'], true))) {
            $unionParams = ['tree' => $treeId];
            if ($addingChild) $unionParams['add_child'] = 1;
            if ($returnChild) $unionParams['child'] = $returnChild;
            if ($child) $unionParams['child'] = $child;
            if ($partner1) $unionParams['partner1'] = $partner1;
            if ($partner2) $unionParams['partner2'] = $partner2;

            $context['backUrl'] = $this->url('union', $unionParams);
            $context['backLabel'] = 'Back to family form';
            $context['title'] = $returnRole === 'child' ? 'Create child' : ($addingChild && $returnRole === 'partner2' ? 'Create other parent' : 'Create parent');
            $context['intro'] = 'Add this person. After saving, you will return to the family form.';
        }

        return $context;
    }

    /**
     * Persist the single BIRTH name. Always one row per person; created on first
     * save, updated on subsequent saves. If all fields are empty we leave the
     * existing row alone (don't blank it out by accident).
     */
    protected function savePostedPrimaryName(int $personId, int $treeId, array $row): void
    {
        if (empty($row)) return;
        $model = $this->arbor->model('names');
        $id = !empty($row['id']) ? (int) $row['id'] : null;
        if ($id) $this->requireRecordInTree('names', $id, $treeId);
        $hasContent = trim(($row['given'] ?? '') . ($row['surname'] ?? '')
                         . ($row['patronymic'] ?? '') . ($row['given_hebrew'] ?? '')) !== '';
        if (!$hasContent && !$id) return;
        $row['person_id'] = $personId;
        $row['name_type'] = 'BIRTH';
        $model->save($row, $id);
    }

    /**
     * Persist AKA / Married / Immigrant / etc. alternate names.
     */
    /**
     * Upsert birth/death/burial events from the quick-edit fields on the
     * person form. Each is a regular arbor_events row; we find the existing
     * one by event_type (one per person) and update, or create if absent.
     */
    protected function savePostedVitalEvents(int $personId, int $treeId, array $vals): void
    {
        $model = $this->arbor->model('events');

        $this->upsertVitalEvent($model, $personId, $treeId, 'birth',  [
            'date'   => $vals['birth_date'],
            'approx' => $vals['birth_approx'],
            'place'  => $vals['birth_place'],
        ]);
        $this->upsertVitalEvent($model, $personId, $treeId, 'death',  [
            'date'   => $vals['death_date'],
            'approx' => $vals['death_approx'],
            'place'  => $vals['death_place'],
            'cause'  => $vals['death_cause'],
        ]);
        $this->upsertVitalEvent($model, $personId, $treeId, 'burial', [
            'date'   => $vals['burial_date'],
            'approx' => 0,
            'place'  => $vals['burial_place'],
        ]);
    }

    protected function upsertVitalEvent($model, int $personId, int $treeId, string $type, array $vals): void
    {
        $existing = $model->findByType($personId, $type);
        $hasInput = trim(($vals['date'] ?? '') . ($vals['place'] ?? '') . ($vals['cause'] ?? '')) !== '';
        if (!$existing && !$hasInput) return;

        $data = [
            'person_id'         => $personId,
            'tree_id'           => $treeId,
            'event_type'        => $type,
            'event_date'        => $vals['date'] ?: null,
            'event_date_approx' => (int) ($vals['approx'] ?? 0),
            'event_place_str'   => $vals['place'] ?? '',
            'cause'             => $vals['cause'] ?? '',
            'title'             => ucfirst($type),
        ];
        $model->save($data, $existing ? (int) $existing['id'] : null);
    }

    protected function savePostedOtherNames(int $personId, int $treeId, array $rows): void
    {
        $model = $this->arbor->model('names');
        $allowed = ['AKA','IMMIGRANT','MAIDEN','MARRIED','PROFESSIONAL','OTHER'];
        foreach ($rows as $row) {
            $id = !empty($row['id']) ? (int) $row['id'] : null;
            if ($id) $this->requireRecordInTree('names', $id, $treeId);
            if (!empty($row['_delete'])) {
                if ($id) $model->delete($id);
                continue;
            }
            $hasContent = trim(($row['given'] ?? '') . ($row['surname'] ?? '')) !== '';
            if (!$hasContent && !$id) continue;
            if (!in_array($row['name_type'] ?? '', $allowed, true)) $row['name_type'] = 'OTHER';
            $row['person_id'] = $personId;
            $model->save($row, $id);
        }
    }

    protected function savePostedCitizenships(int $personId, int $treeId, array $rows): void
    {
        $model = $this->arbor->model('citizenships');
        foreach ($rows as $row) {
            if (!empty($row['_delete'])) {
                if (!empty($row['id'])) {
                    $this->requireRecordInTree('citizenships', (int) $row['id'], $treeId);
                    $model->delete((int) $row['id']);
                }
                continue;
            }
            if (trim($row['country'] ?? '') === '') continue;
            $row['person_id'] = $personId;
            $id = !empty($row['id']) ? (int) $row['id'] : null;
            if ($id) $this->requireRecordInTree('citizenships', $id, $treeId);
            $model->save($row, $id);
        }
    }

    protected function savePostedExternalIds(int $personId, int $treeId, array $rows): void
    {
        $model = $this->arbor->model('persons');
        foreach ($rows as $row) {
            if (!empty($row['_delete'])) {
                if (!empty($row['id'])) {
                    $this->requireRecordInTree('external_ids', (int) $row['id'], $treeId);
                    $model->deleteExternalId((int) $row['id']);
                }
                continue;
            }
            if (trim($row['external_id'] ?? '') === '') continue;
            if (!empty($row['id'])) $this->requireRecordInTree('external_ids', (int) $row['id'], $treeId);
            $model->saveExternalId(
                $personId,
                $row['id_type'] ?? '',
                $row['external_id'] ?? '',
                !empty($row['id']) ? (int) $row['id'] : null
            );
        }
    }

    protected function handlePhotoUploads(int $personId, int $treeId): void
    {
        if (empty($_FILES['photo_upload']['name'][0])) return;
        $arbor = $this->arbor;
        $model = $arbor->model('photos');
        $count = count($model->forPerson($personId));
        $max = (int) $arbor->maxPhotosPerPerson;
        $dir = $arbor->uploadDir($treeId, $personId);
        $files = $_FILES['photo_upload'];
        foreach ($files['name'] as $i => $name) {
            if (!$name || $count >= $max) break;
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            if ($files['size'][$i] > (int) $arbor->maxPhotoSize * 1024) continue;
            $tmp = $files['tmp_name'][$i] ?? '';
            if (!$tmp || !is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info || empty($info['mime'])) continue;
            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if (!isset($extMap[$info['mime']])) continue;
            $target = $dir . bin2hex(random_bytes(16)) . '.' . $extMap[$info['mime']];
            if (move_uploaded_file($files['tmp_name'][$i], $target)) {
                $model->save([
                    'person_id' => $personId, 'tree_id' => $treeId,
                    'filename' => basename($target), 'title' => '', 'sort' => $count,
                ]);
                $count++;
            }
        }
    }

    protected function deletePerson(int $id, int $treeId): string
    {
        $tree = $this->arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        $this->requireEditTree($tree);
        if ($this->wire('input')->post('confirm')) {
            $this->requireValidPost();
            $this->arbor->model('persons')->delete($id);
            $this->message('Person deleted');
            $this->wire('session')->redirect($this->url('persons', ['tree' => $treeId]));
        }
        $back = $this->url('person', ['id' => $id]);
        $csrf = $this->csrfInput();
        return "<div class='pw-wrap Arbor'>
            <div class='uk-alert uk-alert-danger'><p>This will permanently remove the person and all related names, events, photos, citizenships, documents, DNA kits and associations.</p></div>
            <form method='post'>
                $csrf
                <button type='submit' name='confirm' value='1' class='uk-button uk-button-danger'><span uk-icon='icon: trash'></span> Yes, delete</button>
                <a class='uk-button uk-button-text' href='$back'>Cancel</a>
            </form>
        </div>";
    }

    protected function familiesList(array $tree): string
    {
        $unions = $this->arbor->model('unions')->forTree((int) $tree['id']);
        $rows = '';
        $typeLabels = [
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
        foreach ($unions as $u) {
            $p1 = $u['partner1_id'] ? $this->personSummary((int) $u['partner1_id']) : '?';
            $p2 = $u['partner2_id'] ? $this->personSummary((int) $u['partner2_id']) : '?';
            $href = $this->url('union', ['id' => $u['id']]);
            $type = $typeLabels[$u['union_type']] ?? ucfirst(str_replace('_', ' ', (string) $u['union_type']));
            $meta = array_filter([$type, $u['married_date'] ? 'Married ' . $u['married_date'] : null]);
            $rows .= sprintf(
                '<a class="arbor-list-row" href="%s"><span class="arbor-list-main">%s × %s</span><span class="arbor-list-meta">%s</span></a>',
                $href, htmlspecialchars($p1), htmlspecialchars($p2),
                htmlspecialchars(implode(' · ', $meta))
            );
        }
        $name = htmlspecialchars($tree['name']);
        $newUrl = $this->url('union', ['tree' => $tree['id']]);
        $body = $rows
            ? "<div class='arbor-list'>$rows</div>"
            : "<div class='arbor-empty'>
                 <span uk-icon='icon: heart; ratio: 3'></span>
                 <h4>No families yet</h4>
                 <p>A family links parents, partners, and children. You'll need at least one person added before creating a family.</p>
                 <a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add first family</a>
               </div>";
        return "<div class='pw-wrap Arbor'>
            <div class='arbor-toolbar'>
                <a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add family</a>
            </div>
            $body
        </div>";
    }

    protected function editUnion(int $id, int $treeId): string
    {
        $arbor = $this->arbor;
        $input = $this->wire('input');
        $union = $id ? $arbor->model('unions')->get($id) : ['tree_id' => $treeId, 'union_type' => 'unknown'];
        if ($id && !$union) throw new Wire404Exception();
        $tree = $arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        if (!$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        $presetChildren = [];
        $familyWarnings = [];
        $addingChild = !$id && (int) $input->get('add_child') === 1;
        if (!$id) {
            $partner1 = (int) $input->get('partner1') ?: (int) $input->get('person');
            $partner2 = (int) $input->get('partner2');
            $preselectChild = (int) $input->get('child');
            foreach (['first parent/partner' => &$partner1, 'second parent/partner' => &$partner2, 'child' => &$preselectChild] as $label => &$personId) {
                if ($personId && $this->arbor->treeIdForRecord('persons', $personId) !== $treeId) {
                    $personId = 0;
                    $familyWarnings[] = "The selected $label does not belong to this tree.";
                }
            }
            unset($personId);
            if ($preselectChild) {
                if ($partner1 === $preselectChild) {
                    $partner1 = 0;
                    $familyWarnings[] = 'The selected child cannot also be a parent.';
                }
                if ($partner2 === $preselectChild) {
                    $partner2 = 0;
                    $familyWarnings[] = 'The selected child cannot also be a parent.';
                }
            }
            if ($partner1) {
                $this->requireRecordInTree('persons', $partner1, $treeId);
                $union['partner1_id'] = $partner1;
            }
            if ($partner2) {
                $this->requireRecordInTree('persons', $partner2, $treeId);
                $union['partner2_id'] = $partner2;
            }
            if ($preselectChild) {
                $presetChildren[] = [
                    'person_id' => $preselectChild,
                    'pedigree' => 'birth',
                    'birth_order' => 0,
                ];
            }
        }

        if ($id && $input->post('delete_union')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $arbor->model('unions')->delete($id);
            $this->message('Family deleted');
            $this->wire('session')->redirect($this->url('families', ['tree' => $treeId]));
        }

        if ($input->post('save')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $partner1 = (int) $input->post('partner1_id') ?: null;
            $partner2 = (int) $input->post('partner2_id') ?: null;
            if ($partner1) $this->requireRecordInTree('persons', $partner1, $treeId);
            if ($partner2) $this->requireRecordInTree('persons', $partner2, $treeId);
            $postedChildren = $this->postArray('children');
            $validationErrors = [];
            $isAddParentsFlow = !$id && !$addingChild && (int) $input->get('child');
            $marksUnknownParents = false;
            if ($partner1 && $partner2 && $partner1 === $partner2) {
                $validationErrors[] = 'The two parents or partners must be different people.';
            }
            $childIds = [];
            foreach ($postedChildren as $row) {
                $childId = (int) ($row['person_id'] ?? 0);
                if (!$childId || !empty($row['_delete'])) continue;
                if ($childId === $partner1 || $childId === $partner2) {
                    $validationErrors[] = 'A person cannot be both a parent and a child in the same family.';
                }
                if (in_array($childId, $childIds, true)) {
                    $validationErrors[] = 'The same child is listed more than once.';
                }
                if (($row['pedigree'] ?? '') === 'foundling') {
                    $marksUnknownParents = true;
                }
                $childIds[] = $childId;
            }
            if ($isAddParentsFlow && !$partner1 && !$partner2 && !$marksUnknownParents) {
                $validationErrors[] = 'Choose at least one parent, or set relationship to "Foundling or unknown parents".';
            }
            if ($addingChild && !$childIds) {
                $validationErrors[] = 'Choose a child.';
            }
            if (!$id && !$addingChild && !$isAddParentsFlow && !$partner1 && !$partner2 && !$childIds) {
                $validationErrors[] = 'Choose at least one person before saving the family.';
            }
            $union = array_merge($union, [
                'tree_id'             => $treeId,
                'partner1_id'         => $partner1,
                'partner2_id'         => $partner2,
                'union_type'          => $input->post('union_type') ?: 'unknown',
                'married_date'        => $input->post->text('married_date') ?? '',
                'married_date_approx' => (int) $input->post('married_date_approx'),
                'married_place_id'    => (int) $input->post('married_place_id') ?: null,
                'divorced'            => (int) $input->post('divorced'),
                'divorced_date'       => $input->post->text('divorced_date') ?? '',
                'notes'               => $input->post->textarea('notes') ?? '',
                'resn'                => $input->post('resn') ?: 'none',
            ]);
            if ($validationErrors) {
                foreach (array_unique($validationErrors) as $message) {
                    $familyWarnings[] = $message;
                }
                $presetChildren = $postedChildren;
            } else {
                $newId = $arbor->model('unions')->save($union, $id ?: null);
                $this->savePostedChildren($newId, $treeId, $postedChildren);
                $this->closeParentResearchForChildren($treeId, $postedChildren);
                if ($addingChild) {
                    $this->message('Child added');
                    $this->wire('session')->redirect($this->url('viewer', ['tree' => $treeId]));
                }
                if ($isAddParentsFlow) {
                    $this->message('Parents saved');
                    $this->wire('session')->redirect($this->url('viewer', ['tree' => $treeId]));
                }
                $this->message($id ? 'Family updated' : 'Family created');
                $this->wire('session')->redirect($this->url('union', ['id' => $newId]));
            }
        }

        return $this->renderTemplate('arbor-union-edit', [
            'tree'     => $tree,
            'union'    => $union,
            'id'       => $id,
            'children' => $id ? $arbor->model('unions')->children($id) : $presetChildren,
            'persons'  => $arbor->model('persons')->findByTree($treeId, ['limit' => 500]),
            'familyWarnings' => $familyWarnings,
            'addingChild' => $addingChild,
        ]);
    }

    protected function savePostedChildren(int $unionId, int $treeId, array $rows): void
    {
        $model = $this->arbor->model('unions');
        foreach ($rows as $row) {
            $rowId = !empty($row['id']) ? (int) $row['id'] : null;
            if ($rowId) $this->requireRecordInTree('union_children', $rowId, $treeId);
            if (!empty($row['_delete']) && $rowId) { $model->removeChild($rowId); continue; }
            if (empty($row['person_id'])) continue;
            $this->requireRecordInTree('persons', (int) $row['person_id'], $treeId);
            if ($rowId) $model->updateChild($rowId, $row);
            else $model->addChild($unionId, (int) $row['person_id'], $row);
        }
    }

    protected function closeParentResearchForChildren(int $treeId, array $rows): void
    {
        $personIds = [];
        foreach ($rows as $row) {
            $personId = (int) ($row['person_id'] ?? 0);
            if ($personId && empty($row['_delete'])) $personIds[$personId] = true;
        }
        if (!$personIds) return;

        $db = $this->wire('database');
        $parentState = $db->prepare(
            "SELECT
                SUM(u.partner1_id IS NOT NULL OR u.partner2_id IS NOT NULL) AS known_parent_count,
                SUM(uc.pedigree = 'foundling') AS unknown_parent_count
             FROM arbor_union_children uc
             JOIN arbor_unions u ON u.id = uc.union_id
             WHERE uc.person_id = :person
               AND u.tree_id = :tree"
        );
        $closeTasks = $db->prepare(
            "UPDATE arbor_tasks
             SET status = 'done'
             WHERE tree_id = :tree
               AND person_id = :person
               AND task_type = 'parents'
               AND status <> 'done'"
        );
        $closeQuestions = $db->prepare(
            "UPDATE arbor_research_questions
             SET status = :status, closed_date = :closed
             WHERE tree_id = :tree
               AND person_id = :person
               AND status = 'open'
               AND question LIKE 'Who were the parents of %'"
        );
        $today = date('Y-m-d');
        foreach (array_keys($personIds) as $personId) {
            $parentState->execute([':person' => $personId, ':tree' => $treeId]);
            $state = $parentState->fetch(\PDO::FETCH_ASSOC) ?: [];
            $knownParents = (int) ($state['known_parent_count'] ?? 0);
            $unknownParents = (int) ($state['unknown_parent_count'] ?? 0);
            if (!$knownParents && !$unknownParents) continue;
            $questionStatus = $knownParents ? 'answered' : 'abandoned';
            $closeTasks->execute([':tree' => $treeId, ':person' => $personId]);
            $closeQuestions->execute([
                ':status' => $questionStatus,
                ':closed' => $today,
                ':tree' => $treeId,
                ':person' => $personId,
            ]);
        }
    }

    protected function placesList(array $tree): string
    {
        $treeId = (int) $tree['id'];
        $placesModel = $this->arbor->model('places');
        $places = $placesModel->allForTree($treeId);
        $importablePlaces = $this->eventPlaceStringCount($treeId);
        $rows = '';
        $typeLabels = [
            'country' => 'Country',
            'region' => 'Region',
            'gubernia' => 'Gubernia',
            'oblast' => 'Oblast',
            'district' => 'District',
            'uyezd' => 'Uyezd',
            'raion' => 'Raion',
            'city' => 'City or town',
            'town' => 'Town',
            'shtetl' => 'Shtetl',
            'village' => 'Village',
            'street' => 'Street',
            'cemetery' => 'Cemetery',
            'synagogue' => 'Synagogue',
            'hospital' => 'Hospital',
            'church' => 'Church',
            'mosque' => 'Mosque',
            'ghetto' => 'Ghetto',
            'camp' => 'Camp',
            'other' => 'Place',
        ];
        foreach ($places as $p) {
            $type = $typeLabels[$p['place_type']] ?? ucfirst(str_replace('_', ' ', (string) $p['place_type']));
            $path = $placesModel->fullPath((int) $p['id']);
            $meta = trim($type . ($path && $path !== $p['canonical_name'] ? ' · ' . $path : ''));
            $rows .= sprintf(
                '<a class="arbor-list-row" href="%s"><span class="arbor-list-main">%s</span><span class="arbor-list-meta">%s</span></a>',
                $this->url('place', ['id' => $p['id']]),
                htmlspecialchars($p['canonical_name']),
                htmlspecialchars($meta)
            );
        }
        $name = htmlspecialchars($tree['name']);
        $count = count($places);
        $newUrl = $this->url('place', ['tree' => $treeId]);
        $treeUrl = $this->url('tree', ['id' => $treeId]);
        $viewerUrl = $this->url('viewer', ['tree' => $treeId]);
        $importButton = '';
        if ($importablePlaces > 0) {
            $importButton = "<form method='post' class='arbor-inline-form'>
                {$this->wire('session')->CSRF->renderInput()}
                <button class='uk-button uk-button-default' type='submit' name='import_event_places' value='1'>
                    <span uk-icon='icon: pull'></span> Create from event places ($importablePlaces)
                </button>
            </form>";
        }
        $body = $rows
            ? "<div class='arbor-list'>$rows</div>"
            : "<div class='arbor-empty'>
                 <span uk-icon='icon: location; ratio: 3'></span>
                 <h4>No places yet</h4>
                 <p>Add countries, regions, towns, addresses, cemeteries, and other places that appear in your family story.</p>
                 <a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add first place</a>
               </div>";
        return "<div class='pw-wrap Arbor'>
            <p class='uk-text-muted'>$count places in $name. Use places for birth, marriage, death, documents, cemeteries, and notes.</p>
            <div class='arbor-toolbar'>
                <a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add place</a>
                <a class='uk-button uk-button-default' href='$treeUrl'><span uk-icon='icon: tree'></span> Tree overview</a>
                <a class='uk-button uk-button-default' href='$viewerUrl'><span uk-icon='icon: image'></span> Tree viewer</a>
                $importButton
            </div>
            $body
        </div>";
    }

    protected function eventPlaceStringCount(int $treeId): int
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT COUNT(DISTINCT event_place_str)
             FROM arbor_events
             WHERE tree_id = :t AND event_place_str <> '' AND event_place_id IS NULL"
        );
        $stmt->execute([':t' => $treeId]);
        return (int) $stmt->fetchColumn();
    }

    protected function importEventPlaces(int $treeId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare(
            "SELECT id, event_place_str
             FROM arbor_events
             WHERE tree_id = :t AND event_place_str <> '' AND event_place_id IS NULL
             ORDER BY id"
        );
        $stmt->execute([':t' => $treeId]);
        $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $created = 0;
        $linked = 0;
        $update = $db->prepare("UPDATE arbor_events SET event_place_id = :p WHERE id = :id AND tree_id = :t");
        foreach ($events as $event) {
            $placeId = $this->ensurePlacePath($treeId, (string) $event['event_place_str'], $created);
            if (!$placeId) continue;
            $update->execute([':p' => $placeId, ':id' => $event['id'], ':t' => $treeId]);
            $linked += $update->rowCount() > 0 ? 1 : 0;
        }

        return ['created' => $created, 'linked' => $linked];
    }

    protected function ensurePlacePath(int $treeId, string $placeString, int &$created): ?int
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $placeString)), fn($p) => $p !== ''));
        if (!$parts) return null;

        $parentId = null;
        $placeId = null;
        $ordered = array_reverse($parts);
        $lastIndex = count($ordered) - 1;
        foreach ($ordered as $index => $name) {
            $type = $index === 0 ? 'country' : ($index === $lastIndex ? 'city' : 'region');
            $placeId = $this->findPlaceByNameAndParent($treeId, $name, $parentId);
            if (!$placeId) {
                $placeId = $this->arbor->model('places')->save([
                    'tree_id' => $treeId,
                    'canonical_name' => $name,
                    'parent_id' => $parentId,
                    'place_type' => $type,
                ]);
                $created++;
            }
            $parentId = $placeId;
        }

        return $placeId;
    }

    protected function findPlaceByNameAndParent(int $treeId, string $name, ?int $parentId): ?int
    {
        $db = $this->wire('database');
        if ($parentId) {
            $stmt = $db->prepare(
                "SELECT id FROM arbor_places
                 WHERE tree_id = :t AND canonical_name = :n AND parent_id = :p
                 LIMIT 1"
            );
            $stmt->execute([':t' => $treeId, ':n' => $name, ':p' => $parentId]);
        } else {
            $stmt = $db->prepare(
                "SELECT id FROM arbor_places
                 WHERE tree_id = :t AND canonical_name = :n AND parent_id IS NULL
                 LIMIT 1"
            );
            $stmt->execute([':t' => $treeId, ':n' => $name]);
        }
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    protected function editPlace(int $id, int $treeId): string
    {
        $arbor = $this->arbor;
        $input = $this->wire('input');
        $place = $id ? $arbor->model('places')->get($id) : ['tree_id' => $treeId, 'place_type' => 'other'];
        if ($id && !$place) throw new Wire404Exception();
        $tree = $arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        if (!$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        if ($input->post('delete_citation')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $citationId = (int) $input->post('delete_citation');
            if (!$citationId) throw new WireException('Missing evidence link');
            $this->requireRecordInTree('citations', $citationId, $treeId);
            $this->arbor->model('citations')->delete($citationId);
            $this->message('Evidence link removed');
            $this->wire('session')->redirect($this->url('source', ['id' => $id]));
        }
        if ($input->post('save')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            $parentId = (int) $input->post('parent_id') ?: null;
            if ($parentId) $this->requireRecordInTree('places', $parentId, $treeId);
            $place = array_merge($place, [
                'tree_id'        => $treeId,
                'canonical_name' => $input->post->text('canonical_name') ?? '',
                'parent_id'      => $parentId,
                'place_type'     => $input->post('place_type') ?: 'other',
                'latitude'       => $input->post->text('latitude') ?? '',
                'longitude'      => $input->post->text('longitude') ?? '',
                'geonames_id'    => $input->post->text('geonames_id') ?? '',
                'wikipedia_url'  => $input->post->text('wikipedia_url') ?? '',
                'notes'          => $input->post->textarea('notes') ?? '',
            ]);
            $newId = $arbor->model('places')->save($place, $id ?: null);
            $this->message($id ? 'Place updated' : 'Place created');
            $this->wire('session')->redirect($this->url('place', ['id' => $newId]));
        }
        return $this->renderTemplate('arbor-place-edit', [
            'tree'          => $tree,
            'place'         => $place,
            'id'            => $id,
            'names'         => $id ? $arbor->model('places')->names($id) : [],
            'jurisdictions' => $id ? $arbor->model('places')->jurisdictions($id) : [],
            'all_places'    => $arbor->model('places')->allForTree($treeId),
        ]);
    }

    protected function sourcesList(array $tree): string
    {
        $treeId = (int) $tree['id'];
        $filter = (string) $this->wire('input')->get('filter');
        if (!in_array($filter, ['starter_sources', 'unsourced_births'], true)) $filter = '';
        $sources = $this->arbor->model('sources')->forTree($treeId);
        if ($filter === 'starter_sources') {
            $sources = array_values(array_filter($sources, fn($s) => strpos((string) ($s['title'] ?? ''), 'Existing family data for ') === 0));
        }
        $citationCounts = $this->sourceCitationCounts($treeId);
        $repositoryNames = $this->sourceRepositoryNames($treeId);
        $unsourcedBirths = $this->unsourcedBirthEventCount($treeId);
        if ($filter === 'unsourced_births' && $unsourcedBirths === 0) {
            $sources = [];
        }
        $rows = '';
        $typeLabels = [
            'artifact' => 'Artifact',
            'book' => 'Book',
            'census' => 'Census',
            'database' => 'Database',
            'dna_test' => 'DNA test',
            'journal' => 'Journal',
            'manuscript' => 'Manuscript',
            'metrical_book' => 'Metrical book',
            'newspaper' => 'Newspaper',
            'oral_interview' => 'Oral interview',
            'photograph' => 'Photograph',
            'revision_list' => 'Revision list',
            'vital_record' => 'Vital record',
            'website' => 'Website',
            'other' => 'Source',
        ];
        foreach ($sources as $s) {
            $type = $typeLabels[$s['source_type']] ?? ucfirst(str_replace('_', ' ', (string) $s['source_type']));
            $archive = trim((string) (($s['archive_abbrev'] ?? '') ?: ($s['archive_name'] ?? '')));
            if (!$archive && isset($repositoryNames[$s['id']])) $archive = $repositoryNames[$s['id']];
            $url = trim((string) (($s['digital_url'] ?? '') ?: ($s['url'] ?? '')));
            $citations = (int) ($citationCounts[$s['id']] ?? 0);
            $meta = array_filter([$type, $archive ?: null, $url ? 'Online' : null, $citations ? $citations . ' citations' : null]);
            $rows .= sprintf(
                '<a class="arbor-list-row" href="%s"><span class="arbor-list-main">%s</span><span class="arbor-list-meta">%s</span></a>',
                $this->url('source', ['id' => $s['id']]),
                htmlspecialchars($s['title']),
                htmlspecialchars(implode(' · ', $meta))
            );
        }
        $name = htmlspecialchars($tree['name']);
        $count = count($sources);
        $newUrl = $this->url('source', ['tree' => $treeId]);
        $treeUrl = $this->url('tree', ['id' => $treeId]);
        $viewerUrl = $this->url('viewer', ['tree' => $treeId]);
        $clearUrl = $this->url('sources', ['tree' => $treeId]);
        $filterNote = '';
        if ($filter === 'starter_sources') {
            $filterNote = "<div class='arbor-filter-note'>Showing: <strong>Starter sources still in use</strong> <a href='$clearUrl'>show all sources</a></div>";
        } elseif ($filter === 'unsourced_births') {
            $filterNote = "<div class='arbor-filter-note'>Showing next action: <strong>Birth facts without source</strong> <a href='$clearUrl'>show all sources</a></div>";
        }
        $birthSourceButton = '';
        if ($unsourcedBirths > 0) {
            $birthSourceButton = "<form method='post' class='arbor-inline-form'>
                {$this->wire('session')->CSRF->renderInput()}
                <button class='uk-button uk-button-default' type='submit' name='create_birth_source' value='1'>
                    <span uk-icon='icon: link'></span> Source birth facts ($unsourcedBirths)
                </button>
            </form>";
        }
        $starterLeadCount = $this->starterCitationLeadCount($treeId);
        $starterLeadButton = '';
        if ($starterLeadCount > 0) {
            $starterLeadButton = "<form method='post' class='arbor-inline-form'>
                {$this->wire('session')->CSRF->renderInput()}
                <button class='uk-button uk-button-default' type='submit' name='create_starter_document_leads' value='1'>
                    <span uk-icon='icon: copy'></span> Create document leads ($starterLeadCount)
                </button>
            </form>";
        }
        $emptyTitle = 'No sources yet';
        $emptyText = 'Add books, documents, websites, photos, interviews, and archive records that back up facts in the tree.';
        if ($filter === 'starter_sources') {
            $emptyTitle = 'No starter sources in this check';
            $emptyText = 'Temporary family-knowledge sources have already been replaced or removed.';
        } elseif ($filter === 'unsourced_births') {
            $emptyTitle = 'No birth facts in this check';
            $emptyText = 'Every recorded birth fact has a source citation.';
        }
        $emptyAction = $filter
            ? "<a class='uk-button uk-button-text' href='$clearUrl'>Show all sources</a>"
            : "<a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add first source</a>";
        $body = $rows
            ? "<div class='arbor-list'>$rows</div>"
            : "<div class='arbor-empty'>
                 <span uk-icon='icon: file-text; ratio: 3'></span>
                 <h4>$emptyTitle</h4>
                 <p>$emptyText</p>
                 $emptyAction
               </div>";
        return "<div class='pw-wrap Arbor'>
            <p class='uk-text-muted'>$count sources in $name. Add documents, books, websites, photos, interviews, and archive records that prove facts in the tree.</p>
            <div class='arbor-toolbar'>
                <a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add source</a>
                <a class='uk-button uk-button-default' href='$treeUrl'><span uk-icon='icon: tree'></span> Tree overview</a>
                <a class='uk-button uk-button-default' href='$viewerUrl'><span uk-icon='icon: image'></span> Tree viewer</a>
                $birthSourceButton
                $starterLeadButton
            </div>
            $filterNote
            $body
        </div>";
    }

    protected function sourceCitationCounts(int $treeId): array
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT s.id, COUNT(c.id) AS citation_count
             FROM arbor_sources s
             LEFT JOIN arbor_citations c ON c.source_id = s.id
             WHERE s.tree_id = :t
             GROUP BY s.id"
        );
        $stmt->execute([':t' => $treeId]);
        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['id']] = (int) $row['citation_count'];
        }
        return $counts;
    }

    protected function sourceRepositoryNames(int $treeId): array
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT s.id, r.name
             FROM arbor_sources s
             JOIN arbor_repositories r ON r.id = s.repo_id
             WHERE s.tree_id = :t"
        );
        $stmt->execute([':t' => $treeId]);
        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        return $names;
    }

    protected function unsourcedBirthEventCount(int $treeId): int
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT COUNT(*)
             FROM arbor_events e
             LEFT JOIN arbor_citations c ON c.event_id = e.id
             WHERE e.tree_id = :t AND e.event_type = 'birth' AND c.id IS NULL"
        );
        $stmt->execute([':t' => $treeId]);
        return (int) $stmt->fetchColumn();
    }

    protected function createBirthFactSource(array $tree): array
    {
        $treeId = (int) $tree['id'];
        $db = $this->wire('database');
        $title = 'Existing family data for ' . $tree['name'];
        $stmt = $db->prepare("SELECT id FROM arbor_sources WHERE tree_id = :t AND title = :title LIMIT 1");
        $stmt->execute([':t' => $treeId, ':title' => $title]);
        $sourceId = (int) $stmt->fetchColumn();
        if (!$sourceId) {
            $sourceId = $this->arbor->model('sources')->save([
                'tree_id' => $treeId,
                'title' => $title,
                'source_type' => 'other',
                'media_type' => 'OTHER',
                'author' => 'Family researcher',
                'abstract' => 'Placeholder source created from existing birth facts already entered in this tree.',
                'notes' => 'Replace this with original documents or archive records as research progresses.',
            ]);
        }

        $stmt = $db->prepare(
            "SELECT e.id AS event_id, e.person_id, e.event_date, e.event_place_str, n.given, n.surname
             FROM arbor_events e
             LEFT JOIN arbor_citations c ON c.event_id = e.id
             LEFT JOIN arbor_names n ON n.person_id = e.person_id AND n.name_type = 'BIRTH'
             WHERE e.tree_id = :t AND e.event_type = 'birth' AND c.id IS NULL
             ORDER BY e.id"
        );
        $stmt->execute([':t' => $treeId]);
        $linked = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $name = trim((string) $row['given'] . ' ' . (string) $row['surname']) ?: 'person #' . $row['person_id'];
            $parts = array_filter([$name, $row['event_date'] ?: null, $row['event_place_str'] ?: null]);
            $this->arbor->model('citations')->save([
                'source_id' => $sourceId,
                'person_id' => $row['person_id'],
                'event_id' => $row['event_id'],
                'page_ref' => 'Birth fact',
                'quality' => 2,
                'researcher' => 'Arbor',
                'notes' => 'Generated from existing tree data: ' . implode(', ', $parts),
            ]);
            $linked++;
        }

        return ['source_id' => $sourceId, 'linked' => $linked];
    }

    protected function starterCitationLeadCount(int $treeId): int
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT COUNT(*)
             FROM arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             WHERE s.tree_id = :t
               AND s.title LIKE 'Existing family data for %'
               AND c.document_id IS NULL
               AND c.event_id IS NOT NULL"
        );
        $stmt->execute([':t' => $treeId]);
        return (int) $stmt->fetchColumn();
    }

    protected function createStarterDocumentLeads(int $treeId): array
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT c.id AS citation_id, c.source_id, c.person_id, c.event_id, c.page_ref,
                    e.event_type, e.event_date, e.event_place_str, e.event_place_id,
                    n.given, n.surname
             FROM arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             JOIN arbor_events e ON e.id = c.event_id
             LEFT JOIN arbor_names n ON n.person_id = c.person_id AND n.name_type = 'BIRTH'
             WHERE s.tree_id = :t
               AND s.title LIKE 'Existing family data for %'
               AND c.document_id IS NULL
               AND c.event_id IS NOT NULL
             ORDER BY c.id"
        );
        $stmt->execute([':t' => $treeId]);
        $created = 0;
        $linked = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $personName = trim((string) ($row['given'] ?? '') . ' ' . (string) ($row['surname'] ?? '')) ?: 'Person #' . (int) $row['person_id'];
            $eventLabel = ucfirst(str_replace('_', ' ', (string) $row['event_type']));
            $title = "Find {$eventLabel} record for {$personName}";
            $documentId = $this->arbor->model('documents')->save([
                'person_id' => (int) $row['person_id'],
                'tree_id' => $treeId,
                'doc_type' => $this->eventDocumentType((string) $row['event_type']),
                'status' => 'lead',
                'title' => $title,
                'doc_date' => $row['event_date'] ?: null,
                'doc_place_id' => !empty($row['event_place_id']) ? (int) $row['event_place_id'] : null,
                'doc_place_str' => (string) ($row['event_place_str'] ?? ''),
                'description' => trim("Document lead created from starter source. Replace this with the original record, scan, URL, and citation details."),
            ]);
            $citation = $this->arbor->model('citations')->get((int) $row['citation_id']);
            if ($citation) {
                $citation['document_id'] = $documentId;
                $citation['page_ref'] = $citation['page_ref'] ?: 'Document lead';
                $this->arbor->model('citations')->save($citation, (int) $row['citation_id']);
                $linked++;
            }
            $created++;
        }
        return ['created' => $created, 'linked' => $linked];
    }

    protected function eventDocumentType(string $eventType): string
    {
        return match ($eventType) {
            'birth' => 'birth_certificate',
            'death' => 'death_certificate',
            'marriage' => 'marriage_certificate',
            'burial' => 'tombstone_inscription',
            'immigration' => 'immigration',
            'military' => 'military',
            default => 'other',
        };
    }

    protected function editSource(int $id, int $treeId): string
    {
        $arbor = $this->arbor;
        $input = $this->wire('input');
        $src = $id ? $arbor->model('sources')->get($id) : ['tree_id' => $treeId, 'source_type' => 'other', 'media_type' => 'OTHER'];
        if ($id && !$src) throw new Wire404Exception();
        $tree = $arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        if (!$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        if ($input->post('save')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            foreach (['title','author','publisher','pub_place','pub_date','edition','volume',
                      'url','isbn','language','archive_name','archive_abbrev','fond','fond_title',
                      'opis','delo','delo_title','microfilm_reel','digital_url','ee_template'] as $f) {
                $src[$f] = $input->post->text($f) ?? '';
            }
            foreach (['abstract','full_text','translation','ee_citation','notes'] as $f) {
                $src[$f] = $input->post->textarea($f) ?? '';
            }
            $repoId = (int) $input->post('repo_id') ?: null;
            if ($repoId) $this->requireRecordInTree('repositories', $repoId, $treeId);
            $src['tree_id']     = $treeId;
            $src['repo_id']     = $repoId;
            $src['source_type'] = $input->post('source_type') ?: 'other';
            $src['media_type']  = $input->post('media_type') ?: 'OTHER';
            $newId = $arbor->model('sources')->save($src, $id ?: null);
            $this->message($id ? 'Source updated' : 'Source created');
            $this->wire('session')->redirect($this->url('source', ['id' => $newId]));
        }
        return $this->renderTemplate('arbor-source-edit', [
            'tree'   => $tree,
            'source' => $src,
            'id'     => $id,
            'repos'  => $arbor->model('repositories')->forTree($treeId),
            'citations' => $id ? $this->citationsForSource($id) : [],
        ]);
    }

    protected function citationsForSource(int $sourceId): array
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT c.*, e.event_type, e.event_date, e.event_place_str, n.given, n.surname,
                    d.title AS document_title, d.filename AS document_filename,
                    d.external_url AS document_url, d.tree_id AS document_tree_id,
                    d.person_id AS document_person_id
             FROM arbor_citations c
             LEFT JOIN arbor_events e ON e.id = c.event_id
             LEFT JOIN arbor_names n ON n.person_id = c.person_id AND n.name_type = 'BIRTH'
             LEFT JOIN arbor_documents d ON d.id = c.document_id
             WHERE c.source_id = :s
             ORDER BY c.id"
        );
        $stmt->execute([':s' => $sourceId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['document_file_url'] = !empty($row['document_filename'])
                ? $this->arbor->uploadUrl((int) $row['document_tree_id'], (int) $row['document_person_id']) . $row['document_filename']
                : '';
        }
        unset($row);
        return $rows;
    }

    protected function reposList(array $tree): string
    {
        $treeId = (int) $tree['id'];
        $repos = $this->arbor->model('repositories')->forTree($treeId);
        $unassignedSources = $this->unassignedSourceCount($treeId);
        $rows = '';
        foreach ($repos as $r) {
            $meta = array_filter([
                $r['abbreviation'] ?: null,
                $r['city'] ?: null,
                $r['country'] ?: null,
                $r['website'] ? 'Website' : null,
            ]);
            $rows .= sprintf(
                '<a class="arbor-list-row" href="%s"><span class="arbor-list-main">%s</span><span class="arbor-list-meta">%s</span></a>',
                $this->url('repo', ['id' => $r['id']]),
                htmlspecialchars($r['name']),
                htmlspecialchars(implode(' · ', $meta))
            );
        }
        $name = htmlspecialchars($tree['name']);
        $count = count($repos);
        $newUrl = $this->url('repo', ['tree' => $treeId]);
        $treeUrl = $this->url('tree', ['id' => $treeId]);
        $viewerUrl = $this->url('viewer', ['tree' => $treeId]);
        $familyArchiveButton = '';
        if ($unassignedSources > 0) {
            $familyArchiveButton = "<form method='post' class='arbor-inline-form'>
                {$this->wire('session')->CSRF->renderInput()}
                <button class='uk-button uk-button-default' type='submit' name='create_family_archive' value='1'>
                    <span uk-icon='icon: album'></span> Create family archive ($unassignedSources)
                </button>
            </form>";
        }
        $body = $rows
            ? "<div class='arbor-list'>$rows</div>"
            : "<div class='arbor-empty'>
                 <span uk-icon='icon: album; ratio: 3'></span>
                 <h4>No archives or websites yet</h4>
                 <p>Add archives, libraries, websites, and databases where your sources are kept.</p>
                 <a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add first archive</a>
               </div>";
        return "<div class='pw-wrap Arbor'>
            <p class='uk-text-muted'>$count archives and repositories in $name. Track archives, libraries, websites, and databases where sources are kept.</p>
            <div class='arbor-toolbar'>
                <a class='uk-button uk-button-primary' href='$newUrl'><span uk-icon='icon: plus'></span> Add archive</a>
                <a class='uk-button uk-button-default' href='$treeUrl'><span uk-icon='icon: tree'></span> Tree overview</a>
                <a class='uk-button uk-button-default' href='$viewerUrl'><span uk-icon='icon: image'></span> Tree viewer</a>
                $familyArchiveButton
            </div>
            $body
        </div>";
    }

    protected function unassignedSourceCount(int $treeId): int
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT COUNT(*) FROM arbor_sources WHERE tree_id = :t AND repo_id IS NULL"
        );
        $stmt->execute([':t' => $treeId]);
        return (int) $stmt->fetchColumn();
    }

    protected function createFamilyArchive(array $tree): array
    {
        $treeId = (int) $tree['id'];
        $name = 'Family archive';
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT id FROM arbor_repositories WHERE tree_id = :t AND name = :name LIMIT 1");
        $stmt->execute([':t' => $treeId, ':name' => $name]);
        $repoId = (int) $stmt->fetchColumn();
        if (!$repoId) {
            $repoId = $this->arbor->model('repositories')->save([
                'tree_id' => $treeId,
                'name' => $name,
                'abbreviation' => 'Family',
                'notes' => 'Private family files, notes, oral history, and imported starter facts for this tree.',
            ]);
        }

        $update = $db->prepare("UPDATE arbor_sources SET repo_id = :repo WHERE tree_id = :t AND repo_id IS NULL");
        $update->execute([':repo' => $repoId, ':t' => $treeId]);
        return ['repo_id' => $repoId, 'linked' => $update->rowCount()];
    }

    protected function editRepo(int $id, int $treeId): string
    {
        $arbor = $this->arbor;
        $input = $this->wire('input');
        $repo = $id ? $arbor->model('repositories')->get($id) : ['tree_id' => $treeId];
        if ($id && !$repo) throw new Wire404Exception();
        $tree = $arbor->model('trees')->get($treeId);
        if (!$tree) throw new Wire404Exception('Tree not found');
        if (!$this->arbor->canViewTree($tree)) throw new WirePermissionException('You do not have permission to view this tree');
        if ($input->post('save')) {
            $this->requireValidPost();
            $this->requireEditTree($tree);
            foreach (['name','abbreviation','name_original','city','country','website','hours'] as $f) {
                $repo[$f] = $input->post->text($f) ?? '';
            }
            foreach (['address','finding_aids','access_policy','notes'] as $f) {
                $repo[$f] = $input->post->textarea($f) ?? '';
            }
            $repo['tree_id'] = $treeId;
            $newId = $arbor->model('repositories')->save($repo, $id ?: null);
            $this->message($id ? 'Archive updated' : 'Archive created');
            $this->wire('session')->redirect($this->url('repo', ['id' => $newId]));
        }
        return $this->renderTemplate('arbor-repo-edit', [
            'tree' => $tree,
            'repo' => $repo,
            'id'   => $id,
        ]);
    }

    protected function researchList(array $tree): string
    {
        $arbor = $this->arbor;
        $treeId = (int) $tree['id'];
        $taskView = (string) $this->wire('input')->get('task_view');
        if (!in_array($taskView, ['all', 'open', 'in_progress', 'done', 'cancelled'], true)) $taskView = 'open';
        $questionView = (string) $this->wire('input')->get('question_view');
        if (!in_array($questionView, ['all', 'open', 'answered', 'abandoned'], true)) $questionView = 'open';
        $logView = (string) $this->wire('input')->get('log_view');
        if (!in_array($logView, ['all', 'positive', 'negative', 'inconclusive'], true)) $logView = 'all';
        $proofView = (string) $this->wire('input')->get('proof_view');
        if (!in_array($proofView, ['all', 'draft', 'final'], true)) $proofView = 'all';
        $logDefaults = [
            'question_id' => $this->validatedResearchPrefillId($treeId, 'log_question', 'research_questions'),
            'person_id' => $this->validatedResearchPrefillId($treeId, 'log_person', 'persons'),
            'repo_id' => $this->validatedResearchPrefillId($treeId, 'log_repo', 'repositories'),
            'source_id' => $this->validatedResearchPrefillId($treeId, 'log_source', 'sources'),
            'result' => (string) $this->wire('input')->get('log_result'),
        ];
        if (!in_array($logDefaults['result'], ['positive', 'negative', 'inconclusive'], true)) {
            $logDefaults['result'] = 'inconclusive';
        }
        $proofDefaults = [
            'question_id' => $this->validatedResearchPrefillId($treeId, 'proof_question', 'research_questions'),
            'person_id' => $this->validatedResearchPrefillId($treeId, 'proof_person', 'persons'),
            'status' => (string) $this->wire('input')->get('proof_status'),
        ];
        if (!in_array($proofDefaults['status'], ['draft', 'final'], true)) {
            $proofDefaults['status'] = 'draft';
        }
        $taskDefaults = [
            'title' => trim((string) ($this->wire('input')->get->text('task_title') ?? '')),
            'person_id' => $this->validatedResearchPrefillId($treeId, 'task_person', 'persons'),
            'source_id' => $this->validatedResearchPrefillId($treeId, 'task_source', 'sources'),
            'task_type' => (string) $this->wire('input')->get('task_type'),
            'priority' => (string) $this->wire('input')->get('task_priority'),
        ];
        if (!in_array($taskDefaults['task_type'], ['general', 'parents', 'source_review', 'document', 'dna'], true)) {
            $taskDefaults['task_type'] = 'general';
        }
        if (!in_array($taskDefaults['priority'], ['low', 'medium', 'high', 'urgent'], true)) {
            $taskDefaults['priority'] = 'medium';
        }

        $allTasks = $arbor->model('tasks')->forTree($treeId);
        $allQuestions = $arbor->model('research')->questionsForTree($treeId);
        $allLogs = $arbor->model('research')->logForTree($treeId);
        $allProofs = $arbor->model('research')->proofsForTree($treeId);
        $questionActivity = [];
        foreach ($allQuestions as $question) {
            $questionActivity[(int) $question['id']] = ['logs' => 0, 'proofs' => 0, 'finals' => 0];
        }
        foreach ($allLogs as $log) {
            $qid = (int) ($log['question_id'] ?? 0);
            if ($qid && isset($questionActivity[$qid])) $questionActivity[$qid]['logs']++;
        }
        foreach ($allProofs as $proof) {
            $qid = (int) ($proof['question_id'] ?? 0);
            if ($qid && isset($questionActivity[$qid])) {
                $questionActivity[$qid]['proofs']++;
                if ((string) ($proof['status'] ?? '') === 'final') $questionActivity[$qid]['finals']++;
            }
        }
        $today = date('Y-m-d');
        $nextActions = [
            'questions_without_search' => 0,
            'draft_conclusions' => 0,
            'negative_searches' => 0,
            'due_tasks' => 0,
        ];
        foreach ($allQuestions as $question) {
            $qid = (int) $question['id'];
            if ((string) ($question['status'] ?? '') === 'open' && (int) ($questionActivity[$qid]['logs'] ?? 0) === 0) {
                $nextActions['questions_without_search']++;
            }
        }
        foreach ($allProofs as $proof) {
            if ((string) ($proof['status'] ?? '') === 'draft') $nextActions['draft_conclusions']++;
        }
        foreach ($allLogs as $log) {
            if ((string) ($log['result'] ?? '') === 'negative') $nextActions['negative_searches']++;
        }
        foreach ($allTasks as $task) {
            $dueDate = (string) ($task['due_date'] ?? '');
            if (in_array((string) ($task['status'] ?? ''), ['open', 'in_progress'], true) && $dueDate !== '' && $dueDate <= $today) {
                $nextActions['due_tasks']++;
            }
        }
        $researchTotals = [
            'hours' => 0.0,
            'cost' => 0.0,
        ];
        foreach ($allLogs as $log) {
            $researchTotals['hours'] += (float) ($log['hours'] ?? 0);
            $researchTotals['cost'] += (float) ($log['cost'] ?? 0);
        }
        $taskCounts = ['all' => count($allTasks), 'open' => 0, 'in_progress' => 0, 'done' => 0, 'cancelled' => 0];
        foreach ($allTasks as $task) {
            $status = (string) ($task['status'] ?? '');
            if (array_key_exists($status, $taskCounts)) $taskCounts[$status]++;
        }
        $questionCounts = ['all' => count($allQuestions), 'open' => 0, 'answered' => 0, 'abandoned' => 0];
        foreach ($allQuestions as $question) {
            $status = (string) ($question['status'] ?? '');
            if (array_key_exists($status, $questionCounts)) $questionCounts[$status]++;
        }
        $logCounts = ['all' => count($allLogs), 'positive' => 0, 'negative' => 0, 'inconclusive' => 0];
        foreach ($allLogs as $log) {
            $result = (string) ($log['result'] ?? '');
            if (array_key_exists($result, $logCounts)) $logCounts[$result]++;
        }
        $proofCounts = ['all' => count($allProofs), 'draft' => 0, 'final' => 0];
        foreach ($allProofs as $proof) {
            $status = (string) ($proof['status'] ?? '');
            if (array_key_exists($status, $proofCounts)) $proofCounts[$status]++;
        }
        $tasks = $taskView === 'all'
            ? $allTasks
            : array_values(array_filter($allTasks, fn($task) => (string) ($task['status'] ?? '') === $taskView));
        $questions = $questionView === 'all'
            ? $allQuestions
            : array_values(array_filter($allQuestions, fn($question) => (string) ($question['status'] ?? '') === $questionView));
        $logs = $logView === 'all'
            ? $allLogs
            : array_values(array_filter($allLogs, fn($log) => (string) ($log['result'] ?? '') === $logView));
        $proofs = $proofView === 'all'
            ? $allProofs
            : array_values(array_filter($allProofs, fn($proof) => (string) ($proof['status'] ?? '') === $proofView));

        return $this->renderTemplate('arbor-research', [
            'tree'      => $tree,
            'questions' => $questions,
            'allQuestions' => $allQuestions,
            'logs'      => $logs,
            'proofs'    => $proofs,
            'tasks'     => $tasks,
            'taskView'  => $taskView,
            'questionView' => $questionView,
            'logView' => $logView,
            'proofView' => $proofView,
            'taskCounts' => $taskCounts,
            'questionCounts' => $questionCounts,
            'logCounts' => $logCounts,
            'proofCounts' => $proofCounts,
            'questionActivity' => $questionActivity,
            'nextActions' => $nextActions,
            'researchTotals' => $researchTotals,
            'persons'   => $arbor->model('persons')->findByTree($treeId, ['limit' => 500]),
            'sources'   => $arbor->model('sources')->forTree($treeId),
            'repos'     => $arbor->model('repositories')->forTree($treeId),
            'logDefaults' => $logDefaults,
            'proofDefaults' => $proofDefaults,
            'taskDefaults' => $taskDefaults,
            'canCreatePlan' => $this->canCreateResearchPlan($treeId),
        ]);
    }

    protected function validatedResearchPrefillId(int $treeId, string $param, string $entity): ?int
    {
        $id = (int) $this->wire('input')->get($param);
        if (!$id) return null;
        return $this->arbor->treeIdForRecord($entity, $id) === $treeId ? $id : null;
    }

    protected function canCreateResearchPlan(int $treeId): bool
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT
                (SELECT COUNT(*) FROM arbor_research_questions WHERE tree_id = :t1) +
                (SELECT COUNT(*) FROM arbor_tasks WHERE tree_id = :t2) AS total"
        );
        $stmt->execute([':t1' => $treeId, ':t2' => $treeId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    protected function updateResearchTaskStatus(int $treeId): void
    {
        $input = $this->wire('input');
        $taskId = (int) $input->post('task_id');
        $status = (string) $input->post('task_status');
        if (!in_array($status, ['open', 'in_progress', 'done', 'cancelled'], true)) {
            throw new WireException('Invalid task status');
        }
        $this->requireRecordInTree('tasks', $taskId, $treeId);
        $task = $this->arbor->model('tasks')->get($taskId);
        if (!$task) throw new Wire404Exception('Task not found');
        $task['status'] = $status;
        $this->arbor->model('tasks')->save($task, $taskId);
        $this->message('Task updated');
    }

    protected function updateResearchQuestionStatus(int $treeId): void
    {
        $input = $this->wire('input');
        $questionId = (int) $input->post('question_id');
        $status = (string) $input->post('question_status');
        if (!in_array($status, ['open', 'answered', 'abandoned'], true)) {
            throw new WireException('Invalid question status');
        }
        $this->requireRecordInTree('research_questions', $questionId, $treeId);
        $question = $this->arbor->model('research')->getQuestion($questionId);
        if (!$question) throw new Wire404Exception('Question not found');
        $question['status'] = $status;
        $question['closed_date'] = $status === 'open' ? null : date('Y-m-d');
        $this->arbor->model('research')->saveQuestion($question, $questionId);
        $this->message('Question updated');
    }

    protected function addResearchTask(int $treeId): void
    {
        $this->saveResearchTaskFromPost($treeId);
        $this->message('Task added');
    }

    protected function updateResearchTask(int $treeId): void
    {
        $taskId = (int) $this->wire('input')->post('task_id');
        if (!$taskId) throw new WireException('Missing task');
        $this->requireRecordInTree('tasks', $taskId, $treeId);
        $this->saveResearchTaskFromPost($treeId, $taskId);
        $this->message('Task updated');
    }

    protected function saveResearchTaskFromPost(int $treeId, ?int $taskId = null): int
    {
        $input = $this->wire('input');
        $title = trim((string) $input->post->text('task_title'));
        if ($title === '') throw new WireException('Add a task title');
        $personId = (int) $input->post('task_person_id') ?: null;
        $sourceId = (int) $input->post('task_source_id') ?: null;
        if ($personId) $this->requireRecordInTree('persons', $personId, $treeId);
        if ($sourceId) $this->requireRecordInTree('sources', $sourceId, $treeId);
        $priority = (string) $input->post('task_priority');
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) $priority = 'medium';
        $status = (string) $input->post('task_status_edit');
        if (!in_array($status, ['open', 'in_progress', 'done', 'cancelled'], true)) $status = 'open';
        $taskType = trim((string) $input->post->text('task_type'));
        if ($taskType === '') $taskType = 'general';
        return $this->arbor->model('tasks')->save([
            'tree_id' => $treeId,
            'person_id' => $personId,
            'source_id' => $sourceId,
            'task_type' => $taskType,
            'title' => $title,
            'description' => $input->post->textarea('task_description') ?? '',
            'status' => $status,
            'priority' => $priority,
            'due_date' => $input->post->text('task_due_date') ?: null,
            'assigned_to' => $input->post->text('task_assigned_to') ?? '',
        ], $taskId);
    }

    protected function deleteResearchTask(int $treeId): void
    {
        $taskId = (int) $this->wire('input')->post('task_id');
        if (!$taskId) throw new WireException('Missing task');
        $this->requireRecordInTree('tasks', $taskId, $treeId);
        $this->arbor->model('tasks')->delete($taskId);
        $this->message('Task deleted');
    }

    protected function addResearchQuestion(int $treeId): void
    {
        $this->saveResearchQuestionFromPost($treeId);
        $this->message('Question added');
    }

    protected function updateResearchQuestion(int $treeId): void
    {
        $questionId = (int) $this->wire('input')->post('question_id');
        if (!$questionId) throw new WireException('Missing question');
        $this->requireRecordInTree('research_questions', $questionId, $treeId);
        $this->saveResearchQuestionFromPost($treeId, $questionId);
        $this->message('Question updated');
    }

    protected function saveResearchQuestionFromPost(int $treeId, ?int $questionId = null): int
    {
        $input = $this->wire('input');
        $question = trim((string) $input->post->textarea('question'));
        if ($question === '') throw new WireException('Add a question');
        $personId = (int) $input->post('question_person_id') ?: null;
        if ($personId) $this->requireRecordInTree('persons', $personId, $treeId);
        $status = (string) $input->post('question_status_edit');
        if (!in_array($status, ['open', 'answered', 'abandoned'], true)) $status = 'open';
        return $this->arbor->model('research')->saveQuestion([
            'tree_id' => $treeId,
            'person_id' => $personId,
            'question' => $question,
            'status' => $status,
            'opened_date' => $input->post->text('opened_date') ?: date('Y-m-d'),
            'closed_date' => $status === 'open' ? null : ($input->post->text('closed_date') ?: date('Y-m-d')),
            'notes' => $input->post->textarea('question_notes') ?? '',
        ], $questionId);
    }

    protected function deleteResearchQuestion(int $treeId): void
    {
        $questionId = (int) $this->wire('input')->post('question_id');
        if (!$questionId) throw new WireException('Missing question');
        $this->requireRecordInTree('research_questions', $questionId, $treeId);
        $unlink = $this->wire('database')->prepare(
            "UPDATE arbor_research_log SET question_id = NULL WHERE tree_id = :tree AND question_id = :question"
        );
        $unlink->execute([':tree' => $treeId, ':question' => $questionId]);
        $this->arbor->model('research')->deleteQuestion($questionId);
        $this->message('Question deleted');
    }

    protected function addResearchLog(int $treeId): void
    {
        $this->saveResearchLogFromPost($treeId);
        $this->message('Search log entry added');
    }

    protected function updateResearchLog(int $treeId): void
    {
        $logId = (int) $this->wire('input')->post('log_id');
        if (!$logId) throw new WireException('Missing log entry');
        $this->requireRecordInTree('research_log', $logId, $treeId);
        $this->saveResearchLogFromPost($treeId, $logId);
        $this->message('Search log entry updated');
    }

    protected function saveResearchLogFromPost(int $treeId, ?int $logId = null): int
    {
        $input = $this->wire('input');
        $questionId = (int) $input->post('question_id') ?: null;
        $personId = (int) $input->post('person_id') ?: null;
        $repoId = (int) $input->post('repo_id') ?: null;
        $sourceId = (int) $input->post('source_id') ?: null;
        if ($questionId) $this->requireRecordInTree('research_questions', $questionId, $treeId);
        if ($personId) $this->requireRecordInTree('persons', $personId, $treeId);
        if ($repoId) $this->requireRecordInTree('repositories', $repoId, $treeId);
        if ($sourceId) $this->requireRecordInTree('sources', $sourceId, $treeId);

        $searchTerms = trim((string) $input->post->text('search_terms'));
        if ($searchTerms === '') throw new WireException('Search terms are required');
        $result = (string) $input->post('result');
        if (!in_array($result, ['positive', 'negative', 'inconclusive'], true)) $result = 'inconclusive';
        $sourceClass = (string) $input->post('source_class');
        if (!in_array($sourceClass, ['original', 'derivative', 'authored'], true)) $sourceClass = 'original';
        $infoClass = (string) $input->post('info_class');
        if (!in_array($infoClass, ['primary', 'secondary', 'indeterminate'], true)) $infoClass = 'primary';
        $evidenceClass = (string) $input->post('evidence_class');
        if (!in_array($evidenceClass, ['direct', 'indirect', 'negative'], true)) $evidenceClass = 'direct';

        return $this->arbor->model('research')->saveLog([
            'question_id' => $questionId,
            'tree_id' => $treeId,
            'person_id' => $personId,
            'log_date' => $input->post->text('log_date') ?: date('Y-m-d'),
            'repo_id' => $repoId,
            'source_id' => $sourceId,
            'search_terms' => $searchTerms,
            'result' => $result,
            'source_class' => $sourceClass,
            'info_class' => $infoClass,
            'evidence_class' => $evidenceClass,
            'hours' => (float) $input->post('hours'),
            'cost' => (float) $input->post('cost'),
            'notes' => $input->post->textarea('notes') ?? '',
        ], $logId);
    }

    protected function deleteResearchLog(int $treeId): void
    {
        $logId = (int) $this->wire('input')->post('log_id');
        if (!$logId) throw new WireException('Missing log entry');
        $this->requireRecordInTree('research_log', $logId, $treeId);
        $this->arbor->model('research')->deleteLog($logId);
        $this->message('Search log entry deleted');
    }

    protected function addProofArgument(int $treeId): void
    {
        $this->saveProofArgumentFromPost($treeId);
        $this->message('Conclusion added');
    }

    protected function updateProofArgument(int $treeId): void
    {
        $proofId = (int) $this->wire('input')->post('proof_id');
        if (!$proofId) throw new WireException('Missing conclusion');
        $this->requireRecordInTree('proof_arguments', $proofId, $treeId);
        $this->saveProofArgumentFromPost($treeId, $proofId);
        $this->message('Conclusion updated');
    }

    protected function saveProofArgumentFromPost(int $treeId, ?int $proofId = null): int
    {
        $input = $this->wire('input');
        $questionId = (int) $input->post('proof_question_id') ?: null;
        $personId = (int) $input->post('proof_person_id') ?: null;
        if ($questionId) $this->requireRecordInTree('research_questions', $questionId, $treeId);
        if ($personId) $this->requireRecordInTree('persons', $personId, $treeId);

        $title = trim((string) $input->post->text('proof_title'));
        if ($title === '') throw new WireException('Conclusion title is required');
        $status = (string) $input->post('proof_status');
        if (!in_array($status, ['draft', 'final'], true)) $status = 'draft';

        $savedId = $this->arbor->model('research')->saveProof([
            'tree_id' => $treeId,
            'person_id' => $personId,
            'question_id' => $questionId,
            'title' => $title,
            'argument' => $input->post->textarea('proof_argument') ?? '',
            'conclusion' => $input->post->textarea('proof_conclusion') ?? '',
            'conflicts' => $input->post->textarea('proof_conflicts') ?? '',
            'status' => $status,
        ], $proofId);
        if ($status === 'final' && $questionId) {
            $this->markResearchQuestionAnswered($treeId, $questionId);
        }
        return $savedId;
    }

    protected function deleteProofArgument(int $treeId): void
    {
        $proofId = (int) $this->wire('input')->post('proof_id');
        if (!$proofId) throw new WireException('Missing conclusion');
        $this->requireRecordInTree('proof_arguments', $proofId, $treeId);
        $this->arbor->model('research')->deleteProof($proofId);
        $this->message('Conclusion deleted');
    }

    protected function updateProofStatus(int $treeId): void
    {
        $input = $this->wire('input');
        $proofId = (int) $input->post('proof_id');
        $status = (string) $input->post('proof_status');
        if (!in_array($status, ['draft', 'final'], true)) {
            throw new WireException('Invalid conclusion status');
        }
        $this->requireRecordInTree('proof_arguments', $proofId, $treeId);
        $proof = $this->arbor->model('research')->getProof($proofId);
        if (!$proof) throw new Wire404Exception('Conclusion not found');
        $proof['status'] = $status;
        $this->arbor->model('research')->saveProof($proof, $proofId);
        if ($status === 'final' && !empty($proof['question_id'])) {
            $this->markResearchQuestionAnswered($treeId, (int) $proof['question_id']);
        }
        $this->message('Conclusion updated');
    }

    protected function markResearchQuestionAnswered(int $treeId, int $questionId): void
    {
        $this->requireRecordInTree('research_questions', $questionId, $treeId);
        $question = $this->arbor->model('research')->getQuestion($questionId);
        if (!$question || (string) ($question['status'] ?? '') === 'answered') return;
        $question['status'] = 'answered';
        $question['closed_date'] = date('Y-m-d');
        $this->arbor->model('research')->saveQuestion($question, $questionId);
    }

    protected function createStarterResearchPlan(array $tree): array
    {
        $treeId = (int) $tree['id'];
        $research = $this->arbor->model('research');
        $tasks = $this->arbor->model('tasks');
        $createdQuestions = 0;
        $createdTasks = 0;
        $today = date('Y-m-d');

        $db = $this->wire('database');
        $stmt = $db->prepare(
            "SELECT p.id, n.given, n.surname
             FROM arbor_persons p
             LEFT JOIN arbor_names n ON n.person_id = p.id AND n.name_type = 'BIRTH'
             LEFT JOIN arbor_union_children uc ON uc.person_id = p.id
             WHERE p.tree_id = :t AND uc.id IS NULL
             ORDER BY p.id
             LIMIT 4"
        );
        $stmt->execute([':t' => $treeId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $person) {
            $name = trim((string) $person['given'] . ' ' . (string) $person['surname']) ?: 'person #' . $person['id'];
            $question = 'Who were the parents of ' . $name . '?';
            if (!$this->researchQuestionExists($treeId, $question)) {
                $questionId = $research->saveQuestion([
                    'tree_id' => $treeId,
                    'person_id' => $person['id'],
                    'question' => $question,
                    'status' => 'open',
                    'opened_date' => $today,
                    'notes' => 'Starter question generated from a person with no linked parents.',
                ]);
                $createdQuestions++;
                $tasks->save([
                    'tree_id' => $treeId,
                    'person_id' => $person['id'],
                    'task_type' => 'parents',
                    'title' => 'Find parents for ' . $name,
                    'description' => 'Search birth, marriage, census, revision list, and family records for parents of ' . $name . '. Related question #' . $questionId . '.',
                    'status' => 'open',
                    'priority' => 'high',
                ]);
                $createdTasks++;
            }
        }

        $stmt = $db->prepare(
            "SELECT s.id, s.title
             FROM arbor_sources s
             WHERE s.tree_id = :t AND s.title LIKE 'Existing family data for %'
             LIMIT 1"
        );
        $stmt->execute([':t' => $treeId]);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($source && !$this->taskExists($treeId, 'Replace starter source with original records')) {
            $tasks->save([
                'tree_id' => $treeId,
                'source_id' => $source['id'],
                'task_type' => 'source_review',
                'title' => 'Replace starter source with original records',
                'description' => 'The current source groups facts that were already entered. Add real birth records, archive references, URLs, scans, or citations for each person.',
                'status' => 'open',
                'priority' => 'urgent',
            ]);
            $createdTasks++;
        }

        if (!$this->researchQuestionExists($treeId, 'Which facts are supported by original sources?')) {
            $research->saveQuestion([
                'tree_id' => $treeId,
                'question' => 'Which facts are supported by original sources?',
                'status' => 'open',
                'opened_date' => $today,
                'notes' => 'Use this to separate family-memory data from facts backed by original records.',
            ]);
            $createdQuestions++;
        }

        return ['questions' => $createdQuestions, 'tasks' => $createdTasks];
    }

    protected function researchQuestionExists(int $treeId, string $question): bool
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT COUNT(*) FROM arbor_research_questions WHERE tree_id = :t AND question = :q"
        );
        $stmt->execute([':t' => $treeId, ':q' => $question]);
        return (int) $stmt->fetchColumn() > 0;
    }

    protected function taskExists(int $treeId, string $title): bool
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT COUNT(*) FROM arbor_tasks WHERE tree_id = :t AND title = :title"
        );
        $stmt->execute([':t' => $treeId, ':title' => $title]);
        return (int) $stmt->fetchColumn() > 0;
    }

    protected function dnaList(array $tree): string
    {
        $db = $this->wire('database');
        $treeId = (int) $tree['id'];
        $filter = (string) $this->wire('input')->get('filter');
        if ($filter !== 'no_segments') $filter = '';
        $stmt = $db->prepare("SELECT k.*, n.given, n.patronymic, n.surname,
                                     COUNT(DISTINCT m.id) AS match_count,
                                     COUNT(DISTINCT s.id) AS segment_count
                              FROM arbor_dna_kits k
                              LEFT JOIN arbor_persons p ON p.id = k.person_id
                              LEFT JOIN arbor_names n ON n.person_id = p.id AND n.name_type='BIRTH'
                              LEFT JOIN arbor_dna_matches m ON m.kit_a_id = k.id
                              LEFT JOIN arbor_dna_segments s ON s.match_id = m.id
                              WHERE k.tree_id = :t GROUP BY k.id");
        $stmt->execute([':t' => $treeId]);
        $kits = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $allKits = $kits;
        $allMatchesByKit = $this->dnaMatchesByKit($treeId);
        $matchesByKit = $allMatchesByKit;
        if ($filter === 'no_segments') {
            $kitIds = [];
            foreach ($matchesByKit as $kitId => $kitMatches) {
                $matchesByKit[$kitId] = array_values(array_filter($kitMatches, fn($m) => (int) ($m['segment_count'] ?? 0) === 0));
                if (!empty($matchesByKit[$kitId])) $kitIds[(int) $kitId] = true;
            }
            $matchesByKit = array_filter($matchesByKit, fn($kitMatches) => !empty($kitMatches));
            $kits = array_values(array_filter($kits, fn($k) => isset($kitIds[(int) $k['id']])));
        }
        $segmentsByMatch = $this->dnaSegmentsByMatch($treeId);
        $persons = $this->arbor->model('persons')->findByTree($treeId, ['limit' => 500]);
        $csrf = $this->wire('session')->CSRF->renderInput();
        $rows = '';
        $companyLabels = [
            'ftdna' => 'FTDNA',
            '23andme' => '23andMe',
            'ancestrydna' => 'AncestryDNA',
            'myheritage' => 'MyHeritage',
            'livingdna' => 'LivingDNA',
            'gedmatch' => 'GEDmatch',
            'other' => 'Other',
        ];
        $typeLabels = [
            'autosomal' => 'Autosomal',
            'y_dna' => 'Y-DNA',
            'mt_dna' => 'mtDNA',
            'big_y' => 'Big Y',
            'y37' => 'Y-37',
            'y67' => 'Y-67',
            'y111' => 'Y-111',
            'y700' => 'Y-700',
            'other' => 'Other',
        ];
        foreach ($kits as $k) {
            $name = trim((string) $k['given'] . ' ' . (string) ($k['patronymic'] ?? '') . ' ' . (string) $k['surname']) ?: 'Unlinked kit';
            $company = $k['company'] === 'other' && $k['company_other'] ? $k['company_other'] : ($companyLabels[$k['company']] ?? $k['company']);
            $testType = $typeLabels[$k['test_type']] ?? $k['test_type'];
            $meta = array_filter([
                $company ?: null,
                $testType ?: null,
                $k['kit_id'] ? 'Kit ' . $k['kit_id'] : null,
                (int) $k['match_count'] ? (int) $k['match_count'] . ' matches' : null,
                (int) $k['segment_count'] ? (int) $k['segment_count'] . ' segments' : null,
            ]);
            $editPersonOptions = '';
            foreach ($persons as $p) {
                $personName = trim((string) ($p['given'] ?? '') . ' ' . (string) ($p['patronymic'] ?? '') . ' ' . (string) ($p['surname'] ?? '')) ?: 'Person #' . (int) $p['id'];
                $selected = (int) $k['person_id'] === (int) $p['id'] ? ' selected' : '';
                $editPersonOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $p['id'], $selected, htmlspecialchars($personName));
            }
            $editCompanyOptions = '';
            foreach ($companyLabels as $value => $label) {
                $selected = $value === (string) $k['company'] ? ' selected' : '';
                $editCompanyOptions .= "<option value='$value'$selected>$label</option>";
            }
            $editTypeOptions = '';
            foreach ($typeLabels as $value => $label) {
                $selected = $value === (string) $k['test_type'] ? ' selected' : '';
                $editTypeOptions .= "<option value='$value'$selected>$label</option>";
            }
            $kitEdit = "<details class='arbor-inline-editor'>
                <summary>Edit kit</summary>
                <form method='post' class='arbor-dna-edit'>
                    $csrf
                    <input type='hidden' name='kit_id' value='" . (int) $k['id'] . "'>
                    <label><span>Person</span><select class='uk-select uk-form-small' name='person_id'>$editPersonOptions</select></label>
                    <label><span>Company</span><select class='uk-select uk-form-small' name='company'>$editCompanyOptions</select></label>
                    <label><span>Other company</span><input class='uk-input uk-form-small' type='text' name='company_other' value='" . htmlspecialchars((string) ($k['company_other'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Test type</span><select class='uk-select uk-form-small' name='test_type'>$editTypeOptions</select></label>
                    <label><span>Kit ID</span><input class='uk-input uk-form-small' type='text' name='kit_code' value='" . htmlspecialchars((string) ($k['kit_id'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Test date</span><input class='uk-input uk-form-small' type='date' name='test_date' value='" . htmlspecialchars((string) ($k['test_date'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Y haplogroup</span><input class='uk-input uk-form-small' type='text' name='y_haplogroup' value='" . htmlspecialchars((string) ($k['y_haplogroup'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>mt haplogroup</span><input class='uk-input uk-form-small' type='text' name='mt_haplogroup' value='" . htmlspecialchars((string) ($k['mt_haplogroup'] ?? ''), ENT_QUOTES) . "'></label>
                    <label class='arbor-dna-edit-full'><span>Notes</span><textarea class='uk-textarea uk-form-small' rows='2' name='notes'>" . htmlspecialchars((string) ($k['notes'] ?? '')) . "</textarea></label>
                    <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_dna_kit' value='1'><span uk-icon='icon: check'></span> Save</button>
                </form>
            </details>";
            $kitDelete = "<form method='post' class='arbor-inline-form arbor-row-action' onsubmit=\"return confirm('Delete this DNA kit and its matches?')\">
                $csrf
                <input type='hidden' name='kit_id' value='" . (int) $k['id'] . "'>
                <button class='uk-button uk-button-text uk-text-danger' type='submit' name='delete_dna_kit' value='1' title='Delete DNA kit and its matches'>
                    <span uk-icon='icon: trash'></span>
                </button>
            </form>";
            $rows .= sprintf(
                '<div class="arbor-list-row"><span class="arbor-list-main">%s%s</span><span class="arbor-list-meta">%s</span>%s</div>',
                htmlspecialchars($name),
                $kitEdit,
                htmlspecialchars(implode(' · ', $meta)),
                $kitDelete
            );
            if (!empty($matchesByKit[(int) $k['id']])) {
                $matchRows = '';
                foreach ($matchesByKit[(int) $k['id']] as $m) {
                    $matchMeta = array_filter([
                        $m['predicted_relation'] ?: null,
                        ((float) $m['total_cm']) ? (float) $m['total_cm'] . ' cM' : null,
                        ((float) $m['longest_segment_cm']) ? 'longest ' . (float) $m['longest_segment_cm'] . ' cM' : null,
                        (int) $m['segment_count'] ? (int) $m['segment_count'] . ' segments' : null,
                    ]);
                    $matchEdit = "<details class='arbor-inline-editor'>
                        <summary>Edit match</summary>
                        <form method='post' class='arbor-dna-edit'>
                            $csrf
                            <input type='hidden' name='match_id' value='" . (int) $m['id'] . "'>
                            <label><span>Match name</span><input class='uk-input uk-form-small' type='text' name='kit_b_name' value='" . htmlspecialchars((string) ($m['kit_b_name'] ?? ''), ENT_QUOTES) . "'></label>
                            <label><span>Total cM</span><input class='uk-input uk-form-small' type='number' step='0.01' min='0' name='total_cm' value='" . htmlspecialchars((string) ($m['total_cm'] ?? ''), ENT_QUOTES) . "'></label>
                            <label><span>Longest cM</span><input class='uk-input uk-form-small' type='number' step='0.01' min='0' name='longest_segment_cm' value='" . htmlspecialchars((string) ($m['longest_segment_cm'] ?? ''), ENT_QUOTES) . "'></label>
                            <label><span>Predicted relation</span><input class='uk-input uk-form-small' type='text' name='predicted_relation' value='" . htmlspecialchars((string) ($m['predicted_relation'] ?? ''), ENT_QUOTES) . "'></label>
                            <label><span>Triangulation group</span><input class='uk-input uk-form-small' type='text' name='triangulation_group' value='" . htmlspecialchars((string) ($m['triangulation_group'] ?? ''), ENT_QUOTES) . "'></label>
                            <label class='arbor-dna-edit-full'><span>Notes</span><textarea class='uk-textarea uk-form-small' rows='2' name='match_notes'>" . htmlspecialchars((string) ($m['notes'] ?? '')) . "</textarea></label>
                            <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_dna_match' value='1'><span uk-icon='icon: check'></span> Save</button>
                        </form>
                    </details>";
                    $matchDelete = "<form method='post' class='arbor-inline-form arbor-row-action' onsubmit=\"return confirm('Delete this DNA match and its segments?')\">
                        $csrf
                        <input type='hidden' name='match_id' value='" . (int) $m['id'] . "'>
                        <button class='uk-button uk-button-text uk-text-danger' type='submit' name='delete_dna_match' value='1' title='Delete DNA match and its segments'>
                            <span uk-icon='icon: trash'></span>
                        </button>
                    </form>";
                    $matchRows .= sprintf(
                        '<div class="arbor-list-row arbor-subrow"><span class="arbor-list-main">%s%s</span><span class="arbor-list-meta">%s</span>%s</div>',
                        htmlspecialchars($m['kit_b_name'] ?: 'Unnamed match'),
                        $matchEdit,
                        htmlspecialchars(implode(' · ', $matchMeta)),
                        $matchDelete
                    );
                    if (!empty($segmentsByMatch[(int) $m['id']])) {
                        foreach ($segmentsByMatch[(int) $m['id']] as $s) {
                            $segmentMeta = array_filter([
                                'Chr ' . (int) $s['chromosome'],
                                number_format((int) $s['start_pos']) . '-' . number_format((int) $s['end_pos']),
                                ((float) $s['centimorgans']) ? (float) $s['centimorgans'] . ' cM' : null,
                                (int) $s['snp_count'] ? (int) $s['snp_count'] . ' SNPs' : null,
                                $s['side'] && $s['side'] !== 'unknown' ? ucfirst($s['side']) : null,
                            ]);
                            $sideOptions = '';
                            foreach (['unknown' => 'Unknown', 'maternal' => 'Maternal', 'paternal' => 'Paternal'] as $value => $label) {
                                $selected = $value === (string) $s['side'] ? ' selected' : '';
                                $sideOptions .= "<option value='$value'$selected>$label</option>";
                            }
                            $segmentEdit = "<details class='arbor-inline-editor'>
                                <summary>Edit segment</summary>
                                <form method='post' class='arbor-dna-edit'>
                                    $csrf
                                    <input type='hidden' name='segment_id' value='" . (int) $s['id'] . "'>
                                    <label><span>Chromosome</span><input class='uk-input uk-form-small' type='number' min='1' max='99' name='chromosome' value='" . (int) $s['chromosome'] . "'></label>
                                    <label><span>Start</span><input class='uk-input uk-form-small' type='number' min='0' name='start_pos' value='" . (int) $s['start_pos'] . "'></label>
                                    <label><span>End</span><input class='uk-input uk-form-small' type='number' min='0' name='end_pos' value='" . (int) $s['end_pos'] . "'></label>
                                    <label><span>cM</span><input class='uk-input uk-form-small' type='number' step='0.01' min='0' name='centimorgans' value='" . htmlspecialchars((string) ($s['centimorgans'] ?? ''), ENT_QUOTES) . "'></label>
                                    <label><span>SNP count</span><input class='uk-input uk-form-small' type='number' min='0' name='snp_count' value='" . (int) $s['snp_count'] . "'></label>
                                    <label><span>Side</span><select class='uk-select uk-form-small' name='side'>$sideOptions</select></label>
                                    <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_dna_segment' value='1'><span uk-icon='icon: check'></span> Save</button>
                                </form>
                            </details>";
                            $segmentDelete = "<form method='post' class='arbor-inline-form arbor-row-action' onsubmit=\"return confirm('Delete this DNA segment?')\">
                                $csrf
                                <input type='hidden' name='segment_id' value='" . (int) $s['id'] . "'>
                                <button class='uk-button uk-button-text uk-text-danger' type='submit' name='delete_dna_segment' value='1' title='Delete segment'>
                                    <span uk-icon='icon: trash'></span>
                                </button>
                            </form>";
                            $matchRows .= sprintf(
                                '<div class="arbor-list-row arbor-subrow arbor-segment-row"><span class="arbor-list-main">%s%s</span><span class="arbor-list-meta">%s</span>%s</div>',
                                'Segment',
                                $segmentEdit,
                                htmlspecialchars(implode(' · ', $segmentMeta)),
                                $segmentDelete
                            );
                        }
                    }
                }
                $rows .= "<div class='arbor-sublist'>$matchRows</div>";
            }
        }
        $name = htmlspecialchars($tree['name']);
        $treeUrl = $this->url('tree', ['id' => $treeId]);
        $viewerUrl = $this->url('viewer', ['tree' => $treeId]);
        $clearUrl = $this->url('dna', ['tree' => $treeId]);
        $filterNote = $filter === 'no_segments'
            ? "<div class='arbor-filter-note'>Showing: <strong>DNA matches without segments</strong> <a href='$clearUrl'>show all DNA</a></div>"
            : '';
        $personOptions = '<option value="">Choose person</option>';
        foreach ($persons as $p) {
            $personName = trim((string) ($p['given'] ?? '') . ' ' . (string) ($p['patronymic'] ?? '') . ' ' . (string) ($p['surname'] ?? '')) ?: 'Person #' . (int) $p['id'];
            $personOptions .= sprintf('<option value="%d">%s</option>', (int) $p['id'], htmlspecialchars($personName));
        }
        $companyOptions = [
            'ftdna' => 'FTDNA',
            '23andme' => '23andMe',
            'ancestrydna' => 'AncestryDNA',
            'myheritage' => 'MyHeritage',
            'livingdna' => 'LivingDNA',
            'gedmatch' => 'GEDmatch',
            'other' => 'Other',
        ];
        $companySelect = '';
        foreach ($companyOptions as $value => $label) $companySelect .= "<option value='$value'>$label</option>";
        $typeOptions = [
            'autosomal' => 'Autosomal',
            'y_dna' => 'Y-DNA',
            'mt_dna' => 'mtDNA',
            'big_y' => 'Big Y',
            'y37' => 'Y-37',
            'y67' => 'Y-67',
            'y111' => 'Y-111',
            'y700' => 'Y-700',
            'other' => 'Other',
        ];
        $typeSelect = '';
        foreach ($typeOptions as $value => $label) $typeSelect .= "<option value='$value'>$label</option>";
        $form = "<form class='arbor-log-form' method='post'>
            $csrf
            <div class='arbor-simple-grid'>
                <label><span>Person</span><select class='uk-select' name='person_id' required>$personOptions</select></label>
                <label><span>Company</span><select class='uk-select' name='company'>$companySelect</select></label>
                <label><span>Other company</span><input class='uk-input' type='text' name='company_other'></label>
                <label><span>Test type</span><select class='uk-select' name='test_type'>$typeSelect</select></label>
                <label><span>Kit ID</span><input class='uk-input' type='text' name='kit_id'></label>
                <label><span>Test date</span><input class='uk-input' type='date' name='test_date'></label>
                <label><span>Y haplogroup</span><input class='uk-input' type='text' name='y_haplogroup'></label>
                <label><span>mt haplogroup</span><input class='uk-input' type='text' name='mt_haplogroup'></label>
            </div>
            <label class='arbor-simple-full'><span>Notes</span><textarea class='uk-textarea' rows='2' name='notes' placeholder='Consent, tester, match list source, or next step.'></textarea></label>
            <button class='uk-button uk-button-primary' type='submit' name='add_dna_kit' value='1'><span uk-icon='icon: plus'></span> Add DNA kit</button>
        </form>";
        $kitOptions = '<option value="">Choose kit</option>';
        foreach ($allKits as $k) {
            $kitName = trim((string) $k['given'] . ' ' . (string) ($k['patronymic'] ?? '') . ' ' . (string) $k['surname']) ?: 'Kit #' . (int) $k['id'];
            $kitOptions .= sprintf('<option value="%d">%s</option>', (int) $k['id'], htmlspecialchars($kitName));
        }
        $ancestorOptions = '<option value="">Unknown</option>' . str_replace('Choose person', 'Choose ancestor', $personOptions);
        $matchForm = "<form class='arbor-log-form' method='post'>
            $csrf
            <div class='arbor-simple-grid'>
                <label><span>Kit</span><select class='uk-select' name='kit_a_id' required>$kitOptions</select></label>
                <label><span>Match name</span><input class='uk-input' type='text' name='kit_b_name' required placeholder='Name as shown by testing company'></label>
                <label><span>Total shared cM</span><input class='uk-input' type='number' step='0.01' min='0' name='total_cm'></label>
                <label><span>Longest segment cM</span><input class='uk-input' type='number' step='0.01' min='0' name='longest_segment_cm'></label>
                <label><span>Predicted relation</span><input class='uk-input' type='text' name='predicted_relation' placeholder='2nd cousin, 3C1R, etc.'></label>
                <label><span>Common ancestor</span><select class='uk-select' name='common_ancestor_id'>$ancestorOptions</select></label>
                <label><span>Triangulation group</span><input class='uk-input' type='text' name='triangulation_group'></label>
            </div>
            <label class='arbor-simple-full'><span>Notes</span><textarea class='uk-textarea' rows='2' name='match_notes' placeholder='Testing company, side, evidence, contact notes, or doubts.'></textarea></label>
            <button class='uk-button uk-button-primary' type='submit' name='add_dna_match' value='1'><span uk-icon='icon: plus'></span> Add DNA match</button>
        </form>";
        $matchOptions = '<option value="">Choose match</option>';
        foreach ($allMatchesByKit as $kitMatches) {
            foreach ($kitMatches as $m) {
                $label = ($m['kit_b_name'] ?: 'Match #' . (int) $m['id']) . (((float) $m['total_cm']) ? ' · ' . (float) $m['total_cm'] . ' cM' : '');
                $matchOptions .= sprintf('<option value="%d">%s</option>', (int) $m['id'], htmlspecialchars($label));
            }
        }
        $importForm = "<form class='arbor-log-form' method='post' enctype='multipart/form-data'>
            $csrf
            <div class='arbor-simple-grid'>
                <label><span>Kit</span><select class='uk-select' name='kit_id' required>$kitOptions</select></label>
                <label><span>CSV file</span><input class='uk-input' type='file' name='dna_csv_file' accept='.csv,text/csv' required></label>
            </div>
            <p class='uk-text-meta uk-margin-remove'>Accepted columns include: match name, total cm, longest segment, relationship, chromosome, start, end, cm, snps, side.</p>
            <button class='uk-button uk-button-default' type='submit' name='import_dna_csv' value='1'><span uk-icon='icon: upload'></span> Import CSV</button>
        </form>";
        $segmentForm = "<form class='arbor-log-form' method='post'>
            $csrf
            <div class='arbor-simple-grid'>
                <label><span>Match</span><select class='uk-select' name='match_id' required>$matchOptions</select></label>
                <label><span>Chromosome</span><input class='uk-input' type='number' min='1' max='99' name='chromosome' required></label>
                <label><span>Start position</span><input class='uk-input' type='number' min='0' name='start_pos' required></label>
                <label><span>End position</span><input class='uk-input' type='number' min='0' name='end_pos' required></label>
                <label><span>cM</span><input class='uk-input' type='number' step='0.01' min='0' name='centimorgans'></label>
                <label><span>SNP count</span><input class='uk-input' type='number' min='0' name='snp_count'></label>
                <label><span>Side</span><select class='uk-select' name='side'><option value='unknown'>Unknown</option><option value='maternal'>Maternal</option><option value='paternal'>Paternal</option></select></label>
            </div>
            <button class='uk-button uk-button-primary' type='submit' name='add_dna_segment' value='1'><span uk-icon='icon: plus'></span> Add segment</button>
        </form>";
        $emptyTitle = $filter === 'no_segments' ? 'No DNA matches in this check' : 'No DNA kits yet';
        $emptyText = $filter === 'no_segments'
            ? 'Every recorded DNA match has at least one segment.'
            : 'Add test kits from services such as FTDNA, 23andMe, AncestryDNA, MyHeritage, LivingDNA or GEDmatch. Kits are linked to people in this tree.';
        $body = $rows
            ? "<div class='arbor-list'>$rows</div>"
            : "<div class='arbor-empty'>
                 <span uk-icon='icon: bolt; ratio: 3'></span>
                 <h4>$emptyTitle</h4>
                 <p>$emptyText</p>
               </div>";
        return "<div class='pw-wrap Arbor'>
            <p class='uk-text-muted'>DNA test kits linked to people in $name. Keep genetic evidence separate from ordinary records, and record consent or data source in notes.</p>
            <div class='arbor-toolbar'>
                <a class='uk-button uk-button-default' href='$treeUrl'><span uk-icon='icon: tree'></span> Tree overview</a>
                <a class='uk-button uk-button-default' href='$viewerUrl'><span uk-icon='icon: image'></span> Tree viewer</a>
            </div>
            $filterNote
            <span class='arbor-section-label'>DNA kits</span>
            $form
            <span class='arbor-section-label'>DNA matches</span>
            $matchForm
            <span class='arbor-section-label'>Import matches</span>
            $importForm
            <span class='arbor-section-label'>DNA segments</span>
            $segmentForm
            $body
        </div>";
    }

    protected function dnaMatchesByKit(int $treeId): array
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT m.*, COUNT(s.id) AS segment_count
             FROM arbor_dna_matches m
             JOIN arbor_dna_kits k ON k.id = m.kit_a_id
             LEFT JOIN arbor_dna_segments s ON s.match_id = m.id
             WHERE k.tree_id = :t
             GROUP BY m.id
             ORDER BY m.total_cm DESC, m.kit_b_name"
        );
        $stmt->execute([':t' => $treeId]);
        $byKit = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byKit[(int) $row['kit_a_id']][] = $row;
        }
        return $byKit;
    }

    protected function dnaSegmentsByMatch(int $treeId): array
    {
        $stmt = $this->wire('database')->prepare(
            "SELECT s.*
             FROM arbor_dna_segments s
             JOIN arbor_dna_matches m ON m.id = s.match_id
             JOIN arbor_dna_kits k ON k.id = m.kit_a_id
             WHERE k.tree_id = :t
             ORDER BY s.chromosome, s.start_pos"
        );
        $stmt->execute([':t' => $treeId]);
        $byMatch = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byMatch[(int) $row['match_id']][] = $row;
        }
        return $byMatch;
    }

    protected function addDnaKit(int $treeId): void
    {
        $input = $this->wire('input');
        $personId = (int) $input->post('person_id');
        if (!$personId) throw new WireException('Choose a person for this DNA kit');
        $this->requireRecordInTree('persons', $personId, $treeId);
        $company = (string) $input->post('company');
        if (!in_array($company, ['ftdna','23andme','ancestrydna','myheritage','livingdna','gedmatch','other'], true)) $company = 'other';
        $testType = (string) $input->post('test_type');
        if (!in_array($testType, ['autosomal','y_dna','mt_dna','big_y','y37','y67','y111','y700','other'], true)) $testType = 'autosomal';
        $this->arbor->model('dna')->saveKit([
            'person_id' => $personId,
            'tree_id' => $treeId,
            'company' => $company,
            'company_other' => $input->post->text('company_other') ?? '',
            'kit_id' => $input->post->text('kit_id') ?? '',
            'test_type' => $testType,
            'test_date' => $input->post->text('test_date') ?: null,
            'y_haplogroup' => $input->post->text('y_haplogroup') ?? '',
            'mt_haplogroup' => $input->post->text('mt_haplogroup') ?? '',
            'notes' => $input->post->textarea('notes') ?? '',
        ]);
        $this->message('DNA kit added');
    }

    protected function addDnaMatch(int $treeId): void
    {
        $input = $this->wire('input');
        $kitId = (int) $input->post('kit_a_id');
        if (!$kitId) throw new WireException('Choose a DNA kit for this match');
        $this->requireRecordInTree('dna_kits', $kitId, $treeId);
        $ancestorId = (int) $input->post('common_ancestor_id') ?: null;
        if ($ancestorId) $this->requireRecordInTree('persons', $ancestorId, $treeId);
        $matchName = trim((string) $input->post->text('kit_b_name'));
        if ($matchName === '') throw new WireException('Match name is required');
        $this->arbor->model('dna')->saveMatch([
            'kit_a_id' => $kitId,
            'kit_b_name' => $matchName,
            'total_cm' => (float) $input->post('total_cm'),
            'longest_segment_cm' => (float) $input->post('longest_segment_cm'),
            'predicted_relation' => $input->post->text('predicted_relation') ?? '',
            'common_ancestor_id' => $ancestorId,
            'triangulation_group' => $input->post->text('triangulation_group') ?? '',
            'notes' => $input->post->textarea('match_notes') ?? '',
        ]);
        $this->message('DNA match added');
    }

    protected function addDnaSegment(int $treeId): void
    {
        $input = $this->wire('input');
        $matchId = (int) $input->post('match_id');
        if (!$matchId) throw new WireException('Choose a DNA match for this segment');
        $this->requireRecordInTree('dna_matches', $matchId, $treeId);
        $chromosome = max(1, (int) $input->post('chromosome'));
        $start = max(0, (int) $input->post('start_pos'));
        $end = max(0, (int) $input->post('end_pos'));
        if ($end < $start) [$start, $end] = [$end, $start];
        $side = (string) $input->post('side');
        if (!in_array($side, ['maternal', 'paternal', 'unknown'], true)) $side = 'unknown';
        $this->arbor->model('dna')->saveSegment([
            'match_id' => $matchId,
            'chromosome' => $chromosome,
            'start_pos' => $start,
            'end_pos' => $end,
            'centimorgans' => (float) $input->post('centimorgans'),
            'snp_count' => (int) $input->post('snp_count'),
            'side' => $side,
        ]);
        $this->message('DNA segment added');
    }

    protected function updateDnaKit(int $treeId): void
    {
        $input = $this->wire('input');
        $kitId = (int) $input->post('kit_id');
        if (!$kitId) throw new WireException('Missing DNA kit');
        $this->requireRecordInTree('dna_kits', $kitId, $treeId);
        $kit = $this->arbor->model('dna')->getKit($kitId);
        if (!$kit) throw new Wire404Exception('DNA kit not found');
        $personId = (int) $input->post('person_id');
        if (!$personId) throw new WireException('Choose a person for this DNA kit');
        $this->requireRecordInTree('persons', $personId, $treeId);
        $company = (string) $input->post('company');
        if (!in_array($company, ['ftdna','23andme','ancestrydna','myheritage','livingdna','gedmatch','other'], true)) $company = 'other';
        $testType = (string) $input->post('test_type');
        if (!in_array($testType, ['autosomal','y_dna','mt_dna','big_y','y37','y67','y111','y700','other'], true)) $testType = 'autosomal';
        $kit['person_id'] = $personId;
        $kit['company'] = $company;
        $kit['company_other'] = $input->post->text('company_other') ?? '';
        $kit['kit_id'] = $input->post->text('kit_code') ?? '';
        $kit['test_type'] = $testType;
        $kit['test_date'] = $input->post->text('test_date') ?: null;
        $kit['y_haplogroup'] = $input->post->text('y_haplogroup') ?? '';
        $kit['mt_haplogroup'] = $input->post->text('mt_haplogroup') ?? '';
        $kit['notes'] = $input->post->textarea('notes') ?? '';
        $this->arbor->model('dna')->saveKit($kit, $kitId);
        $this->message('DNA kit updated');
    }

    protected function updateDnaMatch(int $treeId): void
    {
        $input = $this->wire('input');
        $matchId = (int) $input->post('match_id');
        if (!$matchId) throw new WireException('Missing DNA match');
        $this->requireRecordInTree('dna_matches', $matchId, $treeId);
        $match = $this->arbor->model('dna')->getMatch($matchId);
        if (!$match) throw new Wire404Exception('DNA match not found');
        $matchName = trim((string) $input->post->text('kit_b_name'));
        if ($matchName === '') throw new WireException('Match name is required');
        $match['kit_b_name'] = $matchName;
        $match['total_cm'] = (float) $input->post('total_cm');
        $match['longest_segment_cm'] = (float) $input->post('longest_segment_cm');
        $match['predicted_relation'] = $input->post->text('predicted_relation') ?? '';
        $match['triangulation_group'] = $input->post->text('triangulation_group') ?? '';
        $match['notes'] = $input->post->textarea('match_notes') ?? '';
        $this->arbor->model('dna')->saveMatch($match, $matchId);
        $this->message('DNA match updated');
    }

    protected function updateDnaSegment(int $treeId): void
    {
        $input = $this->wire('input');
        $segmentId = (int) $input->post('segment_id');
        if (!$segmentId) throw new WireException('Missing DNA segment');
        $this->requireRecordInTree('dna_segments', $segmentId, $treeId);
        $segment = $this->arbor->model('dna')->getSegment($segmentId);
        if (!$segment) throw new Wire404Exception('DNA segment not found');
        $chromosome = max(1, (int) $input->post('chromosome'));
        $start = max(0, (int) $input->post('start_pos'));
        $end = max(0, (int) $input->post('end_pos'));
        if ($end < $start) [$start, $end] = [$end, $start];
        $side = (string) $input->post('side');
        if (!in_array($side, ['maternal', 'paternal', 'unknown'], true)) $side = 'unknown';
        $segment['chromosome'] = $chromosome;
        $segment['start_pos'] = $start;
        $segment['end_pos'] = $end;
        $segment['centimorgans'] = (float) $input->post('centimorgans');
        $segment['snp_count'] = (int) $input->post('snp_count');
        $segment['side'] = $side;
        $this->arbor->model('dna')->saveSegment($segment, $segmentId);
        $this->message('DNA segment updated');
    }

    protected function importDnaCsv(int $treeId): void
    {
        $kitId = (int) $this->wire('input')->post('kit_id');
        if (!$kitId) throw new WireException('Choose a DNA kit for this import');
        $this->requireRecordInTree('dna_kits', $kitId, $treeId);
        if (empty($_FILES['dna_csv_file']['name'])) throw new WireException('Choose a CSV file');
        $file = $_FILES['dna_csv_file'];
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new WireException('DNA CSV upload failed');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > (int) $this->arbor->maxDocSize * 1024) {
            throw new WireException('DNA CSV file is empty or too large');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') throw new WireException('DNA import accepts CSV files only');
        $imported = $this->arbor->model('dna')->importCsv($kitId, (string) $file['tmp_name']);
        $this->message("DNA CSV imported: $imported rows");
    }

    protected function deleteDnaKit(int $treeId): void
    {
        $kitId = (int) $this->wire('input')->post('kit_id');
        if (!$kitId) throw new WireException('Missing DNA kit');
        $this->requireRecordInTree('dna_kits', $kitId, $treeId);
        $this->arbor->model('dna')->deleteKit($kitId);
        $this->message('DNA kit deleted');
    }

    protected function deleteDnaMatch(int $treeId): void
    {
        $matchId = (int) $this->wire('input')->post('match_id');
        if (!$matchId) throw new WireException('Missing DNA match');
        $this->requireRecordInTree('dna_matches', $matchId, $treeId);
        $this->arbor->model('dna')->deleteMatch($matchId);
        $this->message('DNA match deleted');
    }

    protected function deleteDnaSegment(int $treeId): void
    {
        $segmentId = (int) $this->wire('input')->post('segment_id');
        if (!$segmentId) throw new WireException('Missing DNA segment');
        $this->requireRecordInTree('dna_segments', $segmentId, $treeId);
        $this->arbor->model('dna')->deleteSegment($segmentId);
        $this->message('DNA segment deleted');
    }

    protected function documentsList(array $tree): string
    {
        $treeId = (int) $tree['id'];
        $filter = (string) $this->wire('input')->get('filter');
        if (!in_array($filter, ['missing_file', 'no_evidence', 'leads', 'found', 'attached', 'dismissed'], true)) $filter = '';
        $selectedPersonId = (int) $this->wire('input')->get('person');
        if ($selectedPersonId) $this->requireRecordInTree('persons', $selectedPersonId, $treeId);
        $db = $this->wire('database');
        $where = "WHERE d.tree_id = :t";
        $bind = [':t' => $treeId];
        if ($filter === 'missing_file') {
            $where .= " AND d.status NOT IN ('lead','dismissed') AND d.filename = '' AND d.external_url = ''";
        }
        if ($filter === 'leads') {
            $where .= " AND d.status = 'lead'";
        }
        if (in_array($filter, ['found', 'attached', 'dismissed'], true)) {
            $where .= " AND d.status = :status";
            $bind[':status'] = $filter;
        }
        if ($filter === 'no_evidence') {
            $where .= " AND NOT EXISTS (
                SELECT 1
                FROM arbor_citations c
                JOIN arbor_sources s ON s.id = c.source_id
                WHERE c.document_id = d.id
                  AND s.tree_id = d.tree_id
            )";
        }
        if ($selectedPersonId) {
            $where .= " AND d.person_id = :p";
            $bind[':p'] = $selectedPersonId;
        }
        $stmt = $db->prepare(
            "SELECT d.*, n.given, n.patronymic, n.surname, r.name AS repo_name
             FROM arbor_documents d
             LEFT JOIN arbor_names n ON n.person_id = d.person_id AND n.name_type = 'BIRTH'
             LEFT JOIN arbor_repositories r ON r.id = d.repo_id
             $where
             ORDER BY d.doc_date DESC, d.id DESC"
        );
        $stmt->execute($bind);
        $documents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $docCounts = $this->documentDashboardCounts($treeId);
        $persons = $this->arbor->model('persons')->findByTree($treeId, ['limit' => 500]);
        $repos = $this->arbor->model('repositories')->forTree($treeId);
        $places = $this->arbor->model('places')->allForTree($treeId);
        $sources = $this->arbor->model('sources')->forTree($treeId);
        $eventStmt = $db->prepare(
            "SELECT e.id, e.person_id, e.event_type, e.title, e.event_date, e.event_place_str
             FROM arbor_events e
             WHERE e.tree_id = :t AND e.person_id IS NOT NULL
             ORDER BY COALESCE(e.event_date_sort, e.event_date) IS NULL, COALESCE(e.event_date_sort, e.event_date), e.sort, e.id"
        );
        $eventStmt->execute([':t' => $treeId]);
        $eventsByPerson = [];
        foreach ($eventStmt->fetchAll(\PDO::FETCH_ASSOC) as $event) {
            $eventsByPerson[(int) $event['person_id']][] = $event;
        }
        $documentCitationCounts = [];
        $citationStmt = $db->prepare(
            "SELECT c.document_id, COUNT(*) AS citation_count
             FROM arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             WHERE s.tree_id = :t AND c.document_id IS NOT NULL
             GROUP BY c.document_id"
        );
        $citationStmt->execute([':t' => $treeId]);
        foreach ($citationStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $documentCitationCounts[(int) $row['document_id']] = (int) $row['citation_count'];
        }
        $documentCitationsByDocument = [];
        $citationRowsStmt = $db->prepare(
            "SELECT c.id, c.source_id, c.document_id, c.event_id, c.page_ref, c.notes,
                    s.title AS source_title,
                    e.event_type, e.title AS event_title, e.event_date, e.event_place_str
             FROM arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             LEFT JOIN arbor_events e ON e.id = c.event_id
             WHERE s.tree_id = :t AND c.document_id IS NOT NULL
             ORDER BY c.id"
        );
        $citationRowsStmt->execute([':t' => $treeId]);
        foreach ($citationRowsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $documentCitationsByDocument[(int) $row['document_id']][] = $row;
        }

        $typeLabels = [
            'metrical_book' => 'Metrical book',
            'revision_list' => 'Revision list',
            'census' => 'Census',
            'military' => 'Military',
            'police' => 'Police',
            'immigration' => 'Immigration',
            'passport' => 'Passport',
            'birth_certificate' => 'Birth certificate',
            'death_certificate' => 'Death certificate',
            'marriage_certificate' => 'Marriage certificate',
            'ketubah' => 'Ketubah',
            'get' => 'Get',
            'pinkas' => 'Pinkas',
            'photo_document' => 'Photo document',
            'page_of_testimony' => 'Page of testimony',
            'transport_list' => 'Transport list',
            'camp_record' => 'Camp record',
            'notarial_deed' => 'Notarial deed',
            'court_file' => 'Court file',
            'voter_list' => 'Voter list',
            'draft_list' => 'Draft list',
            'tax_roll' => 'Tax roll',
            'business_directory' => 'Business directory',
            'tombstone_inscription' => 'Tombstone inscription',
            'other' => 'Other',
        ];
        $typeOptions = '';
        foreach ($typeLabels as $value => $label) $typeOptions .= "<option value='$value'>$label</option>";

        $personOptions = '<option value="">Choose person</option>';
        $filterPersonOptions = '<option value="">All people</option>';
        $selectedPersonName = '';
        foreach ($persons as $p) {
            $personName = trim((string) ($p['given'] ?? '') . ' ' . (string) ($p['patronymic'] ?? '') . ' ' . (string) ($p['surname'] ?? '')) ?: 'Person #' . (int) $p['id'];
            $personOptions .= sprintf('<option value="%d">%s</option>', (int) $p['id'], htmlspecialchars($personName));
            $selected = $selectedPersonId === (int) $p['id'] ? ' selected' : '';
            if ($selected) $selectedPersonName = $personName;
            $filterPersonOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $p['id'], $selected, htmlspecialchars($personName));
        }
        $repoOptions = '<option value="">Not set</option>';
        foreach ($repos as $r) $repoOptions .= sprintf('<option value="%d">%s</option>', (int) $r['id'], htmlspecialchars($r['name']));
        $sourceOptions = '<option value="">Choose source</option>';
        foreach ($sources as $s) $sourceOptions .= sprintf('<option value="%d">%s</option>', (int) $s['id'], htmlspecialchars($s['title']));
        $placeOptions = '<option value="">Not set</option>';
        foreach ($places as $p) $placeOptions .= sprintf('<option value="%d">%s</option>', (int) $p['id'], htmlspecialchars($p['canonical_name']));

        $csrf = $this->wire('session')->CSRF->renderInput();
        $rows = '';
        foreach ($documents as $d) {
            $personName = trim((string) ($d['given'] ?? '') . ' ' . (string) ($d['patronymic'] ?? '') . ' ' . (string) ($d['surname'] ?? '')) ?: 'Unlinked person';
            $personUrl = $this->url('person', ['id' => (int) $d['person_id']]);
            $personLink = "<a class='arbor-person-mini-link' href='$personUrl'><span uk-icon='icon: user'></span> " . htmlspecialchars($personName) . "</a>";
            $meta = array_filter([
                ucfirst((string) ($d['status'] ?? 'found')),
                $typeLabels[$d['doc_type']] ?? ucfirst(str_replace('_', ' ', (string) $d['doc_type'])),
                $d['doc_date'] ?: null,
                $d['repo_name'] ?: ($d['archive_name'] ?: null),
                $d['external_url'] ? 'Online' : null,
                !empty($d['is_private']) ? 'Private' : null,
            ]);
            $title = $d['title'] ?: implode(' · ', array_slice($meta, 0, 2));
            $fileUrl = $d['filename'] ? $this->arbor->uploadUrl((int) $d['tree_id'], (int) $d['person_id']) . $d['filename'] : '';
            $links = [];
            if ($d['external_url']) $links[] = sprintf('<a class="uk-link-muted" href="%s" target="_blank" rel="noopener">open URL</a>', htmlspecialchars($d['external_url']));
            if ($fileUrl) $links[] = sprintf('<a class="uk-link-muted" href="%s" target="_blank" rel="noopener">open file</a>', htmlspecialchars($fileUrl));
            $link = $links ? ' ' . implode(' · ', $links) : '';
            $citationCount = (int) ($documentCitationCounts[(int) $d['id']] ?? 0);
            $citationBadge = $citationCount ? " · $citationCount evidence link" . ($citationCount === 1 ? '' : 's') : '';
            $hasFileOrUrl = (bool) ($d['filename'] || $d['external_url']);
            $editTypeOptions = '';
            foreach ($typeLabels as $value => $label) {
                $selected = $value === (string) $d['doc_type'] ? ' selected' : '';
                $editTypeOptions .= "<option value='$value'$selected>$label</option>";
            }
            $statusLabels = [
                'lead' => 'Lead to find',
                'found' => 'Found record',
                'attached' => 'Attached to source',
                'dismissed' => 'Dismissed',
            ];
            $statusOptions = '';
            foreach ($statusLabels as $value => $label) {
                $selected = $value === (string) ($d['status'] ?? 'found') ? ' selected' : '';
                $statusOptions .= "<option value='$value'$selected>$label</option>";
            }
            $editRepoOptions = '<option value="">Not set</option>';
            foreach ($repos as $r) {
                $selected = (int) ($d['repo_id'] ?? 0) === (int) $r['id'] ? ' selected' : '';
                $editRepoOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $r['id'], $selected, htmlspecialchars($r['name']));
            }
            $editPlaceOptions = '<option value="">Not set</option>';
            foreach ($places as $p) {
                $selected = (int) ($d['doc_place_id'] ?? 0) === (int) $p['id'] ? ' selected' : '';
                $editPlaceOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $p['id'], $selected, htmlspecialchars($p['canonical_name']));
            }
            $edit = "<details class='arbor-inline-editor'>
                <summary>Edit details</summary>
                <form method='post' class='arbor-document-edit' enctype='multipart/form-data'>
                    $csrf
                    <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                    <label><span>Type</span><select class='uk-select uk-form-small' name='doc_type'>$editTypeOptions</select></label>
                    <label><span>Status</span><select class='uk-select uk-form-small' name='status'>$statusOptions</select></label>
                    <label><span>Title</span><input class='uk-input uk-form-small' type='text' name='doc_title' value='" . htmlspecialchars((string) ($d['title'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Date</span><input class='uk-input uk-form-small' type='date' name='doc_date' value='" . htmlspecialchars((string) ($d['doc_date'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Archive or website</span><select class='uk-select uk-form-small' name='repo_id'>$editRepoOptions</select></label>
                    <label><span>Place</span><select class='uk-select uk-form-small' name='doc_place_id'>$editPlaceOptions</select></label>
                    <label><span>Fond</span><input class='uk-input uk-form-small' type='text' name='fond' value='" . htmlspecialchars((string) ($d['fond'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Opis</span><input class='uk-input uk-form-small' type='text' name='opis' value='" . htmlspecialchars((string) ($d['opis'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Delo</span><input class='uk-input uk-form-small' type='text' name='delo' value='" . htmlspecialchars((string) ($d['delo'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Folio/page</span><input class='uk-input uk-form-small' type='text' name='list_folio' value='" . htmlspecialchars((string) ($d['list_folio'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>URL</span><input class='uk-input uk-form-small' type='url' name='external_url' value='" . htmlspecialchars((string) ($d['external_url'] ?? ''), ENT_QUOTES) . "'></label>
                    <label><span>Replace scan file</span><input class='uk-input uk-form-small' type='file' name='document_file' accept='.pdf,image/*'></label>
                    <label class='arbor-document-edit-full'><span>Description</span><textarea class='uk-textarea uk-form-small' rows='2' name='description'>" . htmlspecialchars((string) ($d['description'] ?? '')) . "</textarea></label>
                    <label><span>Private</span><label class='arbor-checkline'><input class='uk-checkbox' type='checkbox' name='is_private' value='1'" . (!empty($d['is_private']) ? ' checked' : '') . "> Hide from public API</label></label>
                    <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_document' value='1'><span uk-icon='icon: check'></span> Save</button>
                </form>
            </details>";
            $eventOptions = '<option value="">Person only</option>';
            $suggestedEventType = $this->documentSuggestedEventType((string) $d['doc_type']);
            foreach ($eventsByPerson[(int) $d['person_id']] ?? [] as $event) {
                $selected = $suggestedEventType && (string) $event['event_type'] === $suggestedEventType ? ' selected' : '';
                $label = trim(($event['title'] ?: ucfirst((string) $event['event_type'])) . ' ' . ($event['event_date'] ?: '') . ' ' . ($event['event_place_str'] ?: ''));
                $eventOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $event['id'], $selected, htmlspecialchars($label));
            }
            $citationSourceOptions = '<option value="">Choose source</option>';
            foreach ($sources as $s) {
                $selected = (int) ($d['repo_id'] ?? 0) && (int) ($s['repo_id'] ?? 0) === (int) $d['repo_id'] ? ' selected' : '';
                $citationSourceOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $s['id'], $selected, htmlspecialchars($s['title']));
            }
            $pageRef = htmlspecialchars($this->documentPageRef($d), ENT_QUOTES);
            $citation = $sources
                ? "<details class='arbor-inline-editor'>
                    <summary>Add evidence link</summary>
                    <form method='post' class='arbor-document-edit'>
                        $csrf
                        <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                        <label><span>Source</span><select class='uk-select uk-form-small' name='source_id' required>$citationSourceOptions</select></label>
                        <label><span>Fact</span><select class='uk-select uk-form-small' name='event_id'>$eventOptions</select></label>
                        <label><span>Page/reference</span><input class='uk-input uk-form-small' type='text' name='page_ref' value='$pageRef'></label>
                        <label class='arbor-document-edit-full'><span>Notes</span><textarea class='uk-textarea uk-form-small' rows='2' name='notes' placeholder='What this document proves.'></textarea></label>
                        <button class='uk-button uk-button-default uk-button-small' type='submit' name='add_document_citation' value='1'><span uk-icon='icon: link'></span> Link evidence</button>
                    </form>
                </details>"
                : "<div class='uk-text-meta'>Add a source first to link this document as evidence.</div>";
            $needsRealSource = false;
            foreach ($documentCitationsByDocument[(int) $d['id']] ?? [] as $c) {
                if (strpos((string) ($c['source_title'] ?? ''), 'Existing family data for ') === 0) {
                    $needsRealSource = true;
                    break;
                }
            }
            $sourceFromDocument = $needsRealSource
                && ($d['status'] ?? '') !== 'lead'
                && $hasFileOrUrl
                ? "<form method='post' class='arbor-inline-form arbor-document-promote'>
                    $csrf
                    <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                    <button class='uk-button uk-button-default uk-button-small' type='submit' name='create_document_source' value='1'><span uk-icon='icon: database'></span> Create source from document</button>
                </form>"
                : '';
            $linkedEvidence = '';
            if (!empty($documentCitationsByDocument[(int) $d['id']])) {
                $evidenceRows = '';
                foreach ($documentCitationsByDocument[(int) $d['id']] as $c) {
                    $fact = $c['event_id']
                        ? trim(($c['event_title'] ?: ucfirst((string) $c['event_type'])) . ' ' . ($c['event_date'] ?: '') . ' ' . ($c['event_place_str'] ?: ''))
                        : 'Person note';
                    $evidenceMeta = array_filter([$fact, $c['page_ref'] ?: null]);
                    $assignFact = '';
                    if (empty($c['event_id']) && !empty($eventsByPerson[(int) $d['person_id']])) {
                        $assignOptions = '<option value="">Choose fact</option>';
                        $suggestedEventType = $this->documentSuggestedEventType((string) $d['doc_type']);
                        foreach ($eventsByPerson[(int) $d['person_id']] as $event) {
                            $selected = $suggestedEventType && (string) $event['event_type'] === $suggestedEventType ? ' selected' : '';
                            $label = trim(($event['title'] ?: ucfirst((string) $event['event_type'])) . ' ' . ($event['event_date'] ?: '') . ' ' . ($event['event_place_str'] ?: ''));
                            $assignOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $event['id'], $selected, htmlspecialchars($label));
                        }
                        $assignFact = "<form method='post' class='arbor-evidence-assign'>
                            $csrf
                            <input type='hidden' name='citation_id' value='" . (int) $c['id'] . "'>
                            <select class='uk-select uk-form-small' name='event_id' required>$assignOptions</select>
                            <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_document_citation_event' value='1'><span uk-icon='icon: check'></span> Assign</button>
                        </form>";
                    }
                    $evidenceRows .= "<div class='arbor-evidence-chip'>
                        <span><strong>" . htmlspecialchars((string) $c['source_title']) . "</strong><br><em>" . htmlspecialchars(implode(' · ', $evidenceMeta)) . "</em></span>
                        <span class='arbor-evidence-actions'>
                            $assignFact
                            <form method='post' class='arbor-inline-form' onsubmit=\"return confirm('Remove this evidence link?')\">
                                $csrf
                                <input type='hidden' name='citation_id' value='" . (int) $c['id'] . "'>
                                <button class='uk-button uk-button-text uk-text-danger' type='submit' name='delete_document_citation' value='1' title='Remove evidence link'><span uk-icon='icon: trash'></span></button>
                            </form>
                        </span>
                    </div>";
                }
                $linkedEvidence = "<div class='arbor-evidence-list'>$evidenceRows</div>";
            }
            $nextStep = '';
            if (($d['status'] ?? '') === 'lead') {
                $nextStep = 'Paste the record URL to resolve it, or mark it found if you will upload a scan.';
            } elseif (($d['status'] ?? '') === 'found' && !$hasFileOrUrl) {
                $nextStep = 'Add a scan file or URL, then create a source from this document.';
            } elseif (($d['status'] ?? '') === 'found' && $hasFileOrUrl && $needsRealSource) {
                $nextStep = 'Create a source from this document.';
            } elseif (($d['status'] ?? '') === 'attached') {
                $nextStep = 'Attached to a source.';
            }
            $nextStepBlock = $nextStep
                ? "<div class='arbor-document-next-step'><span uk-icon='icon: arrow-right'></span> " . htmlspecialchars($nextStep) . "</div>"
                : '';
            $quickActions = '';
            if (($d['status'] ?? '') === 'lead') {
                $quickActions = "<span class='arbor-document-quick-actions'>
                    <form method='post' class='arbor-inline-form'>
                        $csrf
                        <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                        <input type='hidden' name='status' value='found'>
                        <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_document_status' value='1'><span uk-icon='icon: check'></span> Found</button>
                    </form>
                    <form method='post' class='arbor-inline-form' onsubmit=\"return confirm('Dismiss this lead?')\">
                        $csrf
                        <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                        <input type='hidden' name='status' value='dismissed'>
                        <button class='uk-button uk-button-text uk-text-muted' type='submit' name='update_document_status' value='1'>Dismiss</button>
                    </form>
                </span>";
            }
            $quickUrl = '';
            if (($d['status'] ?? '') === 'lead' && $needsRealSource) {
                $quickUrl = "<form method='post' class='arbor-document-url-form'>
                    $csrf
                    <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                    <input class='uk-input uk-form-small' type='url' name='external_url' placeholder='Paste record URL'>
                    <button class='uk-button uk-button-primary uk-button-small' type='submit' name='resolve_document_lead' value='1'><span uk-icon='icon: bolt'></span> Resolve lead</button>
                </form>";
            } elseif (($d['status'] ?? '') === 'found' && !$hasFileOrUrl) {
                $quickUrl = "<form method='post' class='arbor-document-url-form'>
                    $csrf
                    <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                    <input class='uk-input uk-form-small' type='url' name='external_url' placeholder='Paste record URL'>
                    <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_document_url' value='1'><span uk-icon='icon: link'></span> Add URL</button>
                </form>";
            }
            $delete = "<form method='post' class='arbor-inline-form arbor-row-action' onsubmit=\"return confirm('Delete this document?')\">
                $csrf
                <input type='hidden' name='document_id' value='" . (int) $d['id'] . "'>
                <button class='uk-button uk-button-text uk-text-danger' type='submit' name='delete_document' value='1' title='Delete document'>
                    <span uk-icon='icon: trash'></span>
                </button>
            </form>";
            $rows .= sprintf(
                '<div class="arbor-list-row"><span class="arbor-list-main">%s%s<br>%s%s</span><span class="arbor-list-meta">%s</span>%s</div>',
                htmlspecialchars($title),
                $link,
                $personLink,
                $nextStepBlock . $quickActions . $quickUrl . $linkedEvidence . $sourceFromDocument . $edit . $citation,
                htmlspecialchars(implode(' · ', $meta) . $citationBadge),
                $delete
            );
        }

        $treeUrl = $this->url('tree', ['id' => $treeId]);
        $viewerUrl = $this->url('viewer', ['tree' => $treeId]);
        $clearUrl = $this->url('documents', ['tree' => $treeId]);
        $personParam = $selectedPersonId ? ['person' => $selectedPersonId] : [];
        $filterTabs = [
            ['key' => '', 'label' => 'All', 'count' => $docCounts['all'], 'url' => $this->url('documents', ['tree' => $treeId] + $personParam)],
            ['key' => 'leads', 'label' => 'Leads', 'count' => $docCounts['leads'], 'url' => $this->url('documents', ['tree' => $treeId, 'filter' => 'leads'] + $personParam)],
            ['key' => 'found', 'label' => 'Found', 'count' => $docCounts['found'], 'url' => $this->url('documents', ['tree' => $treeId, 'filter' => 'found'] + $personParam)],
            ['key' => 'attached', 'label' => 'Attached', 'count' => $docCounts['attached'], 'url' => $this->url('documents', ['tree' => $treeId, 'filter' => 'attached'] + $personParam)],
            ['key' => 'missing_file', 'label' => 'Need file', 'count' => $docCounts['missing_file'], 'url' => $this->url('documents', ['tree' => $treeId, 'filter' => 'missing_file'] + $personParam)],
            ['key' => 'no_evidence', 'label' => 'No evidence', 'count' => $docCounts['no_evidence'], 'url' => $this->url('documents', ['tree' => $treeId, 'filter' => 'no_evidence'] + $personParam)],
            ['key' => 'dismissed', 'label' => 'Dismissed', 'count' => $docCounts['dismissed'], 'url' => $this->url('documents', ['tree' => $treeId, 'filter' => 'dismissed'] + $personParam)],
        ];
        $filterNav = "<nav class='arbor-filter-tabs'>";
        foreach ($filterTabs as $tab) {
            $active = $filter === $tab['key'] ? ' is-active' : '';
            $filterNav .= "<a class='arbor-filter-tab$active' href='" . htmlspecialchars($tab['url']) . "'><span>" . htmlspecialchars($tab['label']) . "</span><strong>" . (int) $tab['count'] . "</strong></a>";
        }
        $filterNav .= '</nav>';
        $filterForm = "<form class='arbor-search' method='get'>
            <input type='hidden' name='tree' value='$treeId'>
            " . ($filter ? "<input type='hidden' name='filter' value='" . htmlspecialchars($filter) . "'>" : '') . "
            <select class='uk-select uk-form-small' name='person'>$filterPersonOptions</select>
            <button class='uk-button uk-button-default' type='submit'><span uk-icon='icon: search'></span> Filter</button>
        </form>";
        $noteParts = [];
        if ($filter === 'missing_file') $noteParts[] = '<strong>Documents without file or URL</strong>';
        if ($filter === 'no_evidence') $noteParts[] = '<strong>Documents not linked as evidence</strong>';
        if ($filter === 'leads') $noteParts[] = '<strong>Document leads to resolve</strong>';
        if ($filter === 'found') $noteParts[] = '<strong>Found documents waiting for a source</strong>';
        if ($filter === 'attached') $noteParts[] = '<strong>Documents attached to sources</strong>';
        if ($filter === 'dismissed') $noteParts[] = '<strong>Dismissed document leads</strong>';
        if ($selectedPersonId) {
            $personNote = '<strong>' . htmlspecialchars($selectedPersonName ?: 'selected person') . '</strong>';
            $noteParts[] = $noteParts ? 'for ' . $personNote : 'Documents for ' . $personNote;
        }
        $filterNote = $noteParts
            ? "<div class='arbor-filter-note'>Showing: " . implode(' ', $noteParts) . " <a href='$clearUrl'>show all documents</a></div>"
            : '';
        $form = "<form class='arbor-log-form' method='post' enctype='multipart/form-data'>
            $csrf
            <div class='arbor-simple-grid'>
                <label><span>Person</span><select class='uk-select' name='person_id' required>$personOptions</select></label>
                <label><span>Document type</span><select class='uk-select' name='doc_type'>$typeOptions</select></label>
                <label><span>Status</span><select class='uk-select' name='status'>
                    <option value='found'>Found record</option>
                    <option value='lead'>Lead to find</option>
                    <option value='attached'>Attached to source</option>
                    <option value='dismissed'>Dismissed</option>
                </select></label>
                <label><span>Title</span><input class='uk-input' type='text' name='title' placeholder='Birth record, revision list entry, passport file...'></label>
                <label><span>Date</span><input class='uk-input' type='date' name='doc_date'></label>
                <label><span>Archive or website</span><select class='uk-select' name='repo_id'>$repoOptions</select></label>
                <label><span>Place</span><select class='uk-select' name='doc_place_id'>$placeOptions</select></label>
                <label><span>Fond</span><input class='uk-input' type='text' name='fond'></label>
                <label><span>Opis</span><input class='uk-input' type='text' name='opis'></label>
                <label><span>Delo</span><input class='uk-input' type='text' name='delo'></label>
                <label><span>Folio/page</span><input class='uk-input' type='text' name='list_folio'></label>
                <label><span>URL</span><input class='uk-input' type='url' name='external_url'></label>
                <label><span>Scan file</span><input class='uk-input' type='file' name='document_file' accept='.pdf,image/*'></label>
                <label><span>Private</span><label class='arbor-checkline'><input class='uk-checkbox' type='checkbox' name='is_private' value='1'> Hide from public API</label></label>
            </div>
            <label class='arbor-simple-full'><span>Description</span><textarea class='uk-textarea' rows='2' name='description' placeholder='What this document says and why it matters.'></textarea></label>
            <button class='uk-button uk-button-primary' type='submit' name='add_document' value='1'><span uk-icon='icon: plus'></span> Add document</button>
        </form>";

        $body = $rows
            ? "<div class='arbor-list'>$rows</div>"
            : "<div class='arbor-empty'>
                 <span uk-icon='icon: copy; ratio: 3'></span>
                 <h4>" . (($selectedPersonId || $filter) ? 'No documents in this view' : 'No documents yet') . "</h4>
                 <p>" . (($selectedPersonId || $filter) ? 'Try another person, clear the filters, or add a document.' : 'Add archival records, certificates, lists, and online document links that support people in this tree.') . "</p>
               </div>";

        return "<div class='pw-wrap Arbor'>
            <p class='uk-text-muted'>Documents for " . htmlspecialchars($tree['name']) . ": archive files, scans, online records, certificates, lists, and testimony pages linked to people.</p>
            <div class='arbor-toolbar'>
                <a class='uk-button uk-button-default' href='$treeUrl'><span uk-icon='icon: tree'></span> Tree overview</a>
                <a class='uk-button uk-button-default' href='$viewerUrl'><span uk-icon='icon: image'></span> Tree viewer</a>
            </div>
            $filterNav
            $filterForm
            $filterNote
            $form
            $body
        </div>";
    }

    protected function documentDashboardCounts(int $treeId): array
    {
        $db = $this->wire('database');
        $counts = [
            'all' => 0,
            'leads' => 0,
            'found' => 0,
            'attached' => 0,
            'dismissed' => 0,
            'missing_file' => 0,
            'no_evidence' => 0,
        ];
        $stmt = $db->prepare(
            "SELECT
                COUNT(*) AS all_count,
                SUM(status = 'lead') AS lead_count,
                SUM(status = 'found') AS found_count,
                SUM(status = 'attached') AS attached_count,
                SUM(status = 'dismissed') AS dismissed_count,
                SUM(status NOT IN ('lead','dismissed') AND filename = '' AND external_url = '') AS missing_file_count
             FROM arbor_documents
             WHERE tree_id = :t"
        );
        $stmt->execute([':t' => $treeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $counts['all'] = (int) ($row['all_count'] ?? 0);
        $counts['leads'] = (int) ($row['lead_count'] ?? 0);
        $counts['found'] = (int) ($row['found_count'] ?? 0);
        $counts['attached'] = (int) ($row['attached_count'] ?? 0);
        $counts['dismissed'] = (int) ($row['dismissed_count'] ?? 0);
        $counts['missing_file'] = (int) ($row['missing_file_count'] ?? 0);

        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM arbor_documents d
             WHERE d.tree_id = :t
               AND NOT EXISTS (
                   SELECT 1
                   FROM arbor_citations c
                   JOIN arbor_sources s ON s.id = c.source_id
                   WHERE c.document_id = d.id
                     AND s.tree_id = d.tree_id
               )"
        );
        $stmt->execute([':t' => $treeId]);
        $counts['no_evidence'] = (int) $stmt->fetchColumn();
        return $counts;
    }

    protected function addDocument(int $treeId): void
    {
        $input = $this->wire('input');
        $personId = (int) $input->post('person_id');
        if (!$personId) throw new WireException('Choose a person for this document');
        $this->requireRecordInTree('persons', $personId, $treeId);
        $repoId = (int) $input->post('repo_id') ?: null;
        if ($repoId) $this->requireRecordInTree('repositories', $repoId, $treeId);
        $placeId = (int) $input->post('doc_place_id') ?: null;
        if ($placeId) $this->requireRecordInTree('places', $placeId, $treeId);
        $docType = (string) $input->post('doc_type');
        $allowedTypes = ['metrical_book','revision_list','census','military','police','immigration','passport','birth_certificate','death_certificate','marriage_certificate','ketubah','get','pinkas','photo_document','page_of_testimony','transport_list','camp_record','notarial_deed','court_file','voter_list','draft_list','tax_roll','business_directory','tombstone_inscription','other'];
        if (!in_array($docType, $allowedTypes, true)) $docType = 'other';
        $status = $this->documentStatus((string) $input->post('status'));
        $filename = $this->handleDocumentUpload($treeId, $personId);
        $this->arbor->model('documents')->save([
            'person_id' => $personId,
            'tree_id' => $treeId,
            'doc_type' => $docType,
            'status' => $status,
            'title' => $input->post->text('title') ?? '',
            'repo_id' => $repoId,
            'fond' => $input->post->text('fond') ?? '',
            'opis' => $input->post->text('opis') ?? '',
            'delo' => $input->post->text('delo') ?? '',
            'list_folio' => $input->post->text('list_folio') ?? '',
            'doc_date' => $input->post->text('doc_date') ?: null,
            'doc_place_id' => $placeId,
            'filename' => $filename,
            'external_url' => $input->post->text('external_url') ?? '',
            'description' => $input->post->textarea('description') ?? '',
            'is_private' => (int) $input->post('is_private'),
        ]);
        $this->message('Document added');
    }

    protected function updateDocument(int $treeId): void
    {
        $input = $this->wire('input');
        $documentId = (int) $input->post('document_id');
        if (!$documentId) throw new WireException('Missing document');
        $this->requireRecordInTree('documents', $documentId, $treeId);
        $document = $this->arbor->model('documents')->get($documentId);
        if (!$document) throw new Wire404Exception('Document not found');
        $repoId = (int) $input->post('repo_id') ?: null;
        if ($repoId) $this->requireRecordInTree('repositories', $repoId, $treeId);
        $placeId = (int) $input->post('doc_place_id') ?: null;
        if ($placeId) $this->requireRecordInTree('places', $placeId, $treeId);
        $docType = (string) $input->post('doc_type');
        $allowedTypes = ['metrical_book','revision_list','census','military','police','immigration','passport','birth_certificate','death_certificate','marriage_certificate','ketubah','get','pinkas','photo_document','page_of_testimony','transport_list','camp_record','notarial_deed','court_file','voter_list','draft_list','tax_roll','business_directory','tombstone_inscription','other'];
        if (!in_array($docType, $allowedTypes, true)) $docType = 'other';
        $replacement = $this->handleDocumentUpload($treeId, (int) $document['person_id']);
        if ($replacement) {
            $oldFile = !empty($document['filename'])
                ? $this->arbor->uploadDir($treeId, (int) $document['person_id']) . basename((string) $document['filename'])
                : '';
            if ($oldFile && is_file($oldFile)) @unlink($oldFile);
            $document['filename'] = $replacement;
        }
        $document['doc_type'] = $docType;
        $document['status'] = $this->documentStatus((string) $input->post('status'));
        $document['title'] = $input->post->text('doc_title') ?? '';
        $document['repo_id'] = $repoId;
        $document['fond'] = $input->post->text('fond') ?? '';
        $document['opis'] = $input->post->text('opis') ?? '';
        $document['delo'] = $input->post->text('delo') ?? '';
        $document['list_folio'] = $input->post->text('list_folio') ?? '';
        $document['doc_date'] = $input->post->text('doc_date') ?: null;
        $document['doc_place_id'] = $placeId;
        $document['external_url'] = $input->post->text('external_url') ?? '';
        $document['description'] = $input->post->textarea('description') ?? '';
        $document['is_private'] = (int) $input->post('is_private');
        $this->arbor->model('documents')->save($document, $documentId);
        $this->message('Document updated');
    }

    protected function updateDocumentStatus(int $treeId): void
    {
        $input = $this->wire('input');
        $documentId = (int) $input->post('document_id');
        if (!$documentId) throw new WireException('Missing document');
        $this->requireRecordInTree('documents', $documentId, $treeId);
        $document = $this->arbor->model('documents')->get($documentId);
        if (!$document) throw new Wire404Exception('Document not found');
        $document['status'] = $this->documentStatus((string) $input->post('status'));
        $this->arbor->model('documents')->save($document, $documentId);
        $this->message('Document status updated');
    }

    protected function updateDocumentUrl(int $treeId): void
    {
        $input = $this->wire('input');
        $documentId = (int) $input->post('document_id');
        if (!$documentId) throw new WireException('Missing document');
        $this->requireRecordInTree('documents', $documentId, $treeId);
        $document = $this->arbor->model('documents')->get($documentId);
        if (!$document) throw new Wire404Exception('Document not found');
        $url = trim((string) $input->post->url('external_url'));
        if ($url === '') throw new WireException('Add a URL first');
        $document['external_url'] = $url;
        if (($document['status'] ?? '') === 'lead') $document['status'] = 'found';
        $this->arbor->model('documents')->save($document, $documentId);
        $this->message('Document URL added');
    }

    protected function resolveDocumentLeadWithUrl(int $treeId): void
    {
        $input = $this->wire('input');
        $documentId = (int) $input->post('document_id');
        if (!$documentId) throw new WireException('Missing document');
        $this->requireRecordInTree('documents', $documentId, $treeId);
        $document = $this->arbor->model('documents')->get($documentId);
        if (!$document) throw new Wire404Exception('Document not found');
        $url = trim((string) $input->post->url('external_url'));
        if ($url === '') throw new WireException('Paste the record URL first');

        $starter = $this->wire('database')->prepare(
            "SELECT COUNT(*)
             FROM arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             WHERE c.document_id = :document
               AND s.tree_id = :tree
               AND s.title LIKE 'Existing family data for %'"
        );
        $starter->execute([
            ':document' => $documentId,
            ':tree' => $treeId,
        ]);
        if (!(int) $starter->fetchColumn()) {
            throw new WireException('This document does not have a starter evidence link to resolve');
        }

        $document['external_url'] = $url;
        $document['status'] = 'found';
        $this->arbor->model('documents')->save($document, $documentId);
        $this->createSourceFromDocument($treeId);
        $this->message('Lead resolved');
    }

    protected function addDocumentCitation(int $treeId): void
    {
        $input = $this->wire('input');
        $documentId = (int) $input->post('document_id');
        $sourceId = (int) $input->post('source_id');
        if (!$documentId || !$sourceId) throw new WireException('Choose a document and a source');
        $this->requireRecordInTree('documents', $documentId, $treeId);
        $this->requireRecordInTree('sources', $sourceId, $treeId);
        $document = $this->arbor->model('documents')->get($documentId);
        if (!$document) throw new Wire404Exception('Document not found');
        $eventId = (int) $input->post('event_id') ?: null;
        if ($eventId) {
            $this->requireRecordInTree('events', $eventId, $treeId);
            $event = $this->arbor->model('events')->get($eventId);
            if (!$event || (int) ($event['person_id'] ?? 0) !== (int) $document['person_id']) {
                throw new WireException('This fact belongs to another person');
            }
        }
        $pageRef = $input->post->text('page_ref') ?: $this->documentPageRef($document);
        $notes = trim((string) $input->post->textarea('notes'));
        if ($notes === '') $notes = 'Linked from document: ' . ($document['title'] ?: 'Document #' . $documentId);
        $dup = $this->wire('database')->prepare(
            "SELECT id FROM arbor_citations
             WHERE source_id = :source
               AND person_id = :person
               AND document_id = :document
               AND " . ($eventId ? "event_id = :event" : "event_id IS NULL") . "
             LIMIT 1"
        );
        $params = [
            ':source' => $sourceId,
            ':person' => (int) $document['person_id'],
            ':document' => $documentId,
        ];
        if ($eventId) $params[':event'] = $eventId;
        $dup->execute($params);
        if ($dup->fetchColumn()) {
            $this->warning('This document is already linked to that fact');
            return;
        }
        $this->arbor->model('citations')->save([
            'source_id' => $sourceId,
            'person_id' => (int) $document['person_id'],
            'event_id' => $eventId,
            'document_id' => $documentId,
            'page_ref' => $pageRef,
            'quality' => 2,
            'notes' => $notes,
        ]);
        $this->message('Evidence link added');
    }

    protected function documentPageRef(array $document): string
    {
        $parts = [];
        if (!empty($document['fond'])) $parts[] = 'fond ' . $document['fond'];
        if (!empty($document['opis'])) $parts[] = 'opis ' . $document['opis'];
        if (!empty($document['delo'])) $parts[] = 'delo ' . $document['delo'];
        if (!empty($document['list_folio'])) $parts[] = 'folio/page ' . $document['list_folio'];
        return implode(', ', $parts);
    }

    protected function documentStatus(string $status): string
    {
        return in_array($status, ['lead','found','attached','dismissed'], true) ? $status : 'found';
    }

    protected function documentSuggestedEventType(string $docType): string
    {
        return match ($docType) {
            'birth_certificate' => 'birth',
            'death_certificate' => 'death',
            'marriage_certificate', 'ketubah', 'get' => 'marriage',
            'immigration', 'passport', 'transport_list' => 'immigration',
            'military', 'draft_list' => 'military',
            'tombstone_inscription' => 'burial',
            default => '',
        };
    }

    protected function documentSourceType(string $docType): string
    {
        return match ($docType) {
            'birth_certificate', 'death_certificate', 'marriage_certificate' => 'vital_record',
            'metrical_book' => 'metrical_book',
            'revision_list' => 'revision_list',
            'census' => 'census',
            'photo_document' => 'photograph',
            'oral_interview' => 'oral_interview',
            default => in_array($docType, ['military','immigration','passport','court_file','notarial_deed'], true) ? 'manuscript' : 'other',
        };
    }

    protected function documentSourceTitle(array $document, int $documentId): string
    {
        $title = trim((string) ($document['title'] ?? ''));
        if (preg_match('/^find\s+(.+)$/i', $title, $m)) {
            $title = trim((string) $m[1]);
        }
        if ($title === '') {
            $title = ucfirst(str_replace('_', ' ', (string) ($document['doc_type'] ?? 'document'))) . ' #' . $documentId;
        }
        return $title;
    }

    protected function createSourceFromDocument(int $treeId): void
    {
        $documentId = (int) $this->wire('input')->post('document_id');
        if (!$documentId) throw new WireException('Missing document');
        $this->requireRecordInTree('documents', $documentId, $treeId);
        $document = $this->arbor->model('documents')->get($documentId);
        if (!$document) throw new Wire404Exception('Document not found');

        $title = $this->documentSourceTitle($document, $documentId);
        $sourceId = $this->arbor->model('sources')->save([
            'tree_id' => $treeId,
            'repo_id' => !empty($document['repo_id']) ? (int) $document['repo_id'] : null,
            'title' => $title,
            'source_type' => $this->documentSourceType((string) $document['doc_type']),
            'media_type' => (!empty($document['filename']) || !empty($document['external_url'])) ? 'ELECTRONIC' : 'MANUSCRIPT',
            'url' => (string) ($document['external_url'] ?? ''),
            'digital_url' => (string) ($document['external_url'] ?? ''),
            'archive_name' => (string) ($document['archive_name'] ?? ''),
            'fond' => (string) ($document['fond'] ?? ''),
            'opis' => (string) ($document['opis'] ?? ''),
            'delo' => (string) ($document['delo'] ?? ''),
            'abstract' => (string) ($document['description'] ?? ''),
            'notes' => 'Created from Arbor document #' . $documentId,
        ]);

        $update = $this->wire('database')->prepare(
            "UPDATE arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             SET c.source_id = :new_source
             WHERE c.document_id = :document
               AND s.tree_id = :tree
               AND s.title LIKE 'Existing family data for %'"
        );
        $update->execute([
            ':new_source' => $sourceId,
            ':document' => $documentId,
            ':tree' => $treeId,
        ]);
        $document['status'] = 'attached';
        $document['title'] = $title;
        $this->arbor->model('documents')->save($document, $documentId);
        $cleanup = $this->wire('database')->prepare(
            "DELETE starter
             FROM arbor_citations starter
             JOIN arbor_sources starter_source ON starter_source.id = starter.source_id
             JOIN arbor_citations real_citation ON real_citation.event_id = starter.event_id
             WHERE starter_source.tree_id = :tree
               AND starter_source.title LIKE 'Existing family data for %'
               AND real_citation.source_id = :new_source
               AND real_citation.document_id = :document
               AND starter.document_id IS NULL
               AND starter.event_id IS NOT NULL"
        );
        $cleanup->execute([
            ':tree' => $treeId,
            ':new_source' => $sourceId,
            ':document' => $documentId,
        ]);
        $this->deleteEmptyStarterSources($treeId);
        $this->message('Source created from document');
    }

    protected function deleteEmptyStarterSources(int $treeId): void
    {
        $delete = $this->wire('database')->prepare(
            "DELETE s
             FROM arbor_sources s
             LEFT JOIN arbor_citations c ON c.source_id = s.id
             WHERE s.tree_id = :tree
               AND s.title LIKE 'Existing family data for %'
               AND c.id IS NULL"
        );
        $delete->execute([':tree' => $treeId]);
        $this->closeStarterSourceTasks($treeId);
    }

    protected function closeStarterSourceTasks(int $treeId): void
    {
        $remaining = $this->wire('database')->prepare(
            "SELECT COUNT(*)
             FROM arbor_citations c
             JOIN arbor_sources s ON s.id = c.source_id
             WHERE s.tree_id = :tree
               AND s.title LIKE 'Existing family data for %'"
        );
        $remaining->execute([':tree' => $treeId]);
        if ((int) $remaining->fetchColumn()) return;

        $done = $this->wire('database')->prepare(
            "UPDATE arbor_tasks
             SET status = 'done'
             WHERE tree_id = :tree
               AND task_type = 'source_review'
               AND title = 'Replace starter source with original records'
               AND status <> 'done'"
        );
        $done->execute([':tree' => $treeId]);
        $this->closeOriginalSourcesQuestion($treeId);
    }

    protected function closeOriginalSourcesQuestion(int $treeId): void
    {
        $unsupportedBirths = $this->wire('database')->prepare(
            "SELECT COUNT(*)
             FROM arbor_events e
             WHERE e.tree_id = :tree
               AND e.event_type = 'birth'
               AND NOT EXISTS (
                   SELECT 1
                   FROM arbor_citations c
                   JOIN arbor_sources s ON s.id = c.source_id
                   WHERE s.tree_id = e.tree_id
                     AND c.event_id = e.id
               )"
        );
        $unsupportedBirths->execute([':tree' => $treeId]);
        if ((int) $unsupportedBirths->fetchColumn()) return;

        $closeQuestion = $this->wire('database')->prepare(
            "UPDATE arbor_research_questions
             SET status = 'answered', closed_date = :closed
             WHERE tree_id = :tree
               AND status = 'open'
               AND question = 'Which facts are supported by original sources?'"
        );
        $closeQuestion->execute([
            ':closed' => date('Y-m-d'),
            ':tree' => $treeId,
        ]);
    }

    protected function updateDocumentCitationEvent(int $treeId): void
    {
        $input = $this->wire('input');
        $citationId = (int) $input->post('citation_id');
        $eventId = (int) $input->post('event_id');
        if (!$citationId || !$eventId) throw new WireException('Choose an evidence link and a fact');
        $this->requireRecordInTree('citations', $citationId, $treeId);
        $this->requireRecordInTree('events', $eventId, $treeId);
        $citation = $this->arbor->model('citations')->get($citationId);
        $event = $this->arbor->model('events')->get($eventId);
        if (!$citation || !$event) throw new Wire404Exception('Evidence link not found');
        if ((int) ($citation['person_id'] ?? 0) !== (int) ($event['person_id'] ?? 0)) {
            throw new WireException('This fact belongs to another person');
        }
        $dup = $this->wire('database')->prepare(
            "SELECT id FROM arbor_citations
             WHERE source_id = :source
               AND person_id = :person
               AND document_id <=> :document
               AND event_id = :event
               AND id != :id
             LIMIT 1"
        );
        $documentId = !empty($citation['document_id']) ? (int) $citation['document_id'] : null;
        $dup->bindValue(':source', (int) $citation['source_id'], \PDO::PARAM_INT);
        $dup->bindValue(':person', (int) $citation['person_id'], \PDO::PARAM_INT);
        $dup->bindValue(':document', $documentId, $documentId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $dup->bindValue(':event', $eventId, \PDO::PARAM_INT);
        $dup->bindValue(':id', $citationId, \PDO::PARAM_INT);
        $dup->execute();
        if ($dup->fetchColumn()) {
            $this->warning('This document is already linked to that fact');
            return;
        }
        $citation['event_id'] = $eventId;
        $this->arbor->model('citations')->save($citation, $citationId);
        $this->message('Evidence link assigned to fact');
    }

    protected function deleteDocumentCitation(int $treeId): void
    {
        $citationId = (int) $this->wire('input')->post('citation_id');
        if (!$citationId) throw new WireException('Missing evidence link');
        $this->requireRecordInTree('citations', $citationId, $treeId);
        $this->arbor->model('citations')->delete($citationId);
        $this->message('Evidence link removed');
    }

    protected function deleteDocument(int $treeId): void
    {
        $documentId = (int) $this->wire('input')->post('document_id');
        if (!$documentId) throw new WireException('Missing document');
        $this->requireRecordInTree('documents', $documentId, $treeId);
        $this->arbor->model('documents')->delete($documentId);
        $this->message('Document deleted');
    }

    protected function photosList(array $tree): string
    {
        $treeId = (int) $tree['id'];
        $selectedPersonId = (int) $this->wire('input')->get('person');
        if ($selectedPersonId) $this->requireRecordInTree('persons', $selectedPersonId, $treeId);
        $where = "WHERE ph.tree_id = :t";
        $bind = [':t' => $treeId];
        if ($selectedPersonId) {
            $where .= " AND ph.person_id = :p";
            $bind[':p'] = $selectedPersonId;
        }
        $stmt = $this->wire('database')->prepare(
            "SELECT ph.*, n.given, n.patronymic, n.surname
             FROM arbor_photos ph
             LEFT JOIN arbor_names n ON n.person_id = ph.person_id AND n.name_type = 'BIRTH'
             $where
             ORDER BY ph.created DESC, ph.id DESC"
        );
        $stmt->execute($bind);
        $photos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $persons = $this->arbor->model('persons')->findByTree($treeId, ['limit' => 500]);
        $csrf = $this->wire('session')->CSRF->renderInput();

        $rows = '';
        foreach ($photos as $ph) {
            $personName = trim((string) ($ph['given'] ?? '') . ' ' . (string) ($ph['patronymic'] ?? '') . ' ' . (string) ($ph['surname'] ?? '')) ?: 'Person #' . (int) $ph['person_id'];
            $personUrl = $this->url('person', ['id' => (int) $ph['person_id']]);
            $title = $ph['title'] ?: $personName;
            $url = htmlspecialchars($this->arbor->model('photos')->url($ph));
            $meta = array_filter([
                $personName,
                $ph['year'] ?: null,
                !empty($ph['is_profile']) ? 'Profile photo' : null,
            ]);
            $delete = "<form method='post' class='arbor-inline-form arbor-photo-action' onsubmit=\"return confirm('Delete this photo?')\">
                $csrf
                <input type='hidden' name='photo_id' value='" . (int) $ph['id'] . "'>
                <button class='uk-button uk-button-text uk-text-danger' type='submit' name='delete_photo' value='1' title='Delete photo'>
                    <span uk-icon='icon: trash'></span>
                </button>
            </form>";
            $profileAction = !empty($ph['is_profile'])
                ? "<span class='arbor-photo-badge'><span uk-icon='icon: star'></span> Main photo</span>"
                : "<form method='post' class='arbor-inline-form arbor-photo-action'>
                    $csrf
                    <input type='hidden' name='photo_id' value='" . (int) $ph['id'] . "'>
                    <button class='uk-button uk-button-text' type='submit' name='set_profile_photo' value='1' title='Use as main photo'>
                        <span uk-icon='icon: star'></span> Use as main
                    </button>
                </form>";
            $editForm = "<form method='post' class='arbor-photo-edit'>
                $csrf
                <input type='hidden' name='photo_id' value='" . (int) $ph['id'] . "'>
                <label><span>Title</span><input class='uk-input uk-form-small' type='text' name='photo_title' value='" . htmlspecialchars((string) ($ph['title'] ?? ''), ENT_QUOTES) . "'></label>
                <label><span>Year</span><input class='uk-input uk-form-small' type='number' min='0' max='2100' name='photo_year' value='" . htmlspecialchars((string) ($ph['year'] ?? ''), ENT_QUOTES) . "'></label>
                <label class='arbor-photo-edit-full'><span>Description</span><textarea class='uk-textarea uk-form-small' rows='2' name='photo_description'>" . htmlspecialchars((string) ($ph['description'] ?? '')) . "</textarea></label>
                <button class='uk-button uk-button-default uk-button-small' type='submit' name='update_photo' value='1'><span uk-icon='icon: check'></span> Save</button>
            </form>";
            $rows .= sprintf(
                '<div class="arbor-photo-card"><a href="%s" target="_blank" rel="noopener"><img src="%s" alt=""></a><span>%s</span><a class="arbor-person-mini-link" href="%s"><span uk-icon="icon: user"></span> %s</a><small>%s</small>%s<div class="arbor-photo-actions">%s%s</div></div>',
                $url,
                $url,
                htmlspecialchars($title),
                $personUrl,
                htmlspecialchars($personName),
                htmlspecialchars(implode(' · ', $meta)),
                $editForm,
                $profileAction,
                $delete
            );
        }

        $treeUrl = $this->url('tree', ['id' => $treeId]);
        $viewerUrl = $this->url('viewer', ['tree' => $treeId]);
        $peopleUrl = $this->url('persons', ['tree' => $treeId]);
        $photosUrl = $this->url('photos', ['tree' => $treeId]);
        $personOptions = '<option value="">Choose person</option>';
        $filterPersonOptions = '<option value="">All people</option>';
        $selectedPersonName = '';
        foreach ($persons as $p) {
            $personName = trim((string) ($p['given'] ?? '') . ' ' . (string) ($p['patronymic'] ?? '') . ' ' . (string) ($p['surname'] ?? '')) ?: 'Person #' . (int) $p['id'];
            $personOptions .= sprintf('<option value="%d">%s</option>', (int) $p['id'], htmlspecialchars($personName));
            $selected = $selectedPersonId === (int) $p['id'] ? ' selected' : '';
            if ($selected) $selectedPersonName = $personName;
            $filterPersonOptions .= sprintf('<option value="%d"%s>%s</option>', (int) $p['id'], $selected, htmlspecialchars($personName));
        }
        $filterNote = $selectedPersonId
            ? "<div class='arbor-filter-note'>Showing photos for <strong>" . htmlspecialchars($selectedPersonName ?: 'selected person') . "</strong> <a href='$photosUrl'>show all photos</a></div>"
            : '';
        $filterForm = "<form class='arbor-search' method='get'>
            <input type='hidden' name='tree' value='$treeId'>
            <select class='uk-select uk-form-small' name='person'>$filterPersonOptions</select>
            <button class='uk-button uk-button-default' type='submit'><span uk-icon='icon: search'></span> Filter</button>
        </form>";
        $form = "<form class='arbor-log-form' method='post' enctype='multipart/form-data'>
            $csrf
            <div class='arbor-simple-grid'>
                <label><span>Person</span><select class='uk-select' name='person_id' required>$personOptions</select></label>
                <label><span>Photo file</span><input class='uk-input' type='file' name='photo_file' accept='image/*' required></label>
                <label><span>Title</span><input class='uk-input' type='text' name='title' placeholder='Portrait, family photo, document photo...'></label>
                <label><span>Year</span><input class='uk-input' type='number' min='0' max='2100' name='year'></label>
                <label><span>Profile photo</span><label class='arbor-checkline'><input class='uk-checkbox' type='checkbox' name='is_profile' value='1'> Use as main photo</label></label>
            </div>
            <label class='arbor-simple-full'><span>Description</span><textarea class='uk-textarea' rows='2' name='description' placeholder='Who is in the photo, where it came from, and any uncertainty.'></textarea></label>
            <button class='uk-button uk-button-primary' type='submit' name='add_photo' value='1'><span uk-icon='icon: plus'></span> Add photo</button>
        </form>";
        $body = $rows
            ? "<div class='arbor-photo-grid'>$rows</div>"
            : "<div class='arbor-empty'>
                 <span uk-icon='icon: image; ratio: 3'></span>
                 <h4>" . ($selectedPersonId ? 'No photos for this person' : 'No photos yet') . "</h4>
                 <p>" . ($selectedPersonId ? 'Upload a photo for this person, or show all photos.' : 'Open a person profile and upload portraits, document photos, and family images there.') . "</p>
                 <a class='uk-button uk-button-default' href='" . ($selectedPersonId ? $photosUrl : $peopleUrl) . "'><span uk-icon='icon: users'></span> " . ($selectedPersonId ? 'Show all photos' : 'Choose person') . "</a>
               </div>";

        return "<div class='pw-wrap Arbor'>
            <p class='uk-text-muted'>Photos in " . htmlspecialchars($tree['name']) . ". Upload and manage photos from each person profile.</p>
            <div class='arbor-toolbar'>
                <a class='uk-button uk-button-default' href='$treeUrl'><span uk-icon='icon: tree'></span> Tree overview</a>
                <a class='uk-button uk-button-default' href='$viewerUrl'><span uk-icon='icon: image'></span> Tree viewer</a>
                <a class='uk-button uk-button-primary' href='$peopleUrl'><span uk-icon='icon: users'></span> People</a>
            </div>
            $filterForm
            $filterNote
            $form
            $body
        </div>";
    }

    protected function addTreePhoto(int $treeId): void
    {
        $input = $this->wire('input');
        $personId = (int) $input->post('person_id');
        if (!$personId) throw new WireException('Choose a person for this photo');
        $this->requireRecordInTree('persons', $personId, $treeId);
        $filename = $this->handleTreePhotoUpload($treeId, $personId);
        $count = count($this->arbor->model('photos')->forPerson($personId));
        $this->arbor->model('photos')->save([
            'person_id' => $personId,
            'tree_id' => $treeId,
            'filename' => $filename,
            'title' => $input->post->text('title') ?? '',
            'description' => $input->post->textarea('description') ?? '',
            'is_profile' => (int) $input->post('is_profile'),
            'year' => (int) $input->post('year') ?: null,
            'sort' => $count,
        ]);
        $this->message('Photo added');
    }

    protected function updateTreePhoto(int $treeId): void
    {
        $input = $this->wire('input');
        $photoId = (int) $input->post('photo_id');
        if (!$photoId) throw new WireException('Missing photo');
        $this->requireRecordInTree('photos', $photoId, $treeId);
        $photo = $this->arbor->model('photos')->get($photoId);
        if (!$photo) throw new Wire404Exception('Photo not found');
        $photo['title'] = $input->post->text('photo_title') ?? '';
        $photo['year'] = (int) $input->post('photo_year') ?: null;
        $photo['description'] = $input->post->textarea('photo_description') ?? '';
        $this->arbor->model('photos')->save($photo, $photoId);
        $this->message('Photo updated');
    }

    protected function deleteTreePhoto(int $treeId): void
    {
        $photoId = (int) $this->wire('input')->post('photo_id');
        if (!$photoId) throw new WireException('Missing photo');
        $this->requireRecordInTree('photos', $photoId, $treeId);
        $this->arbor->model('photos')->delete($photoId);
        $this->message('Photo deleted');
    }

    protected function setProfilePhoto(int $treeId): void
    {
        $photoId = (int) $this->wire('input')->post('photo_id');
        if (!$photoId) throw new WireException('Missing photo');
        $this->requireRecordInTree('photos', $photoId, $treeId);
        $this->arbor->model('photos')->setProfile($photoId);
        $this->message('Main photo updated');
    }

    protected function handleTreePhotoUpload(int $treeId, int $personId): string
    {
        if (empty($_FILES['photo_file']['name'])) throw new WireException('Choose a photo file');
        $file = $_FILES['photo_file'];
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new WireException('Photo upload failed');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > (int) $this->arbor->maxPhotoSize * 1024) {
            throw new WireException('Photo file is empty or too large');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || empty($info['mime'])) throw new WireException('Photo file could not be verified');
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extMap[$info['mime']])) throw new WireException('Photo file type is not supported');
        $dir = $this->arbor->uploadDir($treeId, $personId);
        $target = $dir . bin2hex(random_bytes(16)) . '.' . $extMap[$info['mime']];
        if (!move_uploaded_file($file['tmp_name'], $target)) throw new WireException('Could not save photo file');
        return basename($target);
    }

    protected function handleDocumentUpload(int $treeId, int $personId): string
    {
        if (empty($_FILES['document_file']['name'])) return '';
        $file = $_FILES['document_file'];
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) return '';
        if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new WireException('Document upload failed');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > (int) $this->arbor->maxDocSize * 1024) {
            throw new WireException('Document file is empty or too large');
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff'];
        if (!in_array($ext, $allowed, true)) throw new WireException('Document file type is not supported');

        if ($ext !== 'pdf') {
            $info = @getimagesize($file['tmp_name']);
            if (!$info || empty($info['mime']) || strpos((string) $info['mime'], 'image/') !== 0) {
                throw new WireException('Image document could not be verified');
            }
        } else {
            $fh = fopen($file['tmp_name'], 'rb');
            $head = $fh ? fread($fh, 4) : '';
            if ($fh) fclose($fh);
            if ($head !== '%PDF') throw new WireException('PDF document could not be verified');
        }

        $dir = $this->arbor->uploadDir($treeId, $personId);
        $target = $dir . bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!move_uploaded_file($file['tmp_name'], $target)) throw new WireException('Could not save document file');
        return basename($target);
    }

    protected function viewer(array $tree): string
    {
        return $this->renderTemplate('arbor-viewer', ['tree' => $tree]);
    }

    protected function importGedcom(array $tree): string
    {
        $msg = '';
        $this->requireEditTree($tree);
        if (!empty($_FILES['ged']['tmp_name'])) {
            $this->requireValidPost();
            $file = $_FILES['ged'];
            $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $size = (int) ($file['size'] ?? 0);
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $msg = "<div class='uk-alert uk-alert-danger'><p>Upload failed. Please try again.</p></div>";
            } elseif ($size <= 0 || $size > (int) $this->arbor->maxDocSize * 1024) {
                $msg = "<div class='uk-alert uk-alert-danger'><p>The file is empty or larger than the configured upload limit.</p></div>";
            } elseif (!in_array($ext, ['ged','gedcom'], true)) {
                $msg = "<div class='uk-alert uk-alert-danger'><p>Please upload a .ged or .gedcom file.</p></div>";
            } else {
                $stats = $this->arbor->gedcom()->import($file['tmp_name'], (int) $tree['id']);
                $msg = "<div class='uk-alert uk-alert-success'>
                    <p><strong>Import complete.</strong> People: {$stats['persons']} · Families: {$stats['unions']} · Sources: {$stats['sources']} · Places: {$stats['places']}.</p>
                </div>";
            }
        }
        $name = htmlspecialchars($tree['name']);
        $csrf = $this->csrfInput();
        return "<div class='pw-wrap Arbor'>
            <p class='uk-text-muted'>Upload a family tree file from another genealogy program. Supported formats: GEDCOM 5.5.1 and 7.0.</p>
            $msg
            <form class='InputfieldForm' method='post' enctype='multipart/form-data'>
                $csrf
                <ul class='Inputfields'>
                    <li class='Inputfield InputfieldFile InputfieldStateRequired'>
                        <label class='InputfieldHeader ui-widget-header'><i class='toggle-icon fa fa-fw fa-angle-down'></i>Family file</label>
                        <div class='InputfieldContent ui-widget-content'>
                            <input type='file' name='ged' accept='.ged,.gedcom' required>
                            <p class='notes'>Choose a .ged or .gedcom file exported from your genealogy app.</p>
                        </div>
                    </li>
                </ul>
                <div class='uk-margin'>
                    <button type='submit' class='uk-button uk-button-primary'><span uk-icon='icon: upload'></span> Import</button>
                </div>
            </form>
        </div>";
    }

    protected function exportGedcom(array $tree): string
    {
        $version = $this->wire('input')->get('v') === '7' ? '7.0' : '5.5.1';
        if ($this->wire('input')->get('go')) {
            $body = $this->arbor->gedcom()->export((int) $tree['id'], $version);
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $tree['name']);
            $file = $name . '-' . date('Ymd') . '-gedcom' . str_replace('.', '', $version) . '.ged';
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            echo $body;
            exit;
        }
        $name = htmlspecialchars($tree['name']);
        $url551 = $this->url('export', ['tree' => $tree['id'], 'v' => 5, 'go' => 1]);
        $url70  = $this->url('export', ['tree' => $tree['id'], 'v' => 7, 'go' => 1]);
        return "<div class='pw-wrap Arbor'>
            <div class='uk-grid-small uk-child-width-1-2@m' uk-grid>
                <div>
                    <div class='uk-card uk-card-default uk-card-body uk-card-small'>
                        <h3 class='uk-card-title'>Most compatible</h3>
                        <p class='uk-text-small uk-text-muted'>Best for Ancestry, MyHeritage, RootsMagic and older genealogy programs. Some advanced details may be simplified.</p>
                        <a class='uk-button uk-button-primary' href='$url551'><span uk-icon='icon: download'></span> Download .ged</a>
                    </div>
                </div>
                <div>
                    <div class='uk-card uk-card-default uk-card-body uk-card-small'>
                        <h3 class='uk-card-title'>Modern format</h3>
                        <p class='uk-text-small uk-text-muted'>Best when the receiving app supports GEDCOM 7.0. Keeps more relationships, places, images, and translated names.</p>
                        <a class='uk-button uk-button-primary' href='$url70'><span uk-icon='icon: download'></span> Download .ged</a>
                    </div>
                </div>
            </div>
        </div>";
    }

    protected function personSummary(int $personId): string
    {
        $n = $this->arbor->model('names')->primary($personId);
        if (!$n) return "#$personId";
        $parts = array_filter([$n['given'], $n['patronymic'], $n['surname']]);
        return implode(' ', $parts);
    }

    protected function renderTemplate(string $name, array $vars = []): string
    {
        $tplPath = __DIR__ . '/templates/' . $name . '.php';
        if (!is_file($tplPath)) return "<p>Template missing: $name</p>";
        $vars['arbor']   = $this->arbor;
        $vars['process'] = $this;
        $vars['baseUrl'] = $this->page->url;
        $vars['csrfInput'] = $this->csrfInput();
        extract($vars);
        ob_start();
        include $tplPath;
        return ob_get_clean();
    }

    public function ___install(): void
    {
        $pages = $this->wire('pages');
        $pages->uncacheAll();
        $setup = $pages->get('template=admin, name=setup, include=all');
        if (!$setup->id) $setup = $pages->get(2);
        if (!$setup || !$setup->id) return;

        $existing = $pages->get('template=admin, name=arbor, include=all');
        if ($existing->id) return;

        $page = new Page();
        $page->template = 'admin';
        $page->parent = $setup;
        $page->name = 'arbor';
        $page->title = 'Arbor';
        $page->process = $this;
        $page->save();
    }

    public function ___uninstall(): void
    {
        $this->wire('pages')->uncacheAll();
        $p = $this->wire('pages')->get('template=admin, name=arbor, include=all');
        if ($p->id) $this->wire('pages')->delete($p, true);
        $this->wire('pages')->uncacheAll();
    }
}
