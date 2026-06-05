<?php namespace ProcessWire;

/**
 * Arbor — ProcessWire Genealogy Tree Module
 *
 * Main module: registers configuration, installs/uninstalls the database schema,
 * loads model classes, exposes hook surface, and serves as the facade for the
 * admin process and the REST API.
 */
class Arbor extends WireData implements Module, ConfigurableModule
{
    const SCHEMA_VERSION = 3;

    public static function getModuleInfo(): array
    {
        return [
            'title'    => 'Arbor',
            'version'  => 100,
            'summary'  => 'Professional genealogy module: event-centric, source-centric data model aligned with GEDCOM 7.0 and Gramps. Multi-script names, fond/opis/delo citations, DNA, GPS research workflow, REST API, GEDCOM import/export, optional AiWire integration.',
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'icon'     => 'tree',
            'requires' => 'ProcessWire>=3.0.200, PHP>=8.1',
            'installs' => ['ProcessArbor', 'ArborApi'],
            'autoload' => true,
            'singular' => true,
            'permissions' => [
                'arbor-view'  => 'View Arbor genealogy trees',
                'arbor-edit'  => 'Edit Arbor genealogy data',
                'arbor-admin' => 'Administer Arbor module and trees',
            ],
        ];
    }

    /** @var array<string,object> lazy-loaded model handlers */
    protected array $models = [];

    public static function getDefaultConfig(): array
    {
        return [
            'uploadPath'          => '/site/assets/files/arbor/',
            'maxPhotoSize'        => 2048,
            'maxPhotosPerPerson'  => 50,
            'maxDocSize'          => 10240,
            'defaultPublic'       => 0,
            'dmSoundex'           => 1,
            'aiEnabled'           => 0,
            'aiProvider'          => '',
            'aiParsePrompt'       => '',
            'schemaVersion'       => self::SCHEMA_VERSION,
        ];
    }

    public function __construct()
    {
        parent::__construct();
        foreach (self::getDefaultConfig() as $k => $v) $this->set($k, $v);
    }

    public function init(): void
    {
        require_once __DIR__ . '/ArborGedcom.php';
        require_once __DIR__ . '/ArborAi.php';
        $dir = __DIR__ . '/models/';
        foreach (glob($dir . '*.php') as $file) require_once $file;
        $this->ensurePermissions();
        $this->ensureCurrentSchema();
    }

    /**
     * @param string $name e.g. "persons", "names", "events", "places",
     *                     "unions", "sources", "citations", "repositories",
     *                     "documents", "associations", "citizenships",
     *                     "photos", "dna", "research", "tasks", "trees"
     */
    public function model(string $name): object
    {
        $key = strtolower($name);
        if (isset($this->models[$key])) return $this->models[$key];
        $map = [
            'trees'         => ArborTree::class,
            'persons'       => ArborPerson::class,
            'names'         => ArborName::class,
            'unions'        => ArborUnion::class,
            'places'        => ArborPlace::class,
            'events'        => ArborEvent::class,
            'associations'  => ArborAssociation::class,
            'citizenships'  => ArborCitizenship::class,
            'photos'        => ArborPhoto::class,
            'repositories'  => ArborRepository::class,
            'sources'       => ArborSource::class,
            'citations'     => ArborCitation::class,
            'documents'     => ArborDocument::class,
            'dna'           => ArborDna::class,
            'research'      => ArborResearch::class,
            'tasks'         => ArborTask::class,
        ];
        if (!isset($map[$key])) throw new WireException("Unknown Arbor model: $name");
        $class = $map[$key];
        $obj = new $class($this);
        $this->models[$key] = $obj;
        return $obj;
    }

    public function gedcom(): ArborGedcom
    {
        if (!isset($this->models['__gedcom'])) {
            $this->models['__gedcom'] = new ArborGedcom($this);
        }
        return $this->models['__gedcom'];
    }

    public function ai(): ArborAi
    {
        if (!isset($this->models['__ai'])) {
            $this->models['__ai'] = new ArborAi($this);
        }
        return $this->models['__ai'];
    }

    public function uploadDir(int $treeId, ?int $personId = null): string
    {
        $base = rtrim($this->uploadBaseDir(), '/');
        $path = $base . '/' . $treeId;
        if ($personId) $path .= '/' . $personId;
        if (!is_dir($path)) wireMkdir($path, true);
        return $path . '/';
    }

    public function removeUploadDir(int $treeId, ?int $personId = null): void
    {
        $base = rtrim($this->uploadBaseDir(), '/') . '/';
        $path = $base . $treeId;
        if ($personId) $path .= '/' . $personId;

        $realBase = realpath($base);
        $realPath = realpath($path);
        if (!$realBase || !$realPath || !is_dir($realPath)) return;
        if (strpos($realPath . '/', rtrim($realBase, '/') . '/') !== 0) return;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($realPath);
    }

    public function uploadUrl(int $treeId, ?int $personId = null): string
    {
        $base = rtrim($this->uploadPath, '/');
        $url = $base . '/' . $treeId;
        if ($personId) $url .= '/' . $personId;
        return $url . '/';
    }

    protected function uploadBaseDir(): string
    {
        return rtrim($this->wire('config')->paths->root, '/') . rtrim($this->uploadPath, '/');
    }

