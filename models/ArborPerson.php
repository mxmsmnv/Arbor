<?php namespace ProcessWire;

class ArborPerson extends Wire
{
    protected Arbor $arbor;

    protected array $fields = [
        'tree_id','uid','sex','gender_text','is_alive','ethnicity','religion',
        'is_cohen','is_levi','bio','notes','resn','gedcom_id','refn',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_persons WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByTree(int $treeId, array $opts = []): array
    {
        $db = $this->wire('database');
        $search = $opts['search'] ?? '';
        $limit  = max(1, min(500, (int) ($opts['limit'] ?? 100)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $sql = "SELECT p.*, n.given, n.surname, n.patronymic,
                       MIN(b.event_date) AS birth_date,
                       MAX(b.event_date_approx) AS birth_date_approx
                FROM arbor_persons p
                LEFT JOIN arbor_names n
                  ON n.person_id = p.id AND n.name_type = 'BIRTH'
                LEFT JOIN arbor_events b
                  ON b.person_id = p.id AND b.event_type = 'birth'
                WHERE p.tree_id = :tree_id";
        $bind = [':tree_id' => $treeId];

        if ($search !== '') {
            $sql .= " AND (n.given LIKE :q OR n.surname LIKE :q OR n.patronymic LIKE :q
                          OR n.given_hebrew LIKE :q OR p.refn LIKE :q)";
            $bind[':q'] = '%' . $search . '%';
        }
        if (($opts['filter'] ?? '') === 'missing_parents') {
            $sql .= " AND NOT EXISTS (
                        SELECT 1 FROM arbor_union_children uc
                        WHERE uc.person_id = p.id
                      )";
        } elseif (($opts['filter'] ?? '') === 'missing_birth_date') {
            $sql .= " AND NOT EXISTS (
                        SELECT 1 FROM arbor_events be
                        WHERE be.person_id = p.id
                          AND be.event_type = 'birth'
                          AND be.event_date IS NOT NULL
                      )";
        }
        $sql .= " GROUP BY p.id ORDER BY n.surname, n.given LIMIT $offset, $limit";

        $stmt = $db->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findByUid(string $uid, int $treeId): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_persons WHERE uid = :uid AND tree_id = :tree");
        $stmt->execute([':uid' => $uid, ':tree' => $treeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByGedcomId(string $gedcomId, int $treeId): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_persons WHERE gedcom_id = :g AND tree_id = :t");
        $stmt->execute([':g' => $gedcomId, ':t' => $treeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, ?int $id = null): int
    {
        $arbor = $this->arbor;
        $data = $arbor->personBeforeSave($data);

        if (empty($data['uid'])) $data['uid'] = self::uuid4();

        $db = $this->wire('database');
        $now = time();

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $sql = "UPDATE arbor_persons SET $set, modified = :modified WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
            foreach ($this->fields as $f) $stmt->bindValue(":$f", $data[$f] ?? '');
            $stmt->execute();
            $arbor->personAfterSave($id, $data);
            return $id;
        }

        $cols = implode(',', $this->fields) . ',created,modified';
        $vals = ':' . implode(', :', $this->fields) . ', :created, :modified';
        $stmt = $db->prepare("INSERT INTO arbor_persons ($cols) VALUES ($vals)");
        foreach ($this->fields as $f) $stmt->bindValue(":$f", $data[$f] ?? '');
        $stmt->bindValue(':created', $now, \PDO::PARAM_INT);
        $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        $stmt->execute();
        $newId = (int) $db->lastInsertId();
        $arbor->personAfterSave($newId, $data);
        return $newId;
    }

    public function delete(int $id): bool
    {
        $db = $this->wire('database');
        $row = $this->get($id);
        if (!$row) return false;

        $ok = $db->prepare("DELETE FROM arbor_persons WHERE id = :id")->execute([':id' => $id]);
        if ($ok) $this->arbor->removeUploadDir((int) $row['tree_id'], $id);
        return $ok;
    }

    public function externalIds(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_external_ids WHERE person_id = :p");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function saveExternalId(int $personId, string $idType, string $externalId, ?int $id = null): int
    {
        $db = $this->wire('database');
        if ($id) {
            $stmt = $db->prepare("UPDATE arbor_external_ids SET id_type=:t, external_id=:e WHERE id=:id");
            $stmt->execute([':t' => $idType, ':e' => $externalId, ':id' => $id]);
            return $id;
        }
        $stmt = $db->prepare("INSERT INTO arbor_external_ids (person_id, id_type, external_id) VALUES (:p, :t, :e)");
        $stmt->execute([':p' => $personId, ':t' => $idType, ':e' => $externalId]);
        return (int) $db->lastInsertId();
    }

    public function deleteExternalId(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_external_ids WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function relatives(int $personId): array
    {
        $db = $this->wire('database');
        $out = ['parents' => [], 'children' => [], 'spouses' => [], 'siblings' => []];

        $stmt = $db->prepare("SELECT u.* FROM arbor_unions u
                              JOIN arbor_union_children uc ON uc.union_id = u.id
                              WHERE uc.person_id = :p");
        $stmt->execute([':p' => $personId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) {
            foreach (['partner1_id','partner2_id'] as $col) {
                if (!empty($u[$col])) $out['parents'][] = (int) $u[$col];
            }
            $sStmt = $db->prepare("SELECT person_id FROM arbor_union_children
                                   WHERE union_id = :u AND person_id <> :p");
            $sStmt->execute([':u' => $u['id'], ':p' => $personId]);
            foreach ($sStmt->fetchAll(\PDO::FETCH_COLUMN) as $sib) $out['siblings'][] = (int) $sib;
        }

        $stmt = $db->prepare("SELECT * FROM arbor_unions WHERE partner1_id = :p OR partner2_id = :p");
        $stmt->execute([':p' => $personId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) {
            $spouse = ($u['partner1_id'] == $personId) ? $u['partner2_id'] : $u['partner1_id'];
            if ($spouse) $out['spouses'][] = (int) $spouse;
            $cStmt = $db->prepare("SELECT person_id FROM arbor_union_children WHERE union_id = :u");
            $cStmt->execute([':u' => $u['id']]);
            foreach ($cStmt->fetchAll(\PDO::FETCH_COLUMN) as $ch) $out['children'][] = (int) $ch;
        }

        foreach ($out as $k => $v) $out[$k] = array_values(array_unique($v));
        return $out;
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-'
             . substr($h, 16, 4) . '-' . substr($h, 20);
    }
}
