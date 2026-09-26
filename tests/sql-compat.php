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

echo "Arbor SQL compatibility checks passed\n";
