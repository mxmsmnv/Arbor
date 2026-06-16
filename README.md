# Arbor

Arbor is a ProcessWire genealogy module for building source-based family trees.
It combines people, families, places, sources, documents, photos, DNA notes,
research questions, search logs, tasks, proof conclusions, and GEDCOM import/export
inside the ProcessWire admin.

## Status

Beta. Arbor is usable in a test copy, but should not be installed on an important
production site without a full database and file backup first.

Repository: [github.com/mxmsmnv/Arbor](https://github.com/mxmsmnv/Arbor)  

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## Requirements

- ProcessWire 3.0.200 or newer
- PHP 8.1 or newer
- MySQL/MariaDB with InnoDB

## Modules

- `Arbor` installs the schema, models, configuration, permissions, and shared services.
- `ProcessArbor` provides the admin UI under Setup > Arbor.
- `ArborApi` provides optional REST-style endpoints for integrations.

## Features

- Multiple family trees with owner/public settings
- Person profiles with names, events, notes, sources, photos, and relationships
- Family/union management with partners, parents, children, and relationship type
- Interactive tree viewer with main person, selected-person panel, agenda, and legend
- Places, repositories/archives, sources, citations, documents, and document leads
- Research workflow: questions, tasks, search log, proof conclusions, and next actions
- DNA kits, matches, and segments
- GEDCOM 5.5.1 and GEDCOM 7 export
- GEDCOM import foundation
- Optional AI helper integration through AiWire-compatible providers

## Installation

1. Download the release ZIP or copy the `Arbor` directory into `site/modules/Arbor/`.
2. If installing manually, go to ProcessWire admin > Modules > Refresh.
3. Install `Arbor`.
4. ProcessWire should also install `ProcessArbor` and `ArborApi`.
5. Go to Setup > Arbor.
6. Create a tree and start adding people.

## Permissions

Arbor installs these permissions:

- `arbor-view`: view Arbor trees
- `arbor-edit`: create and edit trees owned by the current user
- `arbor-admin`: administer all Arbor trees and delete trees

Public trees can be viewed without ownership checks. Editing always requires a
logged-in user with the correct Arbor permission.

## Data And Files

Arbor creates database tables prefixed with `arbor_`.

Uploaded files are stored under:

```text
/site/assets/files/arbor/
```

Uninstalling the module removes Arbor database tables. Before uninstalling, make
sure you have exported or backed up any family data you want to keep.

## Publication Notes

Before listing Arbor as a stable ProcessWire module, test:

- fresh install on an empty ProcessWire site
- upgrade from an older Arbor schema
- uninstall on a disposable copy
- CRUD flows for trees, people, families, sources, documents, photos, DNA, and research
- permissions with owner, editor, admin, and anonymous users
- mobile and narrow admin layouts

## License

MIT. See `LICENSE`.
