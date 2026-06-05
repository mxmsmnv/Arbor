# Changelog

## 1.0.0 - Beta

- Initial public beta release.
- Added redesigned ProcessWire admin screens for trees, people, families, sources, documents, photos, DNA, and research.
- Added interactive tree viewer improvements: main person, selected person panel, agenda, legend, and relationship actions.
- Added research workflow with tasks, questions, search logs, proof conclusions, next actions, and quick follow-up links.
- Added document lead workflow and source/document linking improvements.
- Added DNA kit, match, and segment management.
- Added photo management.
- Added main-person tree setting and people list birth dates.
- Added schema migrations for document status and citation document links.
- Improved table/code alignment across Arbor models and admin forms.
- Improved permissions and tree ownership checks across admin actions.
- Aligned API tree listing with Arbor view permissions.
- Fixed `arbor-admin` private-tree viewing in shared permission checks.
- Fixed new-tree viewer flow by automatically setting the first created person as the main person when none is set.
- Fixed reinstall behavior by explicitly ensuring Arbor permissions and the Setup > Arbor admin page are created.
- Fixed schema-version saves so upgrades preserve the full module configuration.
- Added README for beta publication prep.
