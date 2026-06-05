<?php namespace ProcessWire;

/** @var array $tree */
/** @var array $source */
/** @var int $id */
/** @var array $repos */
/** @var array $citations */
/** @var string $baseUrl */

$sourceTypes = [
    'book' => 'Book',
    'journal' => 'Journal',
    'newspaper' => 'Newspaper',
    'vital_record' => 'Birth, marriage, or death record',
    'census' => 'Census',
    'metrical_book' => 'Metrical book',
    'revision_list' => 'Revision list',
    'manuscript' => 'Manuscript',
    'website' => 'Website',
    'database' => 'Online database',
    'dna_test' => 'DNA test',
    'oral_interview' => 'Interview',
    'photograph' => 'Photograph',
    'artifact' => 'Object or artifact',
    'other' => 'Other',
];
$mediaTypes = [
    'AUDIO' => 'Audio',
    'BOOK' => 'Book',
    'CARD' => 'Card',
    'ELECTRONIC' => 'Digital file',
    'FICHE' => 'Microfiche',
    'FILM' => 'Microfilm',
    'MAGAZINE' => 'Magazine',
    'MANUSCRIPT' => 'Manuscript',
    'MAP' => 'Map',
    'NEWSPAPER' => 'Newspaper',
    'PHOTO' => 'Photo',
    'TOMBSTONE' => 'Tombstone',
    'VIDEO' => 'Video',
    'OTHER' => 'Other',
];
?>
<div class="pw-wrap Arbor">

<div class="arbor-toolbar">
    <a class="uk-button uk-button-default" href="<?= $baseUrl ?>sources/?tree=<?= $tree['id'] ?>">
        <span uk-icon="icon: arrow-left"></span> All sources
    </a>
</div>

<form class="InputfieldForm" method="post">
<?= $csrfInput ?>

