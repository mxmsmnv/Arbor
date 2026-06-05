<?php namespace ProcessWire;

/** @var array $tree */
/** @var array $repo */
/** @var int $id */
/** @var string $baseUrl */
?>
<div class="pw-wrap Arbor">

<div class="arbor-toolbar">
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>repos/?tree=<?= $tree['id'] ?>">
        <span uk-icon="icon: arrow-left"></span> All archives
    </a>
</div>

<form class="InputfieldForm" method="post">
<?= $csrfInput ?>

<section class="arbor-person-create">
    <div class="arbor-create-main">
        <div class="arbor-create-card">
            <div class="arbor-create-head">
                <span uk-icon="icon: album"></span>
                <div>
                    <h3><?= $id ? 'Edit archive or website' : 'Add archive or website' ?></h3>
                    <p>Save archives, libraries, websites, and databases where sources are kept.</p>
                </div>
            </div>

            <div class="arbor-simple-grid">
                <label>
                    <span>Name <b>*</b></span>
                    <input id="r_name" class="uk-input" type="text" name="name" value="<?= htmlspecialchars($repo['name'] ?? '') ?>" required>
                </label>
                <label>
                    <span>Short name</span>
                    <input id="r_abbr" class="uk-input" type="text" name="abbreviation" value="<?= htmlspecialchars($repo['abbreviation'] ?? '') ?>" placeholder="RGIA, GARF">
                </label>
            </div>

            <div class="arbor-simple-grid">
                <label>
                    <span>City</span>
                    <input id="r_city" class="uk-input" type="text" name="city" value="<?= htmlspecialchars($repo['city'] ?? '') ?>">
                </label>
                <label>
                    <span>Country</span>
                    <input id="r_country" class="uk-input" type="text" name="country" value="<?= htmlspecialchars($repo['country'] ?? '') ?>">
                </label>
                <label>
                    <span>Website</span>
                    <input id="r_web" class="uk-input" type="url" name="website" value="<?= htmlspecialchars($repo['website'] ?? '') ?>">
                </label>
            </div>

            <details class="arbor-create-more">
                <summary>Contact and access details</summary>
                <div class="arbor-simple-grid">
                    <label>
                        <span>Name in local language</span>
                        <input id="r_orig" class="uk-input" type="text" name="name_original" value="<?= htmlspecialchars($repo['name_original'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Opening hours</span>
                        <input id="r_hours" class="uk-input" type="text" name="hours" value="<?= htmlspecialchars($repo['hours'] ?? '') ?>">
                    </label>
                </div>
                <label class="arbor-simple-full">
                    <span>Address</span>
                    <textarea id="r_addr" class="uk-textarea" name="address" rows="3"><?= htmlspecialchars($repo['address'] ?? '') ?></textarea>
                </label>
                <label class="arbor-simple-full">
                    <span>Catalog links</span>
                    <textarea id="r_finding" class="uk-textarea" name="finding_aids" rows="3"><?= htmlspecialchars($repo['finding_aids'] ?? '') ?></textarea>
                </label>
                <label class="arbor-simple-full">
                    <span>How to access records</span>
                    <textarea id="r_policy" class="uk-textarea" name="access_policy" rows="3"><?= htmlspecialchars($repo['access_policy'] ?? '') ?></textarea>
                </label>
                <label class="arbor-simple-full">
                    <span>Notes</span>
                    <textarea id="r_notes" class="uk-textarea" name="notes" rows="3"><?= htmlspecialchars($repo['notes'] ?? '') ?></textarea>
                </label>
            </details>
        </div>
    </div>
</section>

<div class="arbor-form-actions">
    <button type="submit" name="save" value="1" class="uk-button uk-button-primary">
        <span uk-icon="icon: check"></span> Save archive
    </button>
    <a class="uk-button uk-button-text" href="<?= $baseUrl ?>repos/?tree=<?= $tree['id'] ?>">Cancel</a>
</div>
</form>
</div>
