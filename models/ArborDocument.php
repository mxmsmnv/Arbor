<?php namespace ProcessWire;

class ArborDocument extends Wire
{
    protected Arbor $arbor;
    protected array $fields = [
        'person_id','tree_id','doc_type','status','title','repo_id','archive_name',
        'fond','opis','delo','list_folio','folio_verso','doc_date',
        'doc_place_id','doc_place_str','filename','external_url','description','is_private',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_documents WHERE person_id = :p ORDER BY doc_date, id");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $intCols = ['person_id','tree_id','repo_id','folio_verso','doc_place_id','is_private'];
        $defaults = ['folio_verso' => 0, 'is_private' => 0, 'status' => 'found'];
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_documents SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields) . ',created';
            $vals = ':' . implode(', :', $this->fields) . ', :created';
            $stmt = $db->prepare("INSERT INTO arbor_documents ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', time(), \PDO::PARAM_INT);
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? ($defaults[$f] ?? null);
            if (in_array($f, $intCols, true)) {
                if ($f === 'person_id' || $f === 'tree_id') {
                    $stmt->bindValue(":$f", (int) ($v ?? 0), \PDO::PARAM_INT);
                } else {
                    $v = ($v === null || $v === '') ? null : (int) $v;
                    $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
                }
            } elseif ($f === 'doc_date') {
                $v = $v ?: null;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        $newId = $id ?: (int) $db->lastInsertId();
        $this->arbor->documentAfterSave($newId, $data);
        return $newId;
    }

    public function delete(int $id): bool
    {
        $row = $this->get($id);
        if (!$row) return false;
        $file = '';
        if (!empty($row['filename'])) {
            $file = $this->arbor->uploadDir((int) $row['tree_id'], (int) $row['person_id']) . $row['filename'];
        }
        if ($file && is_file($file)) @unlink($file);
        return $this->wire('database')->prepare("DELETE FROM arbor_documents WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
