<?php namespace ProcessWire;

class ArborSource extends Wire
{
    protected Arbor $arbor;
    protected array $fields = [
        'tree_id','repo_id','title','author','publisher','pub_place','pub_date',
        'edition','volume','source_type','media_type','url','isbn','language',
        'abstract','full_text','translation',
        'archive_name','archive_abbrev','fond','fond_title','opis','delo','delo_title',
        'microfilm_reel','digital_url','ee_template','ee_citation','notes',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_sources WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forTree(int $treeId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_sources WHERE tree_id = :t ORDER BY title");
        $stmt->execute([':t' => $treeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_sources SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields) . ',created';
            $vals = ':' . implode(', :', $this->fields) . ', :created';
            $stmt = $db->prepare("INSERT INTO arbor_sources ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', time(), \PDO::PARAM_INT);
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? null;
            if ($f === 'tree_id') {
                $stmt->bindValue(":$f", (int) $v, \PDO::PARAM_INT);
            } elseif ($f === 'repo_id') {
                $v = ($v === null || $v === '') ? null : (int) $v;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        return $id ?: (int) $db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_sources WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function formatArchiveCitation(array $source): string
    {
        $parts = [];
        if ($source['archive_abbrev']) $parts[] = $source['archive_abbrev'];
        elseif ($source['archive_name']) $parts[] = $source['archive_name'];
        if ($source['fond']) $parts[] = 'ф. ' . $source['fond'];
        if ($source['opis']) $parts[] = 'оп. ' . $source['opis'];
        if ($source['delo']) $parts[] = 'д. ' . $source['delo'];
        return implode(', ', $parts);
    }
}