    public function isLiving(array $personRow, array $events = []): bool
    {
        if (empty($personRow['is_alive'])) return false;
        $api = $this->wire('modules')->isInstalled('ArborApi') ? $this->wire('modules')->get('ArborApi') : null;
        $threshold = (int) ($api ? $api->livingThreshold : 110);
        if ($threshold > 0) {
            $birth = null;
            foreach ($events as $ev) {
                if ($ev['event_type'] === 'birth' && !empty($ev['event_date'])) {
                    $birth = $ev['event_date']; break;
                }
            }
            if ($birth) {
                $year = (int) substr($birth, 0, 4);
                if ($year > 0 && ((int) date('Y')) - $year > $threshold) return false;
            }
        }
        return true;
    }

    public function canViewTree(array $tree): bool
    {
        $user = $this->wire('user');
        if (!empty($tree['is_public'])) return true;
        if (!$user->isLoggedin()) return false;
        if ($user->hasPermission('arbor-admin')) return true;
        if ((int) $user->id === (int) ($tree['owner_id'] ?? 0)) return true;
        return $user->hasPermission('arbor-view');
    }

    public function canCreateTree(): bool
    {
        $user = $this->wire('user');
        return $user->isLoggedin() && ($user->hasPermission('arbor-edit') || $user->hasPermission('arbor-admin'));
    }

    public function canEditTree(array $tree): bool
    {
        $user = $this->wire('user');
        if (!$user->isLoggedin()) return false;
        if ($user->hasPermission('arbor-admin')) return true;
        return $user->hasPermission('arbor-edit') && (int) $user->id === (int) ($tree['owner_id'] ?? 0);
    }

    public function canDeleteTree(array $tree): bool
    {
        return $this->wire('user')->hasPermission('arbor-admin');
    }

