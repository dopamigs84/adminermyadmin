/* Progressive enhancement. Native Adminer forms remain the source of truth. */
(() => {
    'use strict';
    const query = new URLSearchParams(location.search);
    const get = (selector) => document.querySelector(selector);
    const context = [query.get('mssql') ?? query.get('server') ?? query.get('pgsql') ?? query.get('sqlite'), query.get('username'), query.get('db'), query.get('ns')];
    const storageKey = (name) => `adminermyadmin:${name}:${JSON.stringify(context)}`;
    const read = (name, fallback) => {
        try { return JSON.parse(localStorage.getItem(storageKey(name))) ?? fallback; } catch { return fallback; }
    };
    const write = (name, value) => {
        try { localStorage.setItem(storageKey(name), JSON.stringify(value)); return true; } catch { return false; }
    };
    const content = get('#content');
    const bar = get('#am-workspace-bar');
    if (bar && content) content.prepend(bar);
    const footer = get('#am-workspace-footer');
    if (footer) document.body.append(footer);

    // Search criteria and operations work through HTML forms, also without JS.
    document.querySelectorAll('.am-search-fields legend a').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const panel = link.closest('fieldset').querySelector('#fieldset-search');
            panel.classList.toggle('hidden');
        });
    });
    document.querySelectorAll('.am-search-op').forEach((select) => {
        const update = () => {
            const row = select.closest('tr');
            const first = row.querySelector('input[name$="[val]"]');
            const second = row.querySelector('.am-search-value2');
            const noValue = ['IS NULL', 'IS NOT NULL', 'EMPTY', 'NOT EMPTY'].includes(select.value);
            const between = ['BETWEEN', 'NOT BETWEEN'].includes(select.value);
            first.disabled = noValue;
            second.disabled = !between;
            second.hidden = !between;
            first.placeholder = ['IN', 'NOT IN'].includes(select.value) ? 'Valori separati da virgola' : 'Ignora campo';
        };
        select.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('[data-am-clear-search]').forEach((button) => {
        button.addEventListener('click', () => {
            const fieldset = button.closest('fieldset');
            fieldset.querySelectorAll('input[name$="[val]"],input[name$="[val2]"]').forEach((input) => { input.value = ''; });
            fieldset.querySelectorAll('.am-search-op').forEach((select) => {
                select.value = [...select.options].some((option) => option.value === 'CONTAINS') ? 'CONTAINS' : '=';
                select.dispatchEvent(new Event('change'));
            });
        });
    });
    const updateSelected = (form) => {
        const count = form.querySelectorAll('input[name="am_tables[]"]:checked').length;
        const label = form.querySelector('[data-am-selected-count]');
        if (label) label.textContent = `${count} selezionate`;
    };
    document.querySelectorAll('[data-am-check-all]').forEach((toggleAll) => {
        toggleAll.addEventListener('change', () => {
            const form = toggleAll.closest('form');
            form.querySelectorAll('input[name="am_tables[]"]').forEach((input) => {
                if (!input.closest('tr')?.hidden) input.checked = toggleAll.checked;
            });
            updateSelected(form);
        });
    });
    document.querySelectorAll('input[name="am_tables[]"]').forEach((input) => {
        input.addEventListener('change', () => updateSelected(input.form));
    });
    get('#am-overview-filter')?.addEventListener('input', (event) => {
        const needle = event.target.value.trim().toLocaleLowerCase();
        document.querySelectorAll('[data-am-overview-row]').forEach((row) => {
            row.hidden = !row.dataset.amOverviewRow.toLocaleLowerCase().includes(needle);
        });
    });
    document.querySelectorAll('[data-am-copy-ddl]').forEach((button) => {
        button.addEventListener('click', async () => {
            const source = get('.am-ddl');
            try {
                await navigator.clipboard.writeText(source.value);
                button.textContent = 'Copiato ✓';
            } catch {
                source.focus();
                source.select();
                button.textContent = 'Premi Ctrl / ⌘ + C';
            }
        });
    });
    document.querySelectorAll('[data-am-check-columns]').forEach((toggleAll) => {
        toggleAll.addEventListener('change', () => {
            toggleAll.form.querySelectorAll('input[name="am_columns[]"]').forEach((input) => { input.checked = toggleAll.checked; });
        });
    });
    if (query.has('am_column') && query.has('create')) {
        const original = [...document.querySelectorAll('input[name$="[orig]"]')].find((input) => input.value === query.get('am_column'));
        const row = original?.closest('tr');
        row?.scrollIntoView({block: 'center'});
        row?.querySelector('input[name$="[field]"]')?.focus();
    }
    if (query.has('am_add_column') && query.has('create')) {
        const fields = [...document.querySelectorAll('input[name$="[field]"]')];
        const empty = fields.find((input) => input.value === '');
        empty?.scrollIntoView({block: 'center'});
        empty?.focus();
    }

    const toggle = get('.am-menu-toggle');
    const closeNav = () => {
        document.body.classList.remove('am-nav-open');
        toggle?.setAttribute('aria-expanded', 'false');
    };
    toggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        const open = document.body.classList.toggle('am-nav-open');
        toggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', (event) => {
        if (document.body.classList.contains('am-nav-open') && !event.target.closest('#foot, .am-menu-toggle')) closeNav();
    });

    const filter = get('#am-table-filter');
    const rows = [...document.querySelectorAll('.am-tree-row')];
    const savedFavorites = read('favorites', []);
    const favorites = new Set(Array.isArray(savedFavorites) ? savedFavorites.filter((name) => typeof name === 'string') : []);
    const sortTree = () => {
        const tree = get('#tables');
        if (!tree) return;
        rows.sort((a, b) => Number(favorites.has(b.dataset.table)) - Number(favorites.has(a.dataset.table)) || a.dataset.table.localeCompare(b.dataset.table));
        rows.forEach((row) => tree.append(row));
    };
    rows.forEach((row) => {
        const favorite = row.querySelector('.am-favorite');
        const update = () => {
            const active = favorites.has(row.dataset.table);
            favorite.setAttribute('aria-pressed', String(active));
            favorite.textContent = active ? '★' : '☆';
            favorite.title = active ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti';
        };
        update();
        favorite.addEventListener('click', () => {
            if (favorites.has(row.dataset.table)) favorites.delete(row.dataset.table);
            else favorites.add(row.dataset.table);
            write('favorites', [...favorites]);
            update();
            sortTree();
        });
    });
    sortTree();
    filter?.addEventListener('input', () => {
        const value = filter.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach((row) => {
            row.hidden = !row.dataset.table.toLocaleLowerCase().includes(value);
            if (!row.hidden) visible++;
        });
        const empty = get('#am-filter-empty');
        if (empty) empty.hidden = visible > 0 || value === '';
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeNav();
        if (event.key === '/' && filter && !event.ctrlKey && !event.metaKey && !event.altKey && !event.target.closest('input,textarea,select,[contenteditable]')) {
            event.preventDefault();
            if (innerWidth <= 850) {
                document.body.classList.add('am-nav-open');
                toggle?.setAttribute('aria-expanded', 'true');
            }
            filter.focus();
        }
    });

    // Login fields work without JS; this only adjusts driver-specific hints.
    const driver = get('#am-driver');
    if (driver) {
        const updateLogin = () => {
            const mssql = driver.value === 'mssql';
            const note = get('#am-tls-note');
            const status = get('#am-driver-status');
            if (note) note.hidden = !mssql;
            if (status) status.hidden = !mssql;
            const server = get('#am-server');
            server.placeholder = ({mssql: 'sql.example.it:1433', server: 'localhost:3306', pgsql: 'localhost:5432', sqlite: 'Non necessario per SQLite'})[driver.value] || 'localhost';
            get('#am-db').placeholder = driver.value === 'sqlite' ? '/percorso/assoluto/database.sqlite' : 'Selezionabile anche dopo l’accesso';
        };
        driver.addEventListener('change', updateLogin);
        updateLogin();
    }

    // Search is the existing Adminer filter, expanded and focused by the tab.
    if (query.has('am_search') || query.has('search')) {
        const search = get('#fieldset-search');
        if (search) {
            search.classList.remove('hidden');
            const control = search.querySelector('input:not([type=hidden]),select');
            control?.focus();
            search.scrollIntoView({block: 'center'});
        } else {
            get('input[type=search][name=query]')?.focus();
        }
    }

    const textarea = document.querySelector('body.am-sql textarea[name="query"]');
    if (!textarea) return;
    const form = textarea.form;
    const tools = document.createElement('div');
    tools.className = 'am-sql-tools';
    const snippetLabel = document.createElement('label');
    snippetLabel.textContent = 'Query rapide';
    const snippets = document.createElement('select');
    snippets.setAttribute('aria-label', 'Inserisci un modello di query');
    const microsoft = query.has('mssql');
    const templates = microsoft ? [
        ['Informazioni sul server', 'SELECT @@SERVERNAME AS server, @@VERSION AS versione;'],
        ['Tabelle e viste dello schema', 'SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE\nFROM INFORMATION_SCHEMA.TABLES\nORDER BY TABLE_SCHEMA, TABLE_NAME;'],
        ['Prime 100 righe', 'SELECT TOP (100) *\nFROM [dbo].[nome_tabella];'],
        ['Colonne e tipi', "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE\nFROM INFORMATION_SCHEMA.COLUMNS\nWHERE TABLE_SCHEMA = N'dbo' AND TABLE_NAME = N'nome_tabella'\nORDER BY ORDINAL_POSITION;"],
        ['Procedure e funzioni', 'SELECT SCHEMA_NAME(schema_id) AS schema_name, name, type_desc\nFROM sys.objects\nWHERE type IN (\'P\', \'FN\', \'IF\', \'TF\')\nORDER BY schema_name, name;'],
        ['Definizione di un oggetto', "SELECT OBJECT_DEFINITION(OBJECT_ID(N'dbo.nome_oggetto')) AS definizione;"],
        ['Crea una procedura (SQL Server 2016 SP1+)', 'DELIMITER ;;\nCREATE OR ALTER PROCEDURE [dbo].[nome_procedura]\nAS\nBEGIN\n    SET NOCOUNT ON;\n    SELECT 1 AS risultato;\nEND;;\nDELIMITER ;'],
        ['Indici delle tabelle', 'SELECT SCHEMA_NAME(t.schema_id) AS schema_name, t.name AS tabella, i.name AS indice, i.type_desc, i.is_unique\nFROM sys.indexes AS i\nJOIN sys.tables AS t ON t.object_id = i.object_id\nWHERE i.index_id > 0\nORDER BY schema_name, tabella, indice;'],
        ['Sessioni e richieste attive', 'SELECT session_id, status, command, wait_type, total_elapsed_time\nFROM sys.dm_exec_requests\nWHERE session_id <> @@SPID;'],
    ] : [
        ['Tabelle e viste', query.has('sqlite') ? "SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name;" : 'SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE\nFROM INFORMATION_SCHEMA.TABLES\nORDER BY TABLE_SCHEMA, TABLE_NAME;'],
        ['Prime 100 righe', 'SELECT *\nFROM nome_tabella\nLIMIT 100;'],
        ['Conteggio righe', 'SELECT COUNT(*) AS totale\nFROM nome_tabella;'],
    ];
    snippets.add(new Option('Scegli un modello…', ''));
    templates.forEach(([label], index) => snippets.add(new Option(label, String(index))));
    snippetLabel.append(snippets);
    tools.append(snippetLabel);
    const spacer = document.createElement('span');
    spacer.className = 'am-tools-spacer';
    tools.append(spacer);
    const button = (label, action) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.textContent = label;
        element.addEventListener('click', action);
        tools.append(element);
        return element;
    };
    const setValue = (value) => {
        textarea.value = value;
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.focus();
        // Adminer's syntax highlighter listens for input/keyup.
        textarea.dispatchEvent(new Event('keyup', {bubbles: true}));
    };
    snippets.addEventListener('change', () => {
        if (snippets.value === '') return;
        const text = templates[Number(snippets.value)][1];
        const selection = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const prefix = textarea.value && selection === end ? '\n\n' : '';
        textarea.setRangeText(prefix + text, selection, end, 'end');
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.dispatchEvent(new Event('keyup', {bubbles: true}));
        textarea.focus();
        snippets.value = '';
    });
    const copyButton = button('Copia SQL', async () => {
        try {
            await navigator.clipboard.writeText(textarea.value);
            copyButton.textContent = 'Copiato ✓';
        } catch {
            textarea.focus();
            textarea.select();
            copyButton.textContent = 'Premi Ctrl / ⌘ + C';
        }
        setTimeout(() => { copyButton.textContent = 'Copia SQL'; }, 2200);
    });
    const draftStatus = document.createElement('span');
    draftStatus.className = 'am-draft-status';
    draftStatus.setAttribute('role', 'status');
    button('Salva bozza', () => {
        const success = write('sql-draft', textarea.value);
        draftStatus.textContent = success ? 'Bozza salvata in questo browser' : 'Salvataggio non disponibile nel browser';
    });
    const saved = read('sql-draft', '');
    if (typeof saved === 'string' && saved !== '') {
        button('Apri bozza', () => {
            const draft = read('sql-draft', '');
            if (typeof draft !== 'string' || draft === '') {
                draftStatus.textContent = 'Nessuna bozza salvata';
                return;
            }
            if (textarea.value.trim() && !window.confirm('Sostituire la query attuale con la bozza salvata?')) return;
            setValue(draft);
            draftStatus.textContent = 'Bozza ripristinata';
        });
        button('Elimina bozza', () => {
            write('sql-draft', '');
            draftStatus.textContent = 'Bozza eliminata';
        });
    }
    tools.append(draftStatus);
    // Mount above the native SQL editor wrapper, preserving its DOM relationships.
    const editor = textarea.closest('.jush') || textarea;
    editor.before(tools);
    textarea.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            form.requestSubmit(form.querySelector('input[type=submit],button[type=submit]') || undefined);
        }
        if (event.key === 'Tab') {
            event.preventDefault();
            textarea.setRangeText('    ', textarea.selectionStart, textarea.selectionEnd, 'end');
            textarea.dispatchEvent(new Event('input', {bubbles: true}));
        }
    });
})();
