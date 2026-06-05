/* Arbor admin JS: row add/remove for repeating tables, AI buttons,
 * conditional visibility of death fields based on the "Living" checkbox. */

document.addEventListener('DOMContentLoaded', function () {
    const livingBox = document.getElementById('p_is_alive');
    if (!livingBox) return;
    const deathFields = document.querySelectorAll('.arbor-death-field');
    function sync() {
        const alive = livingBox.checked;
        deathFields.forEach(el => { el.style.display = alive ? 'none' : ''; });
    }
    livingBox.addEventListener('change', sync);
    sync();
});

document.addEventListener('DOMContentLoaded', function () {
    const parent1 = document.getElementById('u_partner1');
    const parent2 = document.getElementById('u_partner2');
    if (!parent1 || !parent2) return;

    const warning = document.querySelector('.arbor-union-client-warning');
    const warningText = warning ? warning.querySelector('span:last-child') : null;
    const saveButton = document.querySelector('button[name="save"][data-requires]');
    const saveHint = document.querySelector('.arbor-save-hint');

    function showWarning(message) {
        if (!warning || !warningText) return;
        warningText.textContent = message;
        warning.hidden = false;
    }

    function hideWarning() {
        if (warning) warning.hidden = true;
    }

    function selectedChildValue() {
        const fixedChild = document.querySelector('input[type="hidden"][name="children[0][person_id]"]');
        if (fixedChild && fixedChild.value) return fixedChild.value;
        const childSelect = document.querySelector('select[name^="children"][name$="[person_id]"]');
        return childSelect ? childSelect.value : '';
    }

    function updateReturnLinks() {
        document.querySelectorAll('.arbor-field-link[data-return-role]').forEach(link => {
            const role = link.dataset.returnRole;
            const url = new URL(link.getAttribute('href'), window.location.origin);
            const first = parent1.value;
            const second = parent2.value;
            const child = selectedChildValue();

            if (first && role !== 'partner1') url.searchParams.set('partner1', first);
            else if (role !== 'partner1') url.searchParams.delete('partner1');

            if (second && role !== 'partner2') url.searchParams.set('partner2', second);
            else if (role !== 'partner2') url.searchParams.delete('partner2');

            if (child && role !== 'child' && url.searchParams.has('add_child')) url.searchParams.set('child', child);
            else if (role !== 'child' && url.searchParams.has('add_child')) url.searchParams.delete('child');

            link.setAttribute('href', url.pathname + url.search);
        });
    }

    function syncSaveRequirement() {
        if (!saveButton) return;
        let canSave = true;

        if (saveButton.dataset.requires === 'parent') {
            canSave = !!(parent1.value || parent2.value);
        } else if (saveButton.dataset.requires === 'child') {
            canSave = !!selectedChildValue();
        }

        saveButton.disabled = !canSave;
        if (saveHint) saveHint.hidden = canSave;
    }

    function disableSelectedInOtherSelect() {
        const first = parent1.value;
        const second = parent2.value;

        parent1.querySelectorAll('option').forEach(option => {
            option.disabled = !!second && option.value === second;
        });
        parent2.querySelectorAll('option').forEach(option => {
            option.disabled = !!first && option.value === first;
        });
    }

    function syncParents(changed) {
        if (parent1.value && parent1.value === parent2.value) {
            if (changed === parent1) parent2.value = '';
            else parent1.value = '';
            showWarning('Choose two different people for the parents.');
        } else {
            hideWarning();
        }
        disableSelectedInOtherSelect();
        updateReturnLinks();
        syncSaveRequirement();
    }

    parent1.addEventListener('change', function () { syncParents(parent1); });
    parent2.addEventListener('change', function () { syncParents(parent2); });
    document.addEventListener('change', function (event) {
        if (event.target.matches('select[name$="[person_id]"]')) {
            updateReturnLinks();
            syncSaveRequirement();
        }
    });
    syncParents(null);
    syncSaveRequirement();
});

document.addEventListener('click', function (e) {
    /* ----- add row -----
     * Preferred: data-template attribute holds the HTML for a fresh row with
     * `__i__` as the index placeholder. This avoids clone-and-clear bugs where
     * the cloned row inherited values from the previous one.
     * Fallback: clone the last row in the tbody and clear its inputs.
     */
    if (e.target.closest('.arbor-add-row')) {
        const btn = e.target.closest('.arbor-add-row');
        e.preventDefault();
        const tbody = document.querySelector(btn.dataset.target);
        if (!tbody) return;

        // remove empty-state placeholder row if present
        const placeholder = tbody.querySelector('.arbor-empty-row');
        if (placeholder) placeholder.remove();

        const idx = tbody.querySelectorAll('tr').length;
        const tpl = btn.dataset.template;

        if (tpl) {
            const html = tpl.replace(/__i__/g, idx);
            tbody.insertAdjacentHTML('beforeend', html);
        } else {
            const rows = tbody.querySelectorAll('tr');
            const last = rows[rows.length - 1];
            if (!last) return;
            const clone = last.cloneNode(true);
            clone.querySelectorAll('input,select,textarea').forEach(el => {
                if (el.name) el.name = el.name.replace(/\[(\d+)\]/, '[' + idx + ']');
                if (el.type === 'checkbox') el.checked = false;
                else if (el.tagName === 'SELECT') el.selectedIndex = 0;
                else el.value = '';
            });
            tbody.appendChild(clone);
        }

        // focus the first input in the newly added row
        const newRow = tbody.lastElementChild;
        if (newRow) {
            const focusable = newRow.querySelector('input[type="text"], select, textarea');
            if (focusable) focusable.focus();
        }
    }

    /* ----- AI buttons (require AiWire + aiEnabled) ----- */
    if (e.target.closest('.arbor-ai-context')) {
        const btn = e.target.closest('.arbor-ai-context');
        const id = btn.dataset.id;
        btn.disabled = true;
        fetch('/api/arbor/persons/' + id + '/ai/context/', { method: 'POST' })
            .then(r => r.json())
            .then(data => alert(data.context || 'No suggestion'))
            .finally(() => btn.disabled = false);
    }

    if (e.target.closest('.arbor-ai-duplicates')) {
        const btn = e.target.closest('.arbor-ai-duplicates');
        const id = btn.dataset.id;
        btn.disabled = true;
        fetch('/api/arbor/persons/' + id + '/ai/duplicates/', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (!Array.isArray(data) || !data.length) { alert('No likely duplicates'); return; }
                const msg = data.map(d => `#${d.person_id} · ${(d.similarity * 100).toFixed(0)}% · ${d.reason}`).join('\n');
                alert(msg);
            })
            .finally(() => btn.disabled = false);
    }
});