<section class="arbor-person-create">
    <div class="arbor-create-main">
        <div class="arbor-create-card">
            <div class="arbor-create-head">
                <span uk-icon="icon: file-text"></span>
                <div>
                    <h3><?= $id ? 'Edit source' : 'Add source' ?></h3>
                    <p>Save where a fact came from: a document, book, website, photo, interview, or database.</p>
                </div>
            </div>

            <label class="arbor-simple-full">
                <span>Title <b>*</b></span>
                <input id="s_title" class="uk-input" type="text" name="title" value="<?= htmlspecialchars($source['title'] ?? '') ?>" required>
            </label>

            <div class="arbor-simple-grid">
                <label>
                    <span>Kind of source</span>
                    <select id="s_type" class="uk-select" name="source_type">
                        <?php foreach ($sourceTypes as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($source['source_type'] ?? 'other') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Format</span>
                    <select id="s_media" class="uk-select" name="media_type">
                        <?php foreach ($mediaTypes as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($source['media_type'] ?? 'OTHER') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="arbor-simple-grid">
                <label>
                    <span>Author or creator</span>
                    <input id="s_author" class="uk-input" type="text" name="author" value="<?= htmlspecialchars($source['author'] ?? '') ?>">
                </label>
                <label>
                    <span>Date</span>
                    <input id="s_pubdate" class="uk-input" type="text" name="pub_date" value="<?= htmlspecialchars($source['pub_date'] ?? '') ?>">
                </label>
            </div>

            <div class="arbor-simple-grid">
                <label>
                    <span>Where it is kept</span>
                    <select id="s_repo" class="uk-select" name="repo_id">
                        <option value="">Not set</option>
                        <?php foreach ($repos as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= ($source['repo_id'] ?? '') == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>URL</span>
                    <input id="s_url" class="uk-input" type="url" name="url" value="<?= htmlspecialchars($source['url'] ?? '') ?>">
                </label>
            </div>

            <details class="arbor-create-more">
                <summary>Publication details</summary>
                <div class="arbor-simple-grid">
                    <label>
                        <span>Publisher</span>
                        <input id="s_pub" class="uk-input" type="text" name="publisher" value="<?= htmlspecialchars($source['publisher'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Place</span>
                        <input id="s_place" class="uk-input" type="text" name="pub_place" value="<?= htmlspecialchars($source['pub_place'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Edition</span>
                        <input id="s_edition" class="uk-input" type="text" name="edition" value="<?= htmlspecialchars($source['edition'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Volume</span>
                        <input id="s_volume" class="uk-input" type="text" name="volume" value="<?= htmlspecialchars($source['volume'] ?? '') ?>">
                    </label>
                    <label>
                        <span>ISBN</span>
                        <input id="s_isbn" class="uk-input" type="text" name="isbn" value="<?= htmlspecialchars($source['isbn'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Language</span>
                        <input id="s_lang" class="uk-input" type="text" name="language" value="<?= htmlspecialchars($source['language'] ?? '') ?>">
                    </label>
                </div>
            </details>

            <details class="arbor-create-more">
                <summary>Archive details</summary>
                <div class="arbor-simple-grid">
                    <label>
                        <span>Archive name</span>
                        <input id="s_arch_name" class="uk-input" type="text" name="archive_name" value="<?= htmlspecialchars($source['archive_name'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Archive short name</span>
                        <input id="s_arch_abbr" class="uk-input" type="text" name="archive_abbrev" value="<?= htmlspecialchars($source['archive_abbrev'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Collection number</span>
                        <input id="s_fond" class="uk-input" type="text" name="fond" value="<?= htmlspecialchars($source['fond'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Collection title</span>
                        <input id="s_fond_title" class="uk-input" type="text" name="fond_title" value="<?= htmlspecialchars($source['fond_title'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Series</span>
                        <input id="s_opis" class="uk-input" type="text" name="opis" value="<?= htmlspecialchars($source['opis'] ?? '') ?>">
                    </label>
                    <label>
                        <span>File</span>
                        <input id="s_delo" class="uk-input" type="text" name="delo" value="<?= htmlspecialchars($source['delo'] ?? '') ?>">
                    </label>
                    <label>
                        <span>File title</span>
                        <input id="s_delo_title" class="uk-input" type="text" name="delo_title" value="<?= htmlspecialchars($source['delo_title'] ?? '') ?>">
                    </label>
                    <label>
                        <span>Microfilm reel</span>
                        <input id="s_film" class="uk-input" type="text" name="microfilm_reel" value="<?= htmlspecialchars($source['microfilm_reel'] ?? '') ?>">
                    </label>
                </div>
                <label class="arbor-simple-full">
                    <span>Digital copy URL</span>
                    <input id="s_digital" class="uk-input" type="url" name="digital_url" value="<?= htmlspecialchars($source['digital_url'] ?? '') ?>">
                </label>
            </details>

            <details class="arbor-create-more">
                <summary>Notes and transcript</summary>
                <label class="arbor-simple-full">
                    <span>Citation style</span>
                    <input id="s_ee_tpl" class="uk-input" type="text" name="ee_template" value="<?= htmlspecialchars($source['ee_template'] ?? '') ?>">
                </label>
                <label class="arbor-simple-full">
                    <span>Formatted citation</span>
                    <textarea id="s_ee_cit" class="uk-textarea" name="ee_citation" rows="3"><?= htmlspecialchars($source['ee_citation'] ?? '') ?></textarea>
                </label>
                <label class="arbor-simple-full">
                    <span>Short summary</span>
                    <textarea id="s_abstract" class="uk-textarea" name="abstract" rows="3"><?= htmlspecialchars($source['abstract'] ?? '') ?></textarea>
                </label>
                <label class="arbor-simple-full">
                    <span>Transcript</span>
                    <textarea id="s_fulltext" class="uk-textarea" name="full_text" rows="6"><?= htmlspecialchars($source['full_text'] ?? '') ?></textarea>
                </label>
                <label class="arbor-simple-full">
                    <span>Translation</span>
                    <textarea id="s_trans" class="uk-textarea" name="translation" rows="6"><?= htmlspecialchars($source['translation'] ?? '') ?></textarea>
                </label>
                <label class="arbor-simple-full">
                    <span>Notes</span>
                    <textarea id="s_notes" class="uk-textarea" name="notes" rows="3"><?= htmlspecialchars($source['notes'] ?? '') ?></textarea>
                </label>
            </details>
        </div>

        <?php if ($id): ?>
            <div class="arbor-create-card">
                <div class="arbor-create-head">
                    <span uk-icon="icon: link"></span>
                    <div>
                        <h3>Facts using this source</h3>
                        <p>People and events currently linked to this source.</p>
                    </div>
                </div>

                <?php if (!empty($citations)): ?>
                    <div class="arbor-list">
                        <?php foreach ($citations as $c):
                            $personName = trim((string) ($c['given'] ?? '') . ' ' . (string) ($c['surname'] ?? '')) ?: 'Person';
                            $event = trim(ucfirst((string) ($c['event_type'] ?? 'fact')));
                            $meta = array_filter([
                                $event,
                                $c['event_date'] ?? null,
                                $c['event_place_str'] ?? null,
                                $c['page_ref'] ?? null,
                            ]);
                            $documentLinks = [];
                            if (!empty($c['document_url'])) {
                                $documentLinks[] = '<a class="uk-link-muted" href="' . htmlspecialchars($c['document_url']) . '" target="_blank" rel="noopener">open URL</a>';
                            }
                            if (!empty($c['document_file_url'])) {
                                $documentLinks[] = '<a class="uk-link-muted" href="' . htmlspecialchars($c['document_file_url']) . '" target="_blank" rel="noopener">open file</a>';
                            }
                            $documentTitle = trim((string) ($c['document_title'] ?? ''));
                        ?>
                            <div class="arbor-list-row">
                                <span class="arbor-list-main">
                                    <?= htmlspecialchars($personName) ?>
                                    <?php if ($documentTitle): ?>
                                        <br><span class="uk-text-meta"><span uk-icon="icon: copy"></span> <?= htmlspecialchars($documentTitle) ?> <?= $documentLinks ? ' · ' . implode(' · ', $documentLinks) : '' ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="arbor-list-meta"><?= htmlspecialchars(implode(' · ', $meta)) ?></span>
                                <button class="uk-button uk-button-text uk-text-danger" type="submit" name="delete_citation" value="<?= (int) $c['id'] ?>" onclick="return confirm('Remove this evidence link?')" title="Remove evidence link">
                                    <span uk-icon="icon: trash"></span>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="arbor-empty-mini">
                        <span uk-icon="icon: link"></span>
                        <p>No facts are linked to this source yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="arbor-form-actions">
    <button type="submit" name="save" value="1" class="uk-button uk-button-primary">
        <span uk-icon="icon: check"></span> Save source
    </button>
    <a class="uk-button uk-button-text" href="<?= $baseUrl ?>sources/?tree=<?= $tree['id'] ?>">Cancel</a>
</div>
</form>
</div>
