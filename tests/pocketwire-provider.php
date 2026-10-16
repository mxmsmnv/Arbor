<?php
$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Arbor.module.php');
$trait = (string)file_get_contents($root . '/src/ArborPocketWireProviderTrait.php');
$read = strstr($trait, 'public function pocketWireRead');
$read = $read === false ? '' : (strstr($read, 'private function pocketWireCanView', true) ?: '');
$checks = [
    str_contains($module, "'pocketWireProvider' => true"),
    str_contains($trait, 'pocketWireProviderInfo()'),
    str_contains($trait, 'pocketWireManifest(User $actor)'),
    str_contains($trait, 'pocketWireRead(string $resource, string $identifier, array $query, User $actor)'),
    str_contains($trait, "wire('user')") && str_contains($trait, "hasPermission('arbor-view')"),
    str_contains($trait, "'id' => 'status'") && str_contains($trait, "'actions' => []"),
    str_contains($trait, "'layout' => 'dashboard'") && str_contains($trait, "'layout' => 'metrics'"),
    str_contains($trait, "'living_person_data_exposed' => false") && str_contains($trait, "'write_tools' => false"),
    $read !== '' && !preg_match('/model\(|database|arbor_|persons|trees|photos|documents|dna/i', $read),
];
if(in_array(false, $checks, true)) { fwrite(STDERR, "Arbor PocketWire provider contract failed.\n"); exit(1); }
echo 'Arbor PocketWire provider contract passed: ' . count($checks) . " checks.\n";
