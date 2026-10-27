"""Integration coverage for phpMyAdmin-style search and quick operations."""
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
from html.parser import HTMLParser
from urllib.parse import urlencode

from smoke import Browser, Forms, ROOT, check


class RecordLinks(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.count = 0
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag == 'a' and attributes.get('class') == 'edit':
            self.count += 1


def main():
    binary = os.environ.get('PHP_CGI') or shutil.which('php-cgi')
    if not binary:
        raise SystemExit('Imposta PHP_CGI sul binario php-cgi.')
    php = str(Path(binary).with_name('php'))
    password_hash = subprocess.check_output([php, '-r', 'echo password_hash("workflow-only", PASSWORD_DEFAULT);']).decode()
    with tempfile.TemporaryDirectory(prefix='myadmin-workflows-') as directory:
        db = Path(directory) / 'workspace.sqlite'
        with sqlite3.connect(db) as connection:
            connection.executescript('''
                PRAGMA foreign_keys=ON;
                CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT 'draft', qty INTEGER NOT NULL DEFAULT 0 CHECK(qty>=0), category TEXT, slug TEXT UNIQUE);
                CREATE INDEX idx_items_category ON items(category);
                INSERT INTO items(title,qty,category,slug) VALUES ('Alpha',0,NULL,'alpha'),('Beta',10,'','beta'),('Gamma',20,'A','gamma'),('O''Reilly',30,'B','oreilly');
                CREATE TABLE children (id INTEGER PRIMARY KEY, item_id INTEGER REFERENCES items(id));
                INSERT INTO children VALUES(1,1);
                CREATE VIEW titles AS SELECT title FROM items;
            ''')
        browser = Browser(binary, directory, password_hash)
        browser.request('/', data={'auth[driver]': 'sqlite', 'auth[server]': '', 'auth[username]': 'tester', 'auth[password]': 'workflow-only', 'auth[db]': str(db)})
        context = {'sqlite': '', 'username': 'tester', 'db': str(db)}

        def url(**params):
            return '/?' + urlencode(context | params)

        def query(sql, args=()):
            with sqlite3.connect(db) as connection:
                return connection.execute(sql, args).fetchall()

        def operation(name, action='operations'):
            return url(am_action=action, am_table=name)

        def preview(target, data):
            _, start = browser.request(target)
            _, body = browser.request(target, data=data | {'token': Forms(start).token()})
            assert 'Conferma ed esegui' in body, body[-7000:]
            plan = next(item['value'] for item in Forms(body).inputs if item.get('name') == 'am_plan')
            return {'token': Forms(body).token(), 'am_plan': plan}, body

        def execute(target, data):
            form, _ = preview(target, data)
            _, body = browser.request(target, data=form)
            assert 'Operazione completata' in body, body[-7000:]
            return body

        _, overview = browser.request(url())
        check('am-overview-table' in overview and 'am_single_empty' in overview and 'am_tables[]' in overview, 'Elenco tabelle con azioni rapide e selezione multipla')
        _, search = browser.request(operation('items', 'search'))
        form = Forms(search)
        columns = [item.get('value') for item in form.inputs if item.get('name', '').endswith('[col]')]
        check(columns == ['id', 'title', 'qty', 'category', 'slug'] and 'Secondo valore' in search and 'Almeno una (OR)' in search, 'Ricerca: tutti i campi, tipi, operatori, intervalli e AND/OR')

        def search_rows(criteria, match='all', **extra):
            params = {'select': 'items', 'am_filter': '1', 'am_match': match} | extra
            for index, criterion in enumerate(criteria):
                for key, value in criterion.items():
                    params[f'where[{index}][{key}]'] = value
            _, body = browser.request(url(**params))
            return RecordLinks(body).count, body

        count, body = search_rows([{'col': 'qty', 'op': '=', 'val': '0'}, {'col': 'title', 'op': 'CONTAINS', 'val': ''}])
        check(count == 1 and 'Alpha' in body and 'Gamma' not in body, 'Ricerca: zero distinto da valore ignorato')
        count, _ = search_rows([{'col': 'qty', 'op': 'BETWEEN', 'val': '10', 'val2': '20'}])
        check(count == 2, 'Ricerca BETWEEN inclusivo')
        count, _ = search_rows([{'col': 'qty', 'op': '=', 'val': '0'}, {'col': 'category', 'op': 'EMPTY'}], 'any')
        check(count == 2, 'Ricerca OR e stringa vuota')
        count, _ = search_rows([{'col': 'category', 'op': 'IS NULL'}])
        check(count == 1, 'Ricerca NULL distinto dalla stringa vuota')
        count, _ = search_rows([{'col': 'title', 'op': 'IN', 'val': '"Alpha","O\'Reilly"'}])
        check(count == 2, 'Ricerca IN con apostrofi e valori CSV')
        count, _ = search_rows([{'col': 'qty', 'op': '>=', 'val': '10'}, {'col': 'category', 'op': 'NOT EMPTY'}])
        check(count == 2, 'Ricerca AND su più campi')
        count, _ = search_rows([{'col': 'title', 'op': '=', 'val': "' OR 1=1 --"}])
        check(count == 0 and query('SELECT COUNT(*) FROM items')[0][0] == 4, 'Ricerca: input SQL trattato come valore')
        count, body = search_rows([{'col': 'qty', 'op': 'BETWEEN', 'val': '10'}])
        check(count == 0 and 'BETWEEN richiede' in body, 'Ricerca incompleta: errore senza mostrare tutti i record')
        count, body = search_rows([{'col': 'nonexistent', 'op': '=', 'val': '1'}])
        check(count == 0 and 'Campo di ricerca sconosciuto' in body, 'Ricerca: rifiuto colonne non presenti nei metadati')

        _, browse = browser.request(url(select='items'))
        check(browse.count('class="am-row-copy"') == 4 and browse.count('am-row-delete') == 4, 'Azioni Modifica, Copia, Elimina su ogni record con chiave univoca')
        clone_url = url(edit='items', am_clone='1', **{'where[id]': '2'})
        _, copy_form = browser.request(clone_url)
        check('Copia del record' in copy_form and ('Beta</textarea>' in copy_form or any(item.get('name') == 'fields[title]' and item.get('value') == 'Beta' for item in Forms(copy_form).inputs)), 'Copia record: editor precompilato dai dati di origine')
        _, copied = browser.request(clone_url, data={'token': Forms(copy_form).token(), 'save': 'Salva', 'fields[id]': '', 'function[id]': '', 'fields[title]': 'Beta copia', 'function[title]': '', 'fields[qty]': '10', 'function[qty]': '', 'fields[category]': '', 'function[category]': '', 'fields[slug]': 'beta-copy', 'function[slug]': ''})
        check(query("SELECT COUNT(*) FROM items WHERE title='Beta copia'")[0][0] == 1 and query("SELECT title FROM items WHERE id=2")[0][0] == 'Beta', 'Copia record salva con INSERT senza modificare l’originale')

        target = operation('items')
        form, body = preview(target, {'am_operation': 'copy', 'new_name': 'items_copy', 'copy_data': '1'})
        check(not query("SELECT name FROM sqlite_master WHERE name='items_copy'") and 'CREATE TABLE' in body, 'Anteprima copia non modifica il database')
        _, body = browser.request(target, data=form)
        check(query('SELECT COUNT(*) FROM items_copy')[0][0] == 5 and 'Operazione completata' in body, 'Copia tabella con tutti i dati')
        check(any(row[2] for row in query('PRAGMA index_list(items_copy)')) and len(query('PRAGMA index_list(items_copy)')) >= 2, 'Copia preserva vincoli UNIQUE e indici aggiuntivi')
        try:
            query("INSERT INTO items_copy(title,qty,slug) VALUES('bad',-1,'bad')")
            preserved_check = False
        except sqlite3.IntegrityError:
            preserved_check = True
        check(preserved_check, 'Copia preserva i vincoli CHECK')
        _, replay = browser.request(target, data=form)
        check('già utilizzata' in replay, 'Conferma utilizzabile una sola volta')

        execute(target, {'am_operation': 'copy', 'new_name': 'empty_copy', 'copy_data': '0'})
        check(query('SELECT COUNT(*) FROM empty_copy')[0][0] == 0, 'Copia della sola struttura')
        _, structure = browser.request(url(table='empty_copy'))
        check('am_columns[]' in structure and 'Crea indice composto' in structure and 'Modifica' in structure, 'Struttura: azioni per colonna e selezione multipla')
        columns_url = operation('empty_copy', 'columns')
        _, structure = browser.request(url(table='empty_copy'))
        _, column_preview = browser.request(columns_url, data={'token': Forms(structure).token(), 'am_column_operation': 'UNIQUE', 'am_columns[0]': 'title', 'am_columns[1]': 'qty'})
        check('CREATE UNIQUE INDEX' in column_preview and 'Conferma ed esegui' in column_preview, 'Anteprima indice univoco composto')
        browser.request(columns_url, data={'token': Forms(column_preview).token(), 'am_plan': next(item['value'] for item in Forms(column_preview).inputs if item.get('name') == 'am_plan')})
        indexes = query('PRAGMA index_list(empty_copy)')
        check(len([row for row in indexes if row[2]]) == 2, 'Creazione indice composto confermata')
        query('ALTER TABLE empty_copy ADD COLUMN disposable TEXT')
        drop_column_url = operation('empty_copy', 'columns') + '&am_column=disposable&am_mode=drop'
        _, column_preview = browser.request(drop_column_url)
        check(any(row[1] == 'disposable' for row in query('PRAGMA table_info(empty_copy)')), 'Elimina colonna: nessuna modifica durante l’anteprima')
        browser.request(drop_column_url, data={'token': Forms(column_preview).token(), 'am_plan': next(item['value'] for item in Forms(column_preview).inputs if item.get('name') == 'am_plan')})
        check(not any(row[1] == 'disposable' for row in query('PRAGMA table_info(empty_copy)')), 'Eliminazione colonna confermata')
        execute(operation('empty_copy'), {'am_operation': 'rename', 'new_name': 'copy$0"; --'})
        check(bool(query('SELECT name FROM sqlite_master WHERE name=?', ('copy$0"; --',))) and bool(query("SELECT name FROM sqlite_master WHERE name='items'")), 'Rinomina: identificatori speciali quotati senza eseguire SQL aggiuntivo')
        execute(target, {'am_operation': 'copy', 'new_name': 'dollar$0_copy', 'copy_data': '0'})
        check(bool(query("SELECT name FROM sqlite_master WHERE name='dollar$0_copy'")), 'Copia: nomi con dollaro non reinterpretati come regex')
        query("CREATE TABLE self_ref (id INTEGER PRIMARY KEY, parent INTEGER REFERENCES [self_ref](id), note TEXT DEFAULT 'REFERENCES self_ref(id)')")
        query('INSERT INTO self_ref(id,parent) VALUES(1,NULL),(2,1)')
        execute(operation('self_ref'), {'am_operation': 'copy', 'new_name': 'self_copy', 'copy_data': '1'})
        check(query('PRAGMA foreign_key_list(self_copy)')[0][2] == 'self_copy' and query('SELECT note FROM self_copy WHERE id=1')[0][0] == 'REFERENCES self_ref(id)', 'Copia: chiavi autoreferenziali aggiornate senza alterare le stringhe dei default')
        _, ddl = browser.request(operation('items', 'ddl'))
        check('CREATE TABLE' in ddl and 'data-am-copy-ddl' in ddl and 'CHECK(qty&gt;=0)' in ddl, 'Definizione CREATE consultabile e copiabile')

        replace_url = operation('items', 'replace')
        form, body = preview(replace_url, {'replace_column': 'title', 'replace_from': 'Alpha', 'replace_to': 'Omega', 'am_match': 'all', 'where[0][col]': 'qty', 'where[0][op]': '=', 'where[0][val]': '0'})
        check('Righe interessate' in body and query('SELECT title FROM items WHERE id=1')[0][0] == 'Alpha', 'Trova/sostituisci: anteprima e conteggio prima della scrittura')
        browser.request(replace_url, data=form)
        check(query('SELECT title FROM items WHERE id=1')[0][0] == 'Omega' and query('SELECT title FROM items WHERE id=2')[0][0] == 'Beta', 'Sostituzione limitata ai record filtrati')

        delete_url = operation('items', 'delete-row') + '&where%5Bid%5D=5'
        _, confirm = browser.request(delete_url)
        check(query('SELECT COUNT(*) FROM items WHERE id=5')[0][0] == 1 and 'Conferma ed esegui' in confirm, 'Elimina record: apertura senza effetti sui dati')
        confirm_form = {'token': Forms(confirm).token(), 'am_plan': next(item['value'] for item in Forms(confirm).inputs if item.get('name') == 'am_plan')}
        browser.request(delete_url, data=confirm_form)
        check(query('SELECT COUNT(*) FROM items WHERE id=5')[0][0] == 0, 'Elimina record confermato tramite chiave univoca')

        search_url = url(am_action='database-search')
        _, page = browser.request(search_url)
        _, results = browser.request(search_url, data={'token': Forms(page).token(), 'needle': 'Omega', 'search_mode': 'CONTAINS', 'am_tables[]': 'items'})
        check('Sfoglia risultati' in results and 'Corrispondenze' in results, 'Ricerca nel database con selezione tabelle e link ai risultati')

        bulk_url = url(am_action='bulk')
        _, page = browser.request(url())
        headers, dump = browser.request(bulk_url, data={'token': Forms(page).token(), 'am_operation': 'export', 'am_tables[]': 'items_copy'})
        check('attachment' in headers.get('content-disposition', '') and 'CREATE TABLE' in dump and 'Beta copia' in dump, 'Esportazione rapida delle tabelle selezionate')
        form, _ = preview(operation('items_copy'), {'am_operation': 'empty', 'empty_mode': 'truncate'})
        check(query('SELECT COUNT(*) FROM items_copy')[0][0] == 5, 'Svuota: nessuna cancellazione prima della conferma')
        browser.request(operation('items_copy'), data=form)
        check(query('SELECT COUNT(*) FROM items_copy')[0][0] == 0, 'Svuota mantiene la struttura e rimuove i dati')
        execute(operation('items_copy'), {'am_operation': 'drop'})
        check(not query("SELECT name FROM sqlite_master WHERE name='items_copy'"), 'Eliminazione tabella confermata')

        _, workspace = browser.request(url())
        _, batch = browser.request(bulk_url, data={'token': Forms(workspace).token(), 'am_operation': 'drop', 'am_tables[0]': 'dollar$0_copy', 'am_tables[1]': 'items'})
        browser.request(bulk_url, data={'token': Forms(batch).token(), 'am_plan': next(item['value'] for item in Forms(batch).inputs if item.get('name') == 'am_plan')})
        check(bool(query("SELECT name FROM sqlite_master WHERE name='dollar$0_copy'")) and bool(query("SELECT name FROM sqlite_master WHERE name='items'")), 'Operazione multipla: rollback anche delle azioni precedenti se un vincolo impedisce il completamento')

        form, _ = preview(target, {'am_operation': 'drop'})
        _, error = browser.request(target, data=form)
        check('database ha rifiutato' in error and query('SELECT COUNT(*) FROM items')[0][0] == 4, 'Vincoli esterni impediscono DROP senza perdere i dati')
        _, denied = browser.request(target, data={'am_operation': 'empty', 'token': 'invalid'})
        check('token di sicurezza non valido' in denied and query('SELECT COUNT(*) FROM items')[0][0] == 4, 'Operazioni rapide protette da CSRF')
        _, denied = browser.request(url(am_action='operations', am_table='missing'))
        check('non disponibile' in denied, 'Operazioni limitate agli oggetti esistenti')

    print('Tutti i flussi ricerca e operazioni superati. MSSQL reale richiede verifica separata.')


if __name__ == '__main__':
    main()
