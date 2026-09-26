<?php

$source = file_get_contents(__DIR__ . '/../ProcessArbor.module.php');

if ($source === false) {
    fwrite(STDERR, "Unable to read ProcessArbor.module.php\n");
    exit(1);
}

if (str_contains($source, '<=>')) {
    fwrite(STDERR, "MySQL-only null-safe equality remains in ProcessArbor.module.php\n");
    exit(1);
}

if (!str_contains($source, 'document_id = :document OR (document_id IS NULL AND :document_null IS NULL)')) {
    fwrite(STDERR, "Portable citation duplicate comparison is missing\n");
    exit(1);
}

foreach (["SUM(status =", 'SUM(status NOT IN', 'SUM(u.partner1_id IS', "SUM(uc.pedigree ="] as $mysqlAggregate) {
    if (str_contains($source, $mysqlAggregate)) {
        fwrite(STDERR, "PostgreSQL-incompatible boolean SUM remains in ProcessArbor.module.php\n");
        exit(1);
    }
}

if (substr_count($source, 'SUM(CASE WHEN') < 7) {
    fwrite(STDERR, "Portable conditional aggregates are missing\n");
    exit(1);
}

echo "Arbor SQL compatibility checks passed\n";
