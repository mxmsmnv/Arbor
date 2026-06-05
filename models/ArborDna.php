<?php namespace ProcessWire;

class ArborDna extends Wire
{
    protected Arbor $arbor;

    protected array $kitFields = [
        'person_id','tree_id','company','company_other','kit_id','test_type',
        'test_date','y_haplogroup','mt_haplogroup','raw_data_file','ethnicity_json','notes',
    ];
    protected array $matchFields = [
        'kit_a_id','kit_b_id','kit_b_name','total_cm','longest_segment_cm',
        'predicted_relation','common_ancestor_id','triangulation_group',
        'non_paternity_flag','notes',
    ];
    protected array $segmentFields = [
        'match_id','chromosome','start_pos','end_pos','centimorgans',
        'snp_count','is_imputed','is_phased','side',
    ];

    public function __construct(Arbor $arbor) { $this->arbor = $arbor; }

    /* Kits */
    public function getKit(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_dna_kits WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function kitsForPerson(int $personId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_dna_kits WHERE person_id = :p");
        $stmt->execute([':p' => $personId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function saveKit(array $data, ?int $id = null): int
    {
        return $this->saveGeneric('arbor_dna_kits', $this->kitFields, $data, $id, ['person_id','tree_id'], ['test_date']);
    }

    public function deleteKit(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_dna_kits WHERE id = :id")
            ->execute([':id' => $id]);
    }

    /* Matches */
    public function matchesForKit(int $kitId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_dna_matches WHERE kit_a_id = :k ORDER BY total_cm DESC");
        $stmt->execute([':k' => $kitId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getMatch(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_dna_matches WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveMatch(array $data, ?int $id = null): int
    {
        return $this->saveGeneric('arbor_dna_matches', $this->matchFields, $data, $id, ['kit_a_id','kit_b_id','common_ancestor_id','non_paternity_flag']);
    }

    public function deleteMatch(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_dna_matches WHERE id = :id")
            ->execute([':id' => $id]);
    }

    /* Segments */
    public function segmentsForMatch(int $matchId): array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_dna_segments WHERE match_id = :m ORDER BY chromosome, start_pos");
        $stmt->execute([':m' => $matchId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getSegment(int $id): ?array
    {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT * FROM arbor_dna_segments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveSegment(array $data, ?int $id = null): int
    {
        return $this->saveGeneric('arbor_dna_segments', $this->segmentFields, $data, $id, ['match_id','chromosome','start_pos','end_pos','snp_count','is_imputed','is_phased']);
    }

    public function deleteSegment(int $id): bool
    {
        return $this->wire('database')->prepare("DELETE FROM arbor_dna_segments WHERE id = :id")
            ->execute([':id' => $id]);
    }

    public function importCsv(int $kitId, string $csvPath): int
    {
        if (!is_file($csvPath)) return 0;
        $fh = fopen($csvPath, 'r');
        if (!$fh) return 0;
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$header) { fclose($fh); return 0; }
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
        $count = 0;
        $matchIds = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (count($row) !== count($header)) continue;
            $r = array_combine($header, $row);
            $matchName = trim((string) ($r['match name'] ?? $r['name'] ?? $r['match'] ?? ''));
            if ($matchName === '') continue;
            $key = strtolower($matchName);
            if (!isset($matchIds[$key])) {
                $matchIds[$key] = $this->saveMatch([
                    'kit_a_id' => $kitId,
                    'kit_b_name' => $matchName,
                    'total_cm' => (float) ($r['total cm'] ?? $r['shared cm'] ?? $r['shared dna'] ?? 0),
                    'longest_segment_cm' => (float) ($r['longest segment'] ?? $r['largest segment'] ?? 0),
                    'predicted_relation' => $r['relationship'] ?? $r['predicted relationship'] ?? '',
                    'triangulation_group' => $r['triangulation group'] ?? '',
                ]);
            }
            $matchId = (int) $matchIds[$key];
            $chromosome = $r['chromosome'] ?? $r['chr'] ?? '';
            if ($chromosome !== '') {
                $start = (int) str_replace([',', ' '], '', (string) ($r['start location'] ?? $r['start'] ?? $r['start position'] ?? 0));
                $end = (int) str_replace([',', ' '], '', (string) ($r['end location'] ?? $r['end'] ?? $r['end position'] ?? 0));
                if ($end < $start) [$start, $end] = [$end, $start];
                $side = strtolower((string) ($r['side'] ?? 'unknown'));
                if (!in_array($side, ['maternal', 'paternal', 'unknown'], true)) $side = 'unknown';
                $this->saveSegment([
                    'match_id' => $matchId,
                    'chromosome' => (int) $chromosome,
                    'start_pos' => $start,
                    'end_pos' => $end,
                    'centimorgans' => (float) ($r['centimorgans'] ?? $r['cm'] ?? $r['segment cm'] ?? 0),
                    'snp_count' => (int) ($r['snps'] ?? $r['snp count'] ?? 0),
                    'side' => $side,
                ]);
            }
            $count++;
        }
        fclose($fh);
        return $count;
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
            $defaults = [
                'total_cm' => 0,
                'longest_segment_cm' => 0,
                'non_paternity_flag' => 0,
                'start_pos' => 0,
                'end_pos' => 0,
                'centimorgans' => 0,
                'snp_count' => 0,
                'is_imputed' => 0,
                'is_phased' => 0,
                'side' => 'unknown',
            ];
            $v = $data[$f] ?? ($defaults[$f] ?? null);
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
