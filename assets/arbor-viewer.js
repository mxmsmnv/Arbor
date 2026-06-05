/* Arbor interactive tree viewer (D3 force layout).
 *
 * Reads the graph from /api/arbor/trees/{id}/graph/ and renders nodes
 * representing people and edges representing parent/child + spouse relations.
 * Click a node to show a small details panel; double-click opens the profile.
 * If the tree has no persons, an empty-state overlay invites the user to add
 * the first person or import a family file.
 */

(function () {
    const container = document.getElementById('arbor-viewer');
    if (!container) return;
    const svg = d3.select('#arbor-viewer-svg');
    const empty = container.querySelector('.arbor-viewer-empty');
    const details = document.getElementById('arbor-viewer-person');
    const note = document.getElementById('arbor-viewer-note');
    const searchInput = document.getElementById('arbor-viewer-search');
    const searchList = document.getElementById('arbor-viewer-search-list');
    const searchStatus = document.getElementById('arbor-viewer-search-status');
    const showMainButton = document.getElementById('arbor-show-main');
    const showAllButton = document.getElementById('arbor-show-all');
    const directionSelect = document.getElementById('arbor-direction');
    const agendaMain = document.getElementById('arbor-viewer-agenda-main');
    const agendaSelected = document.getElementById('arbor-viewer-agenda-selected');
    const agendaActions = document.getElementById('arbor-viewer-agenda-actions');
    const setMainForms = document.querySelectorAll('.arbor-viewer-set-main');
    const width = container.clientWidth;
    const height = +svg.attr('height') || 600;
    const rootPersonId = parseInt(container.dataset.rootPersonId || '0', 10) || null;
    const prefsKey = 'arbor.viewer.' + (container.dataset.treeId || 'default');

    const g = svg.append('g');
    const zoom = d3.zoom().scaleExtent([0.1, 4]).on('zoom', e => g.attr('transform', e.transform));
    svg.call(zoom);

    let showLiving = true;
    let selectedId = null;
    let lastNodes = [];
    const livingToggle = document.getElementById('arbor-toggle-living');
    const generationInput = document.getElementById('arbor-gen-filter');
    restoreViewerPrefs();
    if (livingToggle) {
        livingToggle.addEventListener('change', e => {
            showLiving = e.target.checked;
            saveViewerPrefs();
            render();
        });
    }
    if (generationInput) {
        generationInput.addEventListener('change', () => { saveViewerPrefs(); render(); });
        generationInput.addEventListener('input', () => { saveViewerPrefs(); render(); });
    }
    if (directionSelect) {
        directionSelect.addEventListener('change', () => { saveViewerPrefs(); render(); });
    }
    if (searchInput) {
        const runSearch = () => {
            const q = searchInput.value.trim();
            if (!q) {
                setSearchStatus('');
                return;
            }
            const { match, count } = searchMatch(q);
            setSearchStatus(count ? (count === 1 ? '1 match' : count + ' matches') : 'No match');
            if (match) {
                selectPersonById(match.id, { keepSearch: true });
            }
        };
        searchInput.addEventListener('input', runSearch);
        searchInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                runSearch();
            } else if (e.key === 'Escape') {
                searchInput.value = '';
                setSearchStatus('');
                selectPersonById(rootPersonId);
            }
        });
    }

    document.querySelectorAll('[data-zoom]').forEach(btn => {
        btn.addEventListener('click', () => {
            const k = btn.dataset.zoom;
            if (k === 'in') svg.transition().call(zoom.scaleBy, 1.3);
            else if (k === 'out') svg.transition().call(zoom.scaleBy, 0.77);
            else if (k === 'fit') fitGraph();
        });
    });
    if (showMainButton) {
        showMainButton.addEventListener('click', () => selectPersonById(rootPersonId));
    }
    if (showAllButton) {
        showAllButton.addEventListener('click', showAll);
    }

    let graph = { nodes: [], edges: [] };

    fetch(container.dataset.graphUrl, { headers: { 'Accept': 'application/json' } })
        .then(r => r.text())
        .then(text => {
            try { graph = JSON.parse(text); }
            catch (e) { console.error('Arbor: viewer graph payload is not JSON', text.slice(0, 200)); graph = { nodes: [], edges: [] }; }
            if (rootPersonId && (graph.nodes || []).some(n => n.id === rootPersonId)) {
                selectedId = rootPersonId;
            }
            populateSearch();
            render();
        })
        .catch(err => { console.error('Arbor: viewer graph fetch failed', err); render(); });

    if (details) {
        const close = details.querySelector('.arbor-viewer-person-close');
        if (close) close.addEventListener('click', showAll);
    }

    function render() {
        g.selectAll('*').remove();
        const allNodes = graph.nodes ? graph.nodes.filter(n => showLiving || !n.is_alive) : [];
        const nodes = filterByGeneration(allNodes);
        lastNodes = nodes;

        if (empty) empty.hidden = nodes.length > 0;
        if (!nodes.some(n => n.id === selectedId)) {
            selectedId = null;
            if (details) details.hidden = true;
        }
        if (!nodes.length) {
            updateAgenda(null);
            return;
        }

        const ids = new Set(nodes.map(n => n.id));
        const links = (graph.edges || [])
            .filter(e => ids.has(e.from) && ids.has(e.to))
            .map(e => ({ source: e.from, target: e.to, type: e.type }));
        const relatedIds = selectedId ? immediateRelatedIds(selectedId) : new Set();
        const generationMap = selectedId ? buildGenerationMap(selectedId, ids) : new Map();
        const pathIds = selectedId && rootPersonId ? new Set(findPath(rootPersonId, selectedId)) : new Set();
        const pathEdgeKeys = pathEdges(pathIds);
        if (note) {
            if (nodes.length === 1 && !links.length) {
                note.textContent = 'Add a family to connect this person with parents, partners, or children.';
                note.hidden = false;
            } else if (selectedId && nodes.length === 1) {
                note.textContent = 'No relatives are connected to this person yet.';
                note.hidden = false;
            } else {
                note.hidden = true;
            }
        }

        const sim = d3.forceSimulation(nodes)
            .force('link', d3.forceLink(links).id(d => d.id).distance(120))
            .force('charge', d3.forceManyBody().strength(-420))
            .force('collide', d3.forceCollide().radius(68).strength(0.9))
            .force('x', d3.forceX(width / 2).strength(0.05))
            .force('y', d3.forceY(d => generationY(d, generationMap)).strength(selectedId ? 0.32 : 0.08))
            .force('center', d3.forceCenter(width / 2, height / 2))
            .on('end', fitGraph);

        const link = g.append('g')
            .selectAll('line').data(links).enter().append('line')
            .attr('class', d => {
                const sourceId = edgeId(d.source);
                const targetId = edgeId(d.target);
                return [
                    'link',
                    d.type === 'spouse' ? 'spouse' : '',
                    pathEdgeKeys.has(edgeKey(sourceId, targetId)) ? 'path' : '',
                    selectedId && (sourceId === selectedId || targetId === selectedId) ? 'active' : '',
                ].filter(Boolean).join(' ');
            });

        const node = g.append('g')
            .selectAll('g').data(nodes).enter().append('g')
            .attr('class', d => [
                'node',
                d.id === selectedId ? 'selected' : '',
                d.id === rootPersonId ? 'root' : '',
                relatedIds.has(d.id) ? 'related' : '',
                pathIds.has(d.id) ? 'path' : '',
            ].filter(Boolean).join(' '))
            .call(d3.drag()
                .on('start', (e, d) => { if (!e.active) sim.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; })
                .on('drag',  (e, d) => { d.fx = e.x; d.fy = e.y; })
                .on('end',   (e, d) => { if (!e.active) sim.alphaTarget(0); d.fx = null; d.fy = null; }));

        const openDetails = function (e, d) {
            selectedId = d.id;
            if (searchInput) searchInput.value = '';
            setSearchStatus('');
            render();
        };

        node.append('circle').attr('r', 20)
            .on('click', openDetails)
            .attr('class', d => d.sex === 'M' ? 'male' : (d.sex === 'F' ? 'female' : 'unknown'));
        node.append('text').attr('class', 'name').attr('y', 35).attr('text-anchor', 'middle')
            .on('click', openDetails)
            .text(d => d.name);
        node.append('text').attr('class', 'years').attr('y', 50).attr('text-anchor', 'middle')
            .on('click', openDetails)
            .text(d => d.years || '');

        node.on('click', openDetails);
        node.on('dblclick', (e, d) => { window.location.href = '../person/?id=' + d.id; });

        const selected = selectedId ? nodes.find(n => n.id === selectedId) : null;
        if (selected) showDetails(selected);
        updateAgenda(selected);

        sim.on('tick', () => {
            link.attr('x1', d => d.source.x).attr('y1', d => d.source.y)
                .attr('x2', d => d.target.x).attr('y2', d => d.target.y);
            node.attr('transform', d => `translate(${d.x},${d.y})`);
        });
    }

    function populateSearch() {
        if (!searchList) return;
        searchList.innerHTML = '';
        (graph.nodes || [])
            .slice()
            .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
            .forEach(person => {
                const option = document.createElement('option');
                option.value = person.name || ('#' + person.id);
                searchList.appendChild(option);
            });
    }

    function searchMatch(query) {
        const q = query.trim().toLowerCase();
        const searchable = (graph.nodes || []).filter(n => showLiving || !n.is_alive);
        const matches = searchable.filter(n => (n.name || '').toLowerCase().includes(q));
        const exact = matches.find(n => (n.name || '').toLowerCase() === q);
        return { match: exact || matches[0] || null, count: matches.length };
    }

    function setSearchStatus(text) {
        if (searchStatus) searchStatus.textContent = text;
    }

    function restoreViewerPrefs() {
        let prefs = {};
        try { prefs = JSON.parse(window.localStorage.getItem(prefsKey) || '{}') || {}; }
        catch (e) { prefs = {}; }

        if (typeof prefs.showLiving === 'boolean') showLiving = prefs.showLiving;
        if (livingToggle) livingToggle.checked = showLiving;

        if (generationInput && prefs.generations) {
            const generations = Math.max(1, Math.min(20, parseInt(prefs.generations, 10) || 6));
            generationInput.value = String(generations);
        }

        if (directionSelect && ['ancestors-up', 'descendants-up'].includes(prefs.direction)) {
            directionSelect.value = prefs.direction;
        }
    }

    function saveViewerPrefs() {
        const prefs = {
            showLiving,
            generations: generationInput ? generationInput.value : '6',
            direction: directionSelect ? directionSelect.value : 'ancestors-up',
        };
        try { window.localStorage.setItem(prefsKey, JSON.stringify(prefs)); }
        catch (e) { /* preferences are optional */ }
    }

    function filterByGeneration(nodes) {
        if (!selectedId) return nodes;
        const depth = Math.max(1, Math.min(20, parseInt(generationInput ? generationInput.value : '6', 10) || 6));
        const allowed = new Set(nodes.map(n => n.id));
        const links = graph.edges || [];
        const queue = [[selectedId, 0]];
        const seen = new Set([selectedId]);
        while (queue.length) {
            const [id, dist] = queue.shift();
            if (dist >= depth) continue;
            links.forEach(edge => {
                if (!allowed.has(edge.from) || !allowed.has(edge.to)) return;
                let next = null;
                if (edge.from === id) next = edge.to;
                else if (edge.to === id) next = edge.from;
                if (next !== null && !seen.has(next)) {
                    seen.add(next);
                    queue.push([next, dist + 1]);
                }
            });
        }
        return nodes.filter(n => seen.has(n.id));
    }

    function buildGenerationMap(personId, allowed) {
        const maxDepth = Math.max(1, Math.min(20, parseInt(generationInput ? generationInput.value : '6', 10) || 6));
        const map = new Map([[personId, 0]]);
        const queue = [personId];
        while (queue.length) {
            const current = queue.shift();
            const currentGeneration = map.get(current) || 0;
            (graph.edges || []).forEach(edge => {
                const from = edgeId(edge.from);
                const to = edgeId(edge.to);
                if (!allowed.has(from) || !allowed.has(to)) return;
                let next = null;
                let nextGeneration = currentGeneration;
                if (edge.type === 'child') {
                    if (to === current) {
                        next = from;
                        nextGeneration = currentGeneration - 1;
                    } else if (from === current) {
                        next = to;
                        nextGeneration = currentGeneration + 1;
                    }
                } else if (edge.type === 'spouse') {
                    if (from === current) next = to;
                    else if (to === current) next = from;
                }
                if (next === null || map.has(next) || Math.abs(nextGeneration) > maxDepth) return;
                map.set(next, nextGeneration);
                queue.push(next);
            });
        }
        return map;
    }

    function generationY(person, generationMap) {
        const generation = generationMap.has(person.id) ? generationMap.get(person.id) : 0;
        const direction = directionSelect ? directionSelect.value : 'ancestors-up';
        const sign = direction === 'descendants-up' ? -1 : 1;
        return (height / 2) + generation * 115 * sign;
    }

    function fitGraph() {
        if (!lastNodes.length) {
            svg.transition().call(zoom.transform, d3.zoomIdentity);
            return;
        }
        let box;
        try { box = g.node().getBBox(); }
        catch (e) { box = null; }
        if (!box || !box.width || !box.height) {
            svg.transition().duration(180).call(zoom.transform, d3.zoomIdentity);
            return;
        }
        const margin = 64;
        const scale = Math.max(0.2, Math.min(1.35, Math.min(
            (width - margin) / box.width,
            (height - margin) / box.height
        )));
        const x = (width - box.width * scale) / 2 - box.x * scale;
        const y = (height - box.height * scale) / 2 - box.y * scale;
        svg.transition().duration(220).call(zoom.transform, d3.zoomIdentity.translate(x, y).scale(scale));
    }

    function showDetails(person) {
        if (!details) return;
        const title = details.querySelector('h3');
        const meta = details.querySelector('.arbor-viewer-person-meta');
        const relationship = details.querySelector('.arbor-viewer-person-relationship');
        const pathBox = details.querySelector('.arbor-viewer-person-path');
        const relativesBox = details.querySelector('.arbor-viewer-person-relatives');
        const gapsBox = details.querySelector('.arbor-viewer-person-gaps');
        const profileLink = details.querySelector('.arbor-viewer-open-profile');
        const familyLink = details.querySelector('.arbor-viewer-add-family');
        const parentsLink = details.querySelector('.arbor-viewer-add-parents');
        const childLink = details.querySelector('.arbor-viewer-add-child');
        const sex = person.sex === 'M' ? 'Male' : (person.sex === 'F' ? 'Female' : 'Unknown sex');
        const living = person.is_alive ? 'Living' : 'Not living';
        if (title) title.textContent = person.name || 'Unnamed person';
        if (meta) meta.textContent = [person.id === rootPersonId ? 'Main person' : '', sex, living, person.years || ''].filter(Boolean).join(' · ');
        if (relationship) relationship.textContent = relationshipToMain(person);
        if (pathBox) renderPathToMain(person, pathBox);
        if (relativesBox) renderRelatives(person, relativesBox);
        if (gapsBox) renderDataGaps(person, gapsBox);
        if (profileLink) profileLink.href = '../person/?id=' + person.id;
        if (familyLink) familyLink.href = (container.dataset.addFamilyUrl || '../union/?person=') + person.id;
        if (parentsLink) parentsLink.href = (container.dataset.addParentsUrl || '../union/?child=') + person.id;
        if (childLink) childLink.href = (container.dataset.addChildUrl || '../union/?add_child=1&partner1=') + person.id;
        updateSetMainForms(person);
        details.hidden = false;
    }

    function renderRelatives(person, box) {
        const relationMap = buildRelationMap(person.id);
        const groups = [
            ['Parents', relationMap.parents],
            ['Partners', relationMap.partners],
            ['Children', relationMap.children],
        ];
        box.innerHTML = '';
        groups.forEach(([label, ids]) => {
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dt.textContent = label;
            if (ids.length) {
                ids.forEach(id => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'arbor-relative-link';
                    button.textContent = personName(id);
                    button.addEventListener('click', () => selectPersonById(id));
                    dd.append(button);
                });
            } else {
                dd.textContent = 'None';
            }
            box.append(dt, dd);
        });
    }

    function renderDataGaps(person, box) {
        const relations = buildRelationMap(person.id);
        const gaps = [];
        if (!person.birth_year) gaps.push(['Birth year', '../person/?id=' + person.id]);
        if (!relations.parents.length) gaps.push(['Parents', (container.dataset.addParentsUrl || '../union/?child=') + person.id]);
        if (!relations.partners.length) gaps.push(['Partner', (container.dataset.addFamilyUrl || '../union/?person=') + person.id]);
        if (!relations.children.length) gaps.push(['Children', (container.dataset.addChildUrl || '../union/?add_child=1&partner1=') + person.id]);

        box.innerHTML = '';
        if (!gaps.length) {
            box.hidden = true;
            return;
        }
        const title = document.createElement('div');
        title.className = 'arbor-viewer-gaps-title';
        title.textContent = 'Missing info';
        box.append(title);
        const list = document.createElement('div');
        list.className = 'arbor-viewer-gaps-list';
        gaps.forEach(([label, href]) => {
            const link = document.createElement('a');
            link.href = href;
            link.textContent = label;
            list.append(link);
        });
        box.append(list);
        box.hidden = false;
    }

    function buildRelationMap(personId) {
        const parents = new Set();
        const partners = new Set();
        const children = new Set();
        (graph.edges || []).forEach(edge => {
            const from = edgeId(edge.from);
            const to = edgeId(edge.to);
            if (edge.type === 'child') {
                if (to === personId) parents.add(from);
                if (from === personId) children.add(to);
            } else if (edge.type === 'spouse') {
                if (from === personId) partners.add(to);
                if (to === personId) partners.add(from);
            }
        });
        return {
            parents: Array.from(parents),
            partners: Array.from(partners),
            children: Array.from(children),
        };
    }

    function immediateRelatedIds(personId) {
        const relations = buildRelationMap(personId);
        return new Set([...relations.parents, ...relations.partners, ...relations.children]);
    }

    function relationshipToMain(person) {
        if (!rootPersonId) return 'Main person is not set';
        if (person.id === rootPersonId) return 'This is the main person';

        const rootRelations = buildRelationMap(rootPersonId);
        if (rootRelations.parents.includes(person.id)) return sexWord(person, 'Father', 'Mother', 'Parent');
        if (rootRelations.partners.includes(person.id)) return 'Partner of the main person';
        if (rootRelations.children.includes(person.id)) return sexWord(person, 'Son', 'Daughter', 'Child');

        const personRelations = buildRelationMap(person.id);
        if (personRelations.parents.some(id => rootRelations.parents.includes(id))) {
            return sexWord(person, 'Brother', 'Sister', 'Sibling');
        }
        if (rootRelations.parents.some(parentId => buildRelationMap(parentId).parents.includes(person.id))) {
            return sexWord(person, 'Grandfather', 'Grandmother', 'Grandparent');
        }
        if (rootRelations.children.some(childId => buildRelationMap(childId).children.includes(person.id))) {
            return sexWord(person, 'Grandson', 'Granddaughter', 'Grandchild');
        }

        const allowed = new Set((graph.nodes || []).map(n => n.id));
        const generations = buildGenerationMap(rootPersonId, allowed);
        const generation = generations.get(person.id);
        if (generation < -1) return 'Ancestor of the main person';
        if (generation > 1) return 'Descendant of the main person';
        if (generation === 0) return 'Relative on the same generation';
        if (generation === -1) return 'Parent generation';
        if (generation === 1) return 'Child generation';
        return 'Connected relative';
    }

    function renderPathToMain(person, box) {
        box.innerHTML = '';
        if (!rootPersonId || person.id === rootPersonId) {
            box.hidden = true;
            return;
        }
        const path = findPath(rootPersonId, person.id);
        if (path.length < 2) {
            box.hidden = true;
            return;
        }
        const label = document.createElement('span');
        label.className = 'arbor-viewer-path-label';
        label.textContent = 'Path: ';
        box.append(label);
        path.forEach((id, index) => {
            if (index) {
                const arrow = document.createElement('span');
                arrow.className = 'arbor-viewer-path-arrow';
                arrow.textContent = ' → ';
                box.append(arrow);
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'arbor-relative-link arbor-viewer-path-person';
            button.textContent = personName(id);
            button.addEventListener('click', () => selectPersonById(id));
            box.append(button);
        });
        box.hidden = false;
    }

    function findPath(startId, endId) {
        const queue = [[startId]];
        const seen = new Set([startId]);
        while (queue.length) {
            const path = queue.shift();
            const current = path[path.length - 1];
            if (current === endId) return path;
            buildNeighborIds(current).forEach(next => {
                if (seen.has(next)) return;
                seen.add(next);
                queue.push([...path, next]);
            });
        }
        return [];
    }

    function pathEdges(pathIds) {
        const path = Array.from(pathIds);
        const keys = new Set();
        for (let i = 1; i < path.length; i += 1) {
            keys.add(edgeKey(path[i - 1], path[i]));
        }
        return keys;
    }

    function edgeKey(a, b) {
        return Math.min(a, b) + ':' + Math.max(a, b);
    }

    function buildNeighborIds(personId) {
        const neighbors = new Set();
        (graph.edges || []).forEach(edge => {
            const from = edgeId(edge.from);
            const to = edgeId(edge.to);
            if (from === personId) neighbors.add(to);
            if (to === personId) neighbors.add(from);
        });
        return Array.from(neighbors);
    }

    function sexWord(person, male, female, fallback) {
        if (person.sex === 'M') return male;
        if (person.sex === 'F') return female;
        return fallback;
    }

    function edgeId(value) {
        return typeof value === 'object' && value !== null ? value.id : value;
    }

    function personName(personId) {
        const person = (graph.nodes || []).find(n => n.id === personId);
        return person ? (person.name || ('#' + person.id)) : ('#' + personId);
    }

    function selectPersonById(personId, options = {}) {
        if (!personId) return;
        const person = (graph.nodes || []).find(n => n.id === personId);
        if (!person) return;
        selectedId = personId;
        if (searchInput && !options.keepSearch) searchInput.value = '';
        if (!options.keepSearch) setSearchStatus('');
        render();
        showDetails(person);
    }

    function showAll() {
        selectedId = null;
        if (searchInput) searchInput.value = '';
        setSearchStatus('');
        if (details) details.hidden = true;
        render();
    }

    function updateAgenda(selected) {
        const root = rootPersonId ? (graph.nodes || []).find(n => n.id === rootPersonId) : null;
        if (agendaMain) {
            agendaMain.textContent = root ? 'Main person: ' + (root.name || ('#' + root.id)) : 'Main person: not set';
        }
        if (agendaSelected) {
            agendaSelected.textContent = selected
                ? 'Selected: ' + (selected.name || ('#' + selected.id))
                : 'Select a person to see next actions.';
        }
        if (!agendaActions) return;
        agendaActions.hidden = !selected;
        updateSetMainForms(selected);
        if (!selected) return;
        const setLink = (selector, url) => {
            const link = agendaActions.querySelector(selector);
            if (link) link.href = url;
        };
        setLink('.arbor-viewer-agenda-profile', '../person/?id=' + selected.id);
        setLink('.arbor-viewer-agenda-parents', (container.dataset.addParentsUrl || '../union/?child=') + selected.id);
        setLink('.arbor-viewer-agenda-partner', (container.dataset.addFamilyUrl || '../union/?person=') + selected.id);
        setLink('.arbor-viewer-agenda-child', (container.dataset.addChildUrl || '../union/?add_child=1&partner1=') + selected.id);
    }

    function updateSetMainForms(person) {
        setMainForms.forEach(form => {
            const input = form.querySelector('input[name="person_id"]');
            const button = form.querySelector('button[type="submit"]');
            if (input) input.value = person ? String(person.id) : '';
            if (button) {
                button.disabled = !person || person.id === rootPersonId;
                button.hidden = !!person && person.id === rootPersonId;
            }
            form.hidden = !person || person.id === rootPersonId;
        });
    }
})();
