<?php namespace ProcessWire;

/** @var array $tree */
/** @var array $place */
/** @var int $id */
/** @var array $names */
/** @var array $jurisdictions */
/** @var array $all_places */
/** @var string $baseUrl */

$types = [
    'country' => 'Country',
    'region' => 'Region',
    'gubernia' => 'Gubernia',
    'oblast' => 'Oblast',
    'district' => 'District',
    'uyezd' => 'Uyezd',
    'raion' => 'Raion',
    'city' => 'City',
    'town' => 'Town',
    'shtetl' => 'Shtetl',
    'village' => 'Village',
    'street' => 'Street',
    'cemetery' => 'Cemetery',
    'hospital' => 'Hospital',
    'synagogue' => 'Synagogue',
    'church' => 'Church',
    'mosque' => 'Mosque',
    'ghetto' => 'Ghetto',
    'camp' => 'Camp',
    'other' => 'Other',
];
?>
<div class="pw-wrap Arbor">

<div class="arbor-toolbar">
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>places/?tree=<?= $tree['id'] ?>">
        <span uk-icon="icon: arrow-left"></span> All places
    </a>
</div>

<form class="InputfieldForm" method="post">
<?= $csrfInput ?>

<section class="arbor-person-create">
    <div class="arbor-create-main">
        <div class="arbor-create-card">
            <div class="arbor-create-head">
                <span uk-icon="icon: location"></span>
                <div>
                    <h3><?= $id ? 'Edit place' : 'Add place' ?></h3>
                    <p>Use places for birth, marriage, death, burial, documents, and research notes.</p>
                </div>
            </div>

            <div class="arbor-simple-grid">
                <label>
                    <span>Place name <b>*</b></span>
                    <input id="pl_canonical" class="uk-input" type="text" name="canonical_name" value="<?= htmlspecialchars($place['canonical_name'] ?? '') ?>" required>
                </label>
                <label>
                    <span>Type</span>
                    <select id="pl_type" class="uk-select" name="place_type">
                        <?php foreach ($types as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($place['place_type'] ?? 'other') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="arbor-simple-grid">
                <label>
                    <span>Inside this place</span>
                    <select id="pl_parent" class="uk-select" name="parent_id">
                        <option value="">No parent place</option>
                        <?php foreach ($all_places as $pp): if ((int) $pp['id'] === (int) ($place['id'] ?? 0)) continue; ?>
                            <option value="<?= $pp['id'] ?>" <?= ($place['parent_id'] ?? '') == $pp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pp['canonical_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Wikipedia URL</span>
                    <input id="pl_wiki" class="uk-input" type="url" name="wikipedia_url" value="<?= htmlspecialchars($place['wikipedia_url'] ?? '') ?>">
                </label>
            </div>

            <details class="arbor-create-more">
                <summary>Map and notes</summary>
                <div class="arbor-simple-grid">
                    <label>
                        <span>Latitude</span>
                        <input id="pl_lat" class="uk-input" type="text" name="latitude" value="<?= htmlspecialchars((string)($place['latitude'] ?? '')) ?>">
                    </label>
                    <label>
                        <span>Longitude</span>
                        <input id="pl_lon" class="uk-input" type="text" name="longitude" value="<?= htmlspecialchars((string)($place['longitude'] ?? '')) ?>">
                    </label>
                    <label>
                        <span>Map database ID</span>
                        <input id="pl_geonames" class="uk-input" type="text" name="geonames_id" value="<?= htmlspecialchars($place['geonames_id'] ?? '') ?>">
                    </label>
                </div>
                <label class="arbor-simple-full">
                    <span>Notes</span>
                    <textarea id="pl_notes" class="uk-textarea" name="notes" rows="4"><?= htmlspecialchars((string)($place['notes'] ?? '')) ?></textarea>
                </label>
            </details>

            <?php if ($id): ?>
                <details class="arbor-create-more">
                    <summary>Historical names and regions</summary>

                    <h4>Other place names</h4>
                    <?php if (empty($names)): ?>
                        <p class="uk-text-muted uk-text-small">No other place names yet.</p>
                    <?php else: ?>
                        <table class="uk-table uk-table-small">
                            <thead><tr><th>Name</th><th>Language</th><th>Script</th><th>Kind</th><th>From</th><th>To</th></tr></thead>
                            <tbody>
                            <?php foreach ($names as $n): ?>
                                <tr>
                                    <td><?= htmlspecialchars($n['name']) ?></td>
                                    <td><?= htmlspecialchars($n['language']) ?></td>
                                    <td><?= htmlspecialchars($n['script']) ?></td>
                                    <td><?= htmlspecialchars($n['name_type']) ?></td>
                                    <td class="uk-text-muted"><?= htmlspecialchars((string)$n['date_from']) ?></td>
                                    <td class="uk-text-muted"><?= htmlspecialchars((string)$n['date_to']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h4>Historical regions</h4>
                    <?php if (empty($jurisdictions)): ?>
                        <p class="uk-text-muted uk-text-small">No historical region entries yet.</p>
                    <?php else: ?>
                        <table class="uk-table uk-table-small">
                            <thead><tr><th>Country</th><th>Region</th><th>From</th><th>To</th></tr></thead>
                            <tbody>
                            <?php foreach ($jurisdictions as $j): ?>
                                <tr>
                                    <td><?= htmlspecialchars($j['country']) ?></td>
                                    <td><?= htmlspecialchars($j['region']) ?></td>
                                    <td class="uk-text-muted"><?= htmlspecialchars((string)$j['date_from']) ?></td>
                                    <td class="uk-text-muted"><?= htmlspecialchars((string)$j['date_to']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="arbor-form-actions">
    <button type="submit" name="save" value="1" class="uk-button uk-button-primary">
        <span uk-icon="icon: check"></span> Save place
    </button>
    <a class="uk-button uk-button-text" href="<?= $baseUrl ?>places/?tree=<?= $tree['id'] ?>">Cancel</a>
</div>
</form>
</div>
