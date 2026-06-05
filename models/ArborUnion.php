<?php namespace ProcessWire;

class ArborUnion extends Wire
{
    protected Arbor $arbor;

    protected array $fields = [
        'tree_id','uid','partner1_id','partner2_id','union_type',
        'married_date','married_date_approx','married_place_id',
        'divorced','divorced_date','gedcom_id','notes','resn',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    public function get(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_unions WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function forTree(int $treeId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_unions WHERE tree_id = :t ORDER BY married_date");
        $stmt->execute([':t' => $treeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function forPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_unions WHERE partner1_id = :p OR partner2_id = :p");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        if (empty($data['uid'])) $data['uid'] = ArborPerson::uuid4();
        $db = $this->wire('database');
        $now = time();
        $dateCols = ['married_date','divorced_date'];
        $intCols  = ['tree_id','partner1_id','partner2_id','married_place_id',
                     'married_date_approx','divorced'];
        $defaults = ['married_date_approx' => 0, 'divorced' => 0];

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $this->fields));
            $stmt = $db->prepare("UPDATE arbor_unions SET $set, modified = :modified WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $this->fields) . ',created,modified';
            $vals = ':' . implode(', :', $this->fields) . ', :created, :modified';
            $stmt = $db->prepare("INSERT INTO arbor_unions ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', $now, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        }
        foreach ($this->fields as $f) {
            $v = $data[$f] ?? ($defaults[$f] ?? null);
            if (in_array($f, $dateCols, true)) {
                $v = $v ?: null;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            } elseif (in_array($f, $intCols, true)) {
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
        return $this->wire('database')->prepare("DELETE FROM arbor_unions WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function children(int $unionId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_union_children WHERE union_id = :u ORDER BY birth_order, id");
        $stmt->execute([':u' => $unionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function addChild(int $unionId, int $personId, array $opts = []): int
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("INSERT INTO arbor_union_children
            (union_id, person_id, pedigree, pedi_status, birth_order, notes)
            VALUES (:u, :p, :pd, :ps, :bo, :n)");
        $stmt->execute([
            ':u' => $unionId, ':p' => $personId,
            ':pd' => $opts['pedigree'] ?? 'birth',
            ':ps' => $opts['pedi_status'] ?? 'proven',
            ':bo' => (int) ($opts['birth_order'] ?? 0),
            ':n'  => $opts['notes'] ?? '',
        ]);
        return (int) $db->lastInsertId();
    }

    public function updateChild(int $childRowId, array $opts): bool
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("UPDATE arbor_union_children
            SET pedigree = :pd, pedi_status = :ps, birth_order = :bo, notes = :n
            WHERE id = :id");
        return $stmt->execute([
            ':pd' => $opts['pedigree'] ?? 'birth',
            ':ps' => $opts['pedi_status'] ?? 'proven',
            ':bo' => (int) ($opts['birth_order'] ?? 0),
            ':n'  => $opts['notes'] ?? '',
            ':id' => $childRowId,
        ]);
    }

    public function removeChild(int $childRowId): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_union_children WHERE id = :id")
            ->execute([':id' => $childRowId]);
    }
}