    public function treeIdForRecord(string $entity, int $id): ?int
    {
        $db = $this->wire('database');
        $direct = [
            'trees' => ['arbor_trees', 'id'],
            'persons' => ['arbor_persons', 'tree_id'],
            'places' => ['arbor_places', 'tree_id'],
            'unions' => ['arbor_unions', 'tree_id'],
            'events' => ['arbor_events', 'tree_id'],
            'photos' => ['arbor_photos', 'tree_id'],
            'documents' => ['arbor_documents', 'tree_id'],
            'repositories' => ['arbor_repositories', 'tree_id'],
            'sources' => ['arbor_sources', 'tree_id'],
            'research_questions' => ['arbor_research_questions', 'tree_id'],
            'research_log' => ['arbor_research_log', 'tree_id'],
            'tasks' => ['arbor_tasks', 'tree_id'],
            'proof_arguments' => ['arbor_proof_arguments', 'tree_id'],
            'dna_kits' => ['arbor_dna_kits', 'tree_id'],
        ];
        if (isset($direct[$entity])) {
            [$table, $col] = $direct[$entity];
            $stmt = $db->prepare("SELECT $col FROM $table WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $treeId = $stmt->fetchColumn();
            return $treeId ? (int) $treeId : null;
        }

        $joins = [
            'names' => "SELECT p.tree_id FROM arbor_names r JOIN arbor_persons p ON p.id = r.person_id WHERE r.id = :id",
            'citizenships' => "SELECT p.tree_id FROM arbor_citizenships r JOIN arbor_persons p ON p.id = r.person_id WHERE r.id = :id",
            'external_ids' => "SELECT p.tree_id FROM arbor_external_ids r JOIN arbor_persons p ON p.id = r.person_id WHERE r.id = :id",
            'associations' => "SELECT p.tree_id FROM arbor_associations r JOIN arbor_persons p ON p.id = r.person_id WHERE r.id = :id",
            'citations' => "SELECT s.tree_id FROM arbor_citations r JOIN arbor_sources s ON s.id = r.source_id WHERE r.id = :id",
            'union_children' => "SELECT u.tree_id FROM arbor_union_children r JOIN arbor_unions u ON u.id = r.union_id WHERE r.id = :id",
            'place_names' => "SELECT p.tree_id FROM arbor_place_names r JOIN arbor_places p ON p.id = r.place_id WHERE r.id = :id",
            'place_jurisdictions' => "SELECT p.tree_id FROM arbor_place_jurisdictions r JOIN arbor_places p ON p.id = r.place_id WHERE r.id = :id",
            'event_fields' => "SELECT e.tree_id FROM arbor_event_fields r JOIN arbor_events e ON e.id = r.event_id WHERE r.id = :id",
            'dna_matches' => "SELECT k.tree_id FROM arbor_dna_matches r JOIN arbor_dna_kits k ON k.id = r.kit_a_id WHERE r.id = :id",
            'dna_segments' => "SELECT k.tree_id FROM arbor_dna_segments r JOIN arbor_dna_matches m ON m.id = r.match_id JOIN arbor_dna_kits k ON k.id = m.kit_a_id WHERE r.id = :id",
        ];
        if (!isset($joins[$entity])) return null;
        $stmt = $db->prepare($joins[$entity]);
        $stmt->execute([':id' => $id]);
        $treeId = $stmt->fetchColumn();
        return $treeId ? (int) $treeId : null;
    }

    /* ----- hook stubs (callable via $wire->addHook) ----- */
    public function ___personBeforeSave(array $data): array { return $data; }
    public function ___personAfterSave(int $id, array $data): void {}
    public function ___eventAfterSave(int $id, array $data): void {}
    public function ___photoAfterUpload(int $id, array $data): void {}
    public function ___documentAfterSave(int $id, array $data): void {}
    public function ___associationAfterSave(int $id, array $data): void {}
    public function ___importBefore(string $filename, array $opts): array { return $opts; }
    public function ___importAfter(array $stats): void {}

    /* ----- ConfigurableModule ----- */
    public static function getModuleConfigInputfields(array $data): InputfieldWrapper
    {
        $defaults = self::getDefaultConfig();
        $data = array_merge($defaults, $data);
        $modules = wire('modules');
        $form = new InputfieldWrapper();

        // ===== Storage =====
        $fsStorage = $modules->get('InputfieldFieldset');
        $fsStorage->label = 'File storage & limits';
        $fsStorage->icon = 'folder';
        $form->add($fsStorage);

        $f = $modules->get('InputfieldText');
        $f->name = 'uploadPath';
        $f->label = 'File storage root';
        $f->description = 'Filesystem root for photos and document scans, relative to your site root.';
        $f->notes = 'Per-person files are stored under {root}/{tree_id}/{person_id}/. Default: /site/assets/files/arbor/';
        $f->value = $data['uploadPath'];
        $f->required = true;
        $fsStorage->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'maxPhotoSize';
        $f->label = 'Max photo size (KB)';
        $f->description = 'Largest accepted photo upload, in kilobytes. Files exceeding this size are silently rejected during multi-upload.';
        $f->notes = 'PHP upload_max_filesize and post_max_size still apply on top of this limit.';
        $f->value = $data['maxPhotoSize'];
        $f->columnWidth = 33;
        $fsStorage->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'maxPhotosPerPerson';
        $f->label = 'Max photos per person';
        $f->description = 'Hard cap on the photo gallery for a single person record.';
        $f->notes = 'Existing photos beyond the limit remain visible; uploads stop once the limit is reached.';
        $f->value = $data['maxPhotosPerPerson'];
        $f->columnWidth = 33;
        $fsStorage->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'maxDocSize';
        $f->label = 'Max document scan size (KB)';
        $f->description = 'Largest accepted archival document scan (PDF or image) per upload.';
        $f->notes = 'Use a higher value for high-resolution metrical book photographs (typically 5–20 MB).';
        $f->value = $data['maxDocSize'];
        $f->columnWidth = 34;
        $fsStorage->add($f);

        // ===== Trees defaults =====
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = 'Tree defaults';
        $fs->icon = 'tree';
        $form->add($fs);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'defaultPublic';
        $f->label = 'New trees are public by default';
        $f->label2 = 'Yes, set is_public = 1 when creating a new tree';
        $f->description = 'When enabled, a freshly created tree is exposed read-only through the REST API without authentication. Per-tree owners can still override this on the tree edit form.';
        $f->notes = 'API-level privacy is configured in the ArborApi module (Modules → Site → Arbor REST API).';
        $f->checked = (bool) $data['defaultPublic'];
        $fs->add($f);

        // ===== Names =====
        $fsNames = $modules->get('InputfieldFieldset');
        $fsNames->label = 'Names & search';
        $fsNames->icon = 'font';
        $form->add($fsNames);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'dmSoundex';
        $f->label = 'Auto-compute Daitch-Mokotoff soundex';
        $f->label2 = 'Compute DM and standard Soundex codes on every name save';
        $f->description = 'When enabled, Arbor pre-computes Daitch-Mokotoff and standard Soundex codes for every saved name, transliterating Cyrillic input automatically. The codes are used to find phonetic name matches in Eastern European and Jewish genealogy.';
        $f->notes = 'Disable only if you maintain external indexes; the computation cost is negligible.';
        $f->checked = (bool) $data['dmSoundex'];
        $fsNames->add($f);

        // ===== AI =====
        $fsAi = $modules->get('InputfieldFieldset');
        $fsAi->label = 'AI integration (via AiWire)';
        $fsAi->icon = 'magic';
        $form->add($fsAi);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'aiEnabled';
        $f->label = 'Enable AI features';
        $f->label2 = 'Show AI panel in person edit and enable AI endpoints';
        $f->description = 'When on, Arbor exposes AI buttons (parse text, suggest historical context, detect duplicates, OCR document scans). Disabled features remain hidden in the UI.';
        $f->notes = 'Requires the AiWire module to be installed and a working provider. Arbor does not make any direct external API calls; everything is brokered through AiWire.';
        $f->checked = (bool) $data['aiEnabled'];
        $f->columnWidth = 50;
        $fsAi->add($f);

        $f = $modules->get('InputfieldText');
        $f->name = 'aiProvider';
        $f->label = 'AiWire provider name';
        $f->description = 'Name of the AiWire provider used for Arbor tasks. Leave blank to use the AiWire global default.';
        $f->notes = 'Vision-capable providers (Claude, GPT-4o) are required for document OCR. Other features work with any text-capable provider.';
        $f->value = $data['aiProvider'];
        $f->columnWidth = 50;
        $f->showIf = 'aiEnabled=1';
        $fsAi->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'aiParsePrompt';
        $f->label = 'Custom AI prompt for text-to-person parsing';
        $f->description = 'Optional override of the built-in prompt for extracting genealogical data from free-form text. Use the literal token {input} where the user-supplied text should be inserted.';
        $f->notes = 'Leave blank to use the default prompt that requests names, events, citizenships, and associations as JSON with no invented data.';
        $f->value = $data['aiParsePrompt'];
        $f->rows = 8;
        $f->showIf = 'aiEnabled=1';
        $fsAi->add($f);

        return $form;
    }

    public function ___install(): void
    {
        $this->ensurePermissions();
        $db = $this->wire('database');
        foreach (self::schemaSql() as $sql) $db->exec($sql);
        $this->saveSchemaVersion(self::SCHEMA_VERSION);
    }

    protected function ensurePermissions(): void
    {
        $permissions = $this->wire('permissions');
        foreach (self::getModuleInfo()['permissions'] as $name => $title) {
            $permission = $permissions->get($name);
            if ($permission && $permission->id) continue;
            $permission = new Permission();
            $permission->name = $name;
            $permission->title = $title;
            $permission->save();
        }
    }

    public function ___upgrade($fromVersion, $toVersion): void
    {
        $fromSchema = (int) ($this->schemaVersion ?: 0);
        if ($fromSchema < self::SCHEMA_VERSION) {
            $this->migrateSchema($fromSchema, self::SCHEMA_VERSION);
            $this->saveSchemaVersion(self::SCHEMA_VERSION);
        }
    }

    public function ___uninstall(): void
    {
        $db = $this->wire('database');
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(self::tableNames()) as $t) {
            $db->exec("DROP TABLE IF EXISTS $t");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function migrateSchema(int $from, int $to): void
    {
        if ($from < 2) {
            $db = $this->wire('database');
            if (!$this->dbColumnExists('arbor_citations', 'document_id')) {
                $db->exec("ALTER TABLE arbor_citations
                    ADD document_id INT UNSIGNED DEFAULT NULL AFTER photo_id,
                    ADD INDEX idx_document (document_id)");
            }
        }
        if ($from < 3) {
            $db = $this->wire('database');
            if (!$this->dbColumnExists('arbor_documents', 'status')) {
                $db->exec("ALTER TABLE arbor_documents
                    ADD status ENUM('lead','found','attached','dismissed') NOT NULL DEFAULT 'found' AFTER doc_type,
                    ADD INDEX idx_status (status)");
                $db->exec("UPDATE arbor_documents
                    SET status = 'lead'
                    WHERE title LIKE 'Find % record for %'");
            }
        }
    }

    protected function ensureCurrentSchema(): void
    {
        $fromSchema = (int) ($this->schemaVersion ?: 0);
        if (!$this->dbTableExists('arbor_citations')) return;
        $needsCitationDocument = !$this->dbColumnExists('arbor_citations', 'document_id');
        $needsDocumentStatus = $this->dbTableExists('arbor_documents') && !$this->dbColumnExists('arbor_documents', 'status');
        $needsMigration = $needsCitationDocument || $needsDocumentStatus;
        if ($fromSchema <= 0 && !$needsMigration) return;
        if ($fromSchema >= self::SCHEMA_VERSION && !$needsMigration) return;
        $migrationFrom = $needsCitationDocument ? 1 : ($needsDocumentStatus ? 2 : $fromSchema);
        $this->migrateSchema($migrationFrom, self::SCHEMA_VERSION);
        if ($fromSchema < self::SCHEMA_VERSION) {
            $this->saveSchemaVersion(self::SCHEMA_VERSION);
        }
    }

    protected function saveSchemaVersion(int $version): void
    {
        $data = self::getDefaultConfig();
        foreach (array_keys($data) as $key) {
            $data[$key] = $this->get($key);
        }
        $data['schemaVersion'] = $version;
        $this->wire('modules')->saveConfig($this, $data);
    }

    protected function dbTableExists(string $table): bool
    {
        $stmt = $this->wire('database')->prepare("SHOW TABLES LIKE :table");
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    protected function dbColumnExists(string $table, string $column): bool
    {
        $stmt = $this->wire('database')->prepare("SHOW COLUMNS FROM $table LIKE :column");
        $stmt->execute([':column' => $column]);
        return (bool) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public static function tableNames(): array
    {
        return [
            'arbor_trees', 'arbor_persons', 'arbor_names', 'arbor_external_ids',
            'arbor_unions', 'arbor_union_children', 'arbor_places',
            'arbor_place_names', 'arbor_place_jurisdictions', 'arbor_events',
            'arbor_event_fields', 'arbor_associations', 'arbor_citizenships',
            'arbor_photos', 'arbor_repositories', 'arbor_sources',
            'arbor_citations', 'arbor_documents', 'arbor_dna_kits',
            'arbor_dna_matches', 'arbor_dna_segments', 'arbor_research_questions',
            'arbor_research_log', 'arbor_proof_arguments', 'arbor_tasks',
        ];
    }

    public static function schemaSql(): array
    {
        $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        return [
            "CREATE TABLE arbor_trees (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                owner_id INT UNSIGNED NOT NULL DEFAULT 0,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                settings TEXT,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                modified INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_owner (owner_id)
            ) $charset",
            "CREATE TABLE arbor_persons (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                uid VARCHAR(36) NOT NULL DEFAULT '',
                sex ENUM('M','F','X','U') NOT NULL DEFAULT 'U',
                gender_text VARCHAR(128) NOT NULL DEFAULT '',
                is_alive TINYINT(1) NOT NULL DEFAULT 1,
                ethnicity VARCHAR(255) NOT NULL DEFAULT '',
                religion VARCHAR(255) NOT NULL DEFAULT '',
                is_cohen TINYINT(1) NOT NULL DEFAULT 0,
                is_levi TINYINT(1) NOT NULL DEFAULT 0,
                bio TEXT,
                notes TEXT,
                resn ENUM('none','confidential','locked','privacy') NOT NULL DEFAULT 'none',
                gedcom_id VARCHAR(64) NOT NULL DEFAULT '',
                refn VARCHAR(128) NOT NULL DEFAULT '',
                created INT UNSIGNED NOT NULL DEFAULT 0,
                modified INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_tree (tree_id),
                INDEX idx_uid (uid),
                INDEX idx_gedcom (gedcom_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_names (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                name_type ENUM('BIRTH','AKA','IMMIGRANT','MAIDEN','MARRIED','PROFESSIONAL','OTHER') NOT NULL DEFAULT 'BIRTH',
                prefix VARCHAR(64) NOT NULL DEFAULT '',
                given VARCHAR(255) NOT NULL DEFAULT '',
                nickname VARCHAR(128) NOT NULL DEFAULT '',
                surname_pfx VARCHAR(64) NOT NULL DEFAULT '',
                surname VARCHAR(255) NOT NULL DEFAULT '',
                suffix VARCHAR(64) NOT NULL DEFAULT '',
                patronymic VARCHAR(255) NOT NULL DEFAULT '',
                given_hebrew VARCHAR(255) NOT NULL DEFAULT '',
                father_hebrew VARCHAR(255) NOT NULL DEFAULT '',
                matronymic VARCHAR(255) NOT NULL DEFAULT '',
                kinui_id INT UNSIGNED DEFAULT NULL,
                script ENUM('latin','cyrillic','hebrew','yiddish','arabic','other') NOT NULL DEFAULT 'latin',
                language VARCHAR(16) NOT NULL DEFAULT '',
                dm_soundex VARCHAR(32) NOT NULL DEFAULT '',
                std_soundex VARCHAR(8) NOT NULL DEFAULT '',
                surname_adopted_date DATE DEFAULT NULL,
                date_from DATE DEFAULT NULL,
                date_to DATE DEFAULT NULL,
                sort TINYINT UNSIGNED NOT NULL DEFAULT 0,
                notes TEXT,
                INDEX idx_person (person_id),
                INDEX idx_type (name_type),
                INDEX idx_dm (dm_soundex),
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_external_ids (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                id_type VARCHAR(128) NOT NULL,
                external_id VARCHAR(255) NOT NULL,
                INDEX idx_person (person_id),
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_unions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                uid VARCHAR(36) NOT NULL DEFAULT '',
                partner1_id INT UNSIGNED DEFAULT NULL,
                partner2_id INT UNSIGNED DEFAULT NULL,
                union_type ENUM('married_civil','married_religious_jewish','married_religious_christian','married_religious_muslim','married_religious_other','common_law','civil_union','partnered','unmarried_with_children','engaged','unknown') NOT NULL DEFAULT 'unknown',
                married_date DATE DEFAULT NULL,
                married_date_approx TINYINT(1) NOT NULL DEFAULT 0,
                married_place_id INT UNSIGNED DEFAULT NULL,
                divorced TINYINT(1) NOT NULL DEFAULT 0,
                divorced_date DATE DEFAULT NULL,
                gedcom_id VARCHAR(64) NOT NULL DEFAULT '',
                notes TEXT,
                resn ENUM('none','confidential','locked','privacy') NOT NULL DEFAULT 'none',
                created INT UNSIGNED NOT NULL DEFAULT 0,
                modified INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_tree (tree_id),
                INDEX idx_partner1 (partner1_id),
                INDEX idx_partner2 (partner2_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_union_children (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                union_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED NOT NULL,
                pedigree ENUM('birth','adopted','foster','stepchild','guardian','sealing','foundling','birth_disputed','birth_unknown') NOT NULL DEFAULT 'birth',
                pedi_status ENUM('proven','challenged','disproven') NOT NULL DEFAULT 'proven',
                birth_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
                notes TEXT,
                INDEX idx_union (union_id),
                INDEX idx_person (person_id),
                FOREIGN KEY (union_id) REFERENCES arbor_unions(id) ON DELETE CASCADE,
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_places (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                canonical_name VARCHAR(255) NOT NULL DEFAULT '',
                parent_id INT UNSIGNED DEFAULT NULL,
                place_type ENUM('country','region','gubernia','oblast','district','uyezd','raion','city','town','shtetl','village','street','cemetery','hospital','synagogue','church','mosque','ghetto','camp','other') NOT NULL DEFAULT 'other',
                latitude DECIMAL(10,7) DEFAULT NULL,
                longitude DECIMAL(10,7) DEFAULT NULL,
                geonames_id VARCHAR(32) NOT NULL DEFAULT '',
                gov_id VARCHAR(64) NOT NULL DEFAULT '',
                jewishgen_id VARCHAR(64) NOT NULL DEFAULT '',
                wikipedia_url VARCHAR(512) NOT NULL DEFAULT '',
                notes TEXT,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_tree (tree_id),
                INDEX idx_parent (parent_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_place_names (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                place_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                language VARCHAR(16) NOT NULL DEFAULT '',
                script VARCHAR(16) NOT NULL DEFAULT '',
                date_from DATE DEFAULT NULL,
                date_to DATE DEFAULT NULL,
                name_type ENUM('official','historical','yiddish','local','transliteration','other') NOT NULL DEFAULT 'official',
                INDEX idx_place (place_id),
                FOREIGN KEY (place_id) REFERENCES arbor_places(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_place_jurisdictions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                place_id INT UNSIGNED NOT NULL,
                country VARCHAR(128) NOT NULL,
                region VARCHAR(128) NOT NULL DEFAULT '',
                date_from DATE DEFAULT NULL,
                date_to DATE DEFAULT NULL,
                notes VARCHAR(255) NOT NULL DEFAULT '',
                INDEX idx_place (place_id),
                FOREIGN KEY (place_id) REFERENCES arbor_places(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED DEFAULT NULL,
                union_id INT UNSIGNED DEFAULT NULL,
                tree_id INT UNSIGNED NOT NULL,
                event_type VARCHAR(64) NOT NULL DEFAULT 'other',
                event_subtype VARCHAR(128) NOT NULL DEFAULT '',
                title VARCHAR(255) NOT NULL DEFAULT '',
                event_date DATE DEFAULT NULL,
                event_date_approx TINYINT(1) NOT NULL DEFAULT 0,
                event_date_phrase VARCHAR(255) NOT NULL DEFAULT '',
                event_date_cal ENUM('gregorian','julian','hebrew','dual') NOT NULL DEFAULT 'gregorian',
                event_date_hebrew VARCHAR(64) NOT NULL DEFAULT '',
                event_date_sort DATE DEFAULT NULL,
                event_place_id INT UNSIGNED DEFAULT NULL,
                event_place_str VARCHAR(255) NOT NULL DEFAULT '',
                agency VARCHAR(255) NOT NULL DEFAULT '',
                cause VARCHAR(255) NOT NULL DEFAULT '',
                age_at_event VARCHAR(64) NOT NULL DEFAULT '',
                description TEXT,
                source_note VARCHAR(255) NOT NULL DEFAULT '',
                is_private TINYINT(1) NOT NULL DEFAULT 0,
                resn ENUM('none','confidential','locked','privacy') NOT NULL DEFAULT 'none',
                quality TINYINT UNSIGNED NOT NULL DEFAULT 2,
                sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                modified INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_person (person_id),
                INDEX idx_union (union_id),
                INDEX idx_type (event_type),
                INDEX idx_tree (tree_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_event_fields (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id INT UNSIGNED NOT NULL,
                field_key VARCHAR(64) NOT NULL,
                field_value TEXT,
                INDEX idx_event (event_id),
                INDEX idx_key (field_key),
                FOREIGN KEY (event_id) REFERENCES arbor_events(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_associations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                related_id INT UNSIGNED DEFAULT NULL,
                event_id INT UNSIGNED DEFAULT NULL,
                role ENUM('CHIL','CLERGY','FATH','FRIEND','GODP','HUSB','MOTH','MULTIPLE','NGHBR','OFFICIATOR','PARENT','SPOU','WIFE','WITN','SANDEK','KVATER','SHADCHAN','EMPLOYER','EMPLOYEE','TEACHER','STUDENT','EXECUTOR','DOCTOR','LAWYER','GUARDIAN','MASTER','APPRENTICE','PARTNER','LODGER','OTHER') NOT NULL DEFAULT 'OTHER',
                role_phrase VARCHAR(255) NOT NULL DEFAULT '',
                notes TEXT,
                INDEX idx_person (person_id),
                INDEX idx_related (related_id),
                INDEX idx_event (event_id),
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_citizenships (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                country VARCHAR(255) NOT NULL,
                date_from DATE DEFAULT NULL,
                date_to DATE DEFAULT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(255) NOT NULL DEFAULT '',
                INDEX idx_person (person_id),
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_photos (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                tree_id INT UNSIGNED NOT NULL,
                filename VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                description TEXT,
                is_profile TINYINT(1) NOT NULL DEFAULT 0,
                year SMALLINT UNSIGNED DEFAULT NULL,
                crop_x SMALLINT UNSIGNED DEFAULT NULL,
                crop_y SMALLINT UNSIGNED DEFAULT NULL,
                crop_w SMALLINT UNSIGNED DEFAULT NULL,
                crop_h SMALLINT UNSIGNED DEFAULT NULL,
                sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_person (person_id),
                INDEX idx_profile (person_id, is_profile),
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_repositories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                abbreviation VARCHAR(32) NOT NULL DEFAULT '',
                name_original VARCHAR(255) NOT NULL DEFAULT '',
                city VARCHAR(128) NOT NULL DEFAULT '',
                country VARCHAR(128) NOT NULL DEFAULT '',
                address TEXT,
                website VARCHAR(512) NOT NULL DEFAULT '',
                finding_aids TEXT,
                hours VARCHAR(255) NOT NULL DEFAULT '',
                access_policy TEXT,
                notes TEXT,
                INDEX idx_tree (tree_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_sources (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                repo_id INT UNSIGNED DEFAULT NULL,
                title VARCHAR(512) NOT NULL DEFAULT '',
                author VARCHAR(255) NOT NULL DEFAULT '',
                publisher VARCHAR(255) NOT NULL DEFAULT '',
                pub_place VARCHAR(255) NOT NULL DEFAULT '',
                pub_date VARCHAR(64) NOT NULL DEFAULT '',
                edition VARCHAR(64) NOT NULL DEFAULT '',
                volume VARCHAR(64) NOT NULL DEFAULT '',
                source_type ENUM('book','journal','newspaper','vital_record','census','metrical_book','revision_list','manuscript','website','database','dna_test','oral_interview','photograph','artifact','other') NOT NULL DEFAULT 'other',
                media_type ENUM('AUDIO','BOOK','CARD','ELECTRONIC','FICHE','FILM','MAGAZINE','MANUSCRIPT','MAP','NEWSPAPER','PHOTO','TOMBSTONE','VIDEO','OTHER') NOT NULL DEFAULT 'OTHER',
                url VARCHAR(512) NOT NULL DEFAULT '',
                isbn VARCHAR(32) NOT NULL DEFAULT '',
                language VARCHAR(32) NOT NULL DEFAULT '',
                abstract TEXT,
                full_text TEXT,
                translation TEXT,
                archive_name VARCHAR(255) NOT NULL DEFAULT '',
                archive_abbrev VARCHAR(32) NOT NULL DEFAULT '',
                fond VARCHAR(64) NOT NULL DEFAULT '',
                fond_title VARCHAR(512) NOT NULL DEFAULT '',
                opis VARCHAR(64) NOT NULL DEFAULT '',
                delo VARCHAR(64) NOT NULL DEFAULT '',
                delo_title VARCHAR(512) NOT NULL DEFAULT '',
                microfilm_reel VARCHAR(64) NOT NULL DEFAULT '',
                digital_url VARCHAR(512) NOT NULL DEFAULT '',
                ee_template VARCHAR(128) NOT NULL DEFAULT '',
                ee_citation TEXT,
                notes TEXT,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_tree (tree_id),
                INDEX idx_repo (repo_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_citations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED DEFAULT NULL,
                event_id INT UNSIGNED DEFAULT NULL,
                page_ref VARCHAR(128) NOT NULL DEFAULT '',
                folio_verso TINYINT(1) NOT NULL DEFAULT 0,
                quality TINYINT UNSIGNED NOT NULL DEFAULT 2,
                accessed_date DATE DEFAULT NULL,
                transcription TEXT,
                translation TEXT,
                photo_id INT UNSIGNED DEFAULT NULL,
                document_id INT UNSIGNED DEFAULT NULL,
                researcher VARCHAR(128) NOT NULL DEFAULT '',
                notes TEXT,
                INDEX idx_source (source_id),
                INDEX idx_person (person_id),
                INDEX idx_event (event_id),
                INDEX idx_document (document_id),
                FOREIGN KEY (source_id) REFERENCES arbor_sources(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                tree_id INT UNSIGNED NOT NULL,
                doc_type ENUM('metrical_book','revision_list','census','military','police','immigration','passport','birth_certificate','death_certificate','marriage_certificate','ketubah','get','pinkas','photo_document','page_of_testimony','transport_list','camp_record','notarial_deed','court_file','voter_list','draft_list','tax_roll','business_directory','tombstone_inscription','other') NOT NULL DEFAULT 'other',
                status ENUM('lead','found','attached','dismissed') NOT NULL DEFAULT 'found',
                title VARCHAR(255) NOT NULL DEFAULT '',
                repo_id INT UNSIGNED DEFAULT NULL,
                archive_name VARCHAR(255) NOT NULL DEFAULT '',
                fond VARCHAR(64) NOT NULL DEFAULT '',
                opis VARCHAR(64) NOT NULL DEFAULT '',
                delo VARCHAR(64) NOT NULL DEFAULT '',
                list_folio VARCHAR(64) NOT NULL DEFAULT '',
                folio_verso TINYINT(1) NOT NULL DEFAULT 0,
                doc_date DATE DEFAULT NULL,
                doc_place_id INT UNSIGNED DEFAULT NULL,
                doc_place_str VARCHAR(255) NOT NULL DEFAULT '',
                filename VARCHAR(255) NOT NULL DEFAULT '',
                external_url VARCHAR(512) NOT NULL DEFAULT '',
                description TEXT,
                is_private TINYINT(1) NOT NULL DEFAULT 0,
                created INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_person (person_id),
                INDEX idx_type (doc_type),
                INDEX idx_status (status),
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_dna_kits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                tree_id INT UNSIGNED NOT NULL,
                company ENUM('ftdna','23andme','ancestrydna','myheritage','livingdna','gedmatch','other') NOT NULL DEFAULT 'other',
                company_other VARCHAR(128) NOT NULL DEFAULT '',
                kit_id VARCHAR(128) NOT NULL DEFAULT '',
                test_type ENUM('autosomal','y_dna','mt_dna','big_y','y37','y67','y111','y700','other') NOT NULL DEFAULT 'autosomal',
                test_date DATE DEFAULT NULL,
                y_haplogroup VARCHAR(64) NOT NULL DEFAULT '',
                mt_haplogroup VARCHAR(64) NOT NULL DEFAULT '',
                raw_data_file VARCHAR(255) NOT NULL DEFAULT '',
                ethnicity_json TEXT,
                notes TEXT,
                INDEX idx_person (person_id),
                FOREIGN KEY (person_id) REFERENCES arbor_persons(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_dna_matches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kit_a_id INT UNSIGNED NOT NULL,
                kit_b_id INT UNSIGNED DEFAULT NULL,
                kit_b_name VARCHAR(255) NOT NULL DEFAULT '',
                total_cm DECIMAL(8,2) NOT NULL DEFAULT 0,
                longest_segment_cm DECIMAL(8,2) NOT NULL DEFAULT 0,
                predicted_relation VARCHAR(128) NOT NULL DEFAULT '',
                common_ancestor_id INT UNSIGNED DEFAULT NULL,
                triangulation_group VARCHAR(64) NOT NULL DEFAULT '',
                non_paternity_flag TINYINT(1) NOT NULL DEFAULT 0,
                notes TEXT,
                INDEX idx_kit_a (kit_a_id),
                FOREIGN KEY (kit_a_id) REFERENCES arbor_dna_kits(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_dna_segments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                match_id INT UNSIGNED NOT NULL,
                chromosome TINYINT UNSIGNED NOT NULL,
                start_pos BIGINT UNSIGNED NOT NULL,
                end_pos BIGINT UNSIGNED NOT NULL,
                centimorgans DECIMAL(8,2) NOT NULL DEFAULT 0,
                snp_count INT UNSIGNED NOT NULL DEFAULT 0,
                is_imputed TINYINT(1) NOT NULL DEFAULT 0,
                is_phased TINYINT(1) NOT NULL DEFAULT 0,
                side ENUM('maternal','paternal','unknown') NOT NULL DEFAULT 'unknown',
                INDEX idx_match (match_id),
                FOREIGN KEY (match_id) REFERENCES arbor_dna_matches(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_research_questions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED DEFAULT NULL,
                question TEXT NOT NULL,
                status ENUM('open','answered','abandoned') NOT NULL DEFAULT 'open',
                opened_date DATE DEFAULT NULL,
                closed_date DATE DEFAULT NULL,
                notes TEXT,
                INDEX idx_tree (tree_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_research_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                question_id INT UNSIGNED DEFAULT NULL,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED DEFAULT NULL,
                log_date DATE NOT NULL,
                repo_id INT UNSIGNED DEFAULT NULL,
                source_id INT UNSIGNED DEFAULT NULL,
                search_terms VARCHAR(512) NOT NULL DEFAULT '',
                result ENUM('positive','negative','inconclusive') NOT NULL DEFAULT 'inconclusive',
                source_class ENUM('original','derivative','authored') NOT NULL DEFAULT 'original',
                info_class ENUM('primary','secondary','indeterminate') NOT NULL DEFAULT 'primary',
                evidence_class ENUM('direct','indirect','negative') NOT NULL DEFAULT 'direct',
                hours DECIMAL(4,1) NOT NULL DEFAULT 0,
                cost DECIMAL(8,2) NOT NULL DEFAULT 0,
                citation_id INT UNSIGNED DEFAULT NULL,
                notes TEXT,
                INDEX idx_question (question_id),
                INDEX idx_tree (tree_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_proof_arguments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED DEFAULT NULL,
                question_id INT UNSIGNED DEFAULT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                argument TEXT,
                conclusion TEXT,
                conflicts TEXT,
                status ENUM('draft','final') NOT NULL DEFAULT 'draft',
                created INT UNSIGNED NOT NULL DEFAULT 0,
                modified INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_tree (tree_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
            "CREATE TABLE arbor_tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED DEFAULT NULL,
                event_id INT UNSIGNED DEFAULT NULL,
                source_id INT UNSIGNED DEFAULT NULL,
                task_type VARCHAR(64) NOT NULL DEFAULT 'general',
                title VARCHAR(255) NOT NULL DEFAULT '',
                description TEXT,
                status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
                priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
                due_date DATE DEFAULT NULL,
                assigned_to VARCHAR(128) NOT NULL DEFAULT '',
                created INT UNSIGNED NOT NULL DEFAULT 0,
                INDEX idx_tree (tree_id),
                FOREIGN KEY (tree_id) REFERENCES arbor_trees(id) ON DELETE CASCADE
            ) $charset",
        ];
    }
}
