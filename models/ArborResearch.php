<?php namespace ProcessWire;

class ArborResearch extends Wire
{
    protected Arbor $arbor;

    protected array $questionFields = ['tree_id','person_id','question','status','opened_date','closed_date','notes'];
    protected array $logFields = [
        'question_id','tree_id','person_id','log_date','repo_id','source_id',
        'search_terms','result','source_class','info_class','evidence_class',
        'hours','cost','citation_id','notes',
    ];
    protected array $proofFields = ['tree_id','person_id','question_id','title','argument','conclusion','conflicts','status'];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    /* Questions */
    public function getQuestion(int $id): ?array { return $this->getRow('arbor_research_questions', $id); }
    public function questionsForTree(int $treeId): array { return $this->rowsBy('arbor_research_questions', 'tree_id', $treeId, 'opened_date DESC'); }
    public function saveQuestion(array $data, ?int $id = null): int
    {
        return $this->saveGeneric('arbor_research_questions', $this->questionFields, $data, $id,
            ['tree_id','person_id'], ['opened_date','closed_date']);
    }
    public function deleteQuestion(int $id): bool { return $this->deleteRow('arbor_research_questions', $id); }

    /* Log */
    public function logForQuestion(int $questionId): array { return $this->rowsBy('arbor_research_log', 'question_id', $questionId, 'log_date'); }
    public function logForTree(int $treeId): array { return $this->rowsBy('arbor_research_log', 'tree_id', $treeId, 'log_date DESC'); }
    public function saveLog(array $data, ?int $id = null): int
    {
        return $this->saveGeneric('arbor_research_log', $this->logFields, $data, $id,
            ['question_id','tree_id','person_id','repo_id','source_id','citation_id'], ['log_date']);
    }
    public function deleteLog(int $id): bool { return $this->deleteRow('arbor_research_log', $id); }

    /* Proof arguments */
    public function getProof(int $id): ?array { return $this->getRow('arbor_proof_arguments', $id); }
    public function proofsForTree(int $treeId): array { return $this->rowsBy('arbor_proof_arguments', 'tree_id', $treeId, 'modified DESC'); }
    public function saveProof(array $data, ?int $id = null): int
    {
        $db = $this->wire('database');
        $now = time();
        $fields = $this->proofFields;
        $intCols = ['tree_id','person_id','question_id'];

        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            $stmt = $db->prepare("UPDATE arbor_proof_arguments SET $set, modified = :modified WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $fields) . ',created,modified';
            $vals = ':' . implode(', :', $fields) . ', :created, :modified';
            $stmt = $db->prepare("INSERT INTO arbor_proof_arguments ($cols) VALUES ($vals)");
            $stmt->bindValue(':created', $now, \PDO::PARAM_INT);
            $stmt->bindValue(':modified', $now, \PDO::PARAM_INT);
        }
        foreach ($fields as $f) {
            $v = $data[$f] ?? null;
            if (in_array($f, $intCols, true)) {
                $v = ($v === null || $v === '') ? null : (int) $v;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        return $id ?: (int) $db->lastInsertId();
    }
    public function deleteProof(int $id): bool { return $this->deleteRow('arbor_proof_arguments', $id); }

    /* helpers */
    protected function getRow(string $table, int $id): ?array
    {
        $stmt = $this->wire('database')->prepare("SELECT * FROM $table WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    protected function rowsBy(string $table, string $col, $val, string $order): array
    {
        $stmt = $this->wire('database')->prepare("SELECT * FROM $table WHERE $col = :v ORDER BY $order");
        $stmt->execute([':v' => $val]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    protected function deleteRow(string $table, int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM $table WHERE id = :id")->execute([':id' => $id]);
    }
    protected function saveGeneric(string $table, array $fields, array $data, ?int $id, array $intCols, array $dateCols = []): int
    {
        $db = $this->wire('database');
        if ($id) {
            $set = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            $stmt = $db->prepare("UPDATE $table SET $set WHERE id = :id");
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        } else {
            $cols = implode(',', $fields);
            $vals = ':' . implode(', :', $fields);
            $stmt = $db->prepare("INSERT INTO $table ($cols) VALUES ($vals)");
        }
        foreach ($fields as $f) {
            $v = $data[$f] ?? null;
            if (in_array($f, $intCols, true)) {
                $v = ($v === null || $v === '') ? null : (int) $v;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            } elseif (in_array($f, $dateCols, true)) {
                $v = $v ?: null;
                $stmt->bindValue(":$f", $v, $v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            } else {
                $stmt->bindValue(":$f", (string) ($v ?? ''));
            }
        }
        $stmt->execute();
        return $id ?: (int) $db->lastInsertId();
    }
}
