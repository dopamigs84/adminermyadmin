"""Exercise real Adminer HTML forms via CGI, without listening on a network port."""
from __future__ import annotations

import hashlib
from html.parser import HTMLParser
from http.cookies import SimpleCookie
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
from urllib.parse import urlencode, urlsplit

ROOT = Path(__file__).resolve().parent.parent


class Forms(HTMLParser):
    def __init__(self, document: str):
        super().__init__()
        self.inputs: list[dict[str, str]] = []
        self.feed(document)

    def handle_starttag(self, tag, attrs):
        if tag == 'input':
            self.inputs.append(dict(attrs))

    def token(self):
        return next(i['value'] for i in self.inputs if i.get('name') == 'token')


class Browser:
    def __init__(self, binary: str, directory: str, password_hash: str):
        self.binary = binary
        self.directory = directory
        self.password_hash = password_hash
        self.cookies: dict[str, str] = {}

    def request(self, url='/', data=None, raw=None, content_type=None, redirects=5):
        parsed = urlsplit(url)
        payload = raw if raw is not None else (urlencode(data).encode() if data is not None else b'')
        env = dict(os.environ,
            REDIRECT_STATUS='200', REQUEST_METHOD='POST' if data is not None or raw is not None else 'GET',
            SCRIPT_FILENAME=str(ROOT / 'public/index.php'), SCRIPT_NAME='/index.php',
            REQUEST_URI='/' + ('?' + parsed.query if parsed.query else ''), QUERY_STRING=parsed.query,
            SERVER_PROTOCOL='HTTP/1.1', SERVER_NAME='localhost', SERVER_PORT='80',
            REMOTE_ADDR='127.0.0.1', HTTP_COOKIE='; '.join(f'{k}={v}' for k, v in self.cookies.items()),
            CONTENT_LENGTH=str(len(payload)), CONTENT_TYPE=content_type or 'application/x-www-form-urlencoded',
            AM_SQLITE_PASSWORD_HASH=self.password_hash,
        )
        result = subprocess.run([self.binary, '-d', f'session.save_path={self.directory}', '-d', f'upload_tmp_dir={self.directory}'],
            input=payload, env=env, cwd=ROOT / 'public', capture_output=True, timeout=25)
        assert result.returncode == 0, result.stderr.decode(errors='replace')
        output = result.stdout.decode('utf-8', errors='replace')
        headers_raw, _, body = output.partition('\r\n\r\n')
        if not body:
            headers_raw, _, body = output.partition('\n\n')
        headers = {}
        for line in headers_raw.splitlines():
            name, separator, value = line.partition(':')
            if not separator:
                continue
            if name.lower() == 'set-cookie':
                cookie = SimpleCookie()
                cookie.load(value.strip())
                self.cookies.update({key: item.value for key, item in cookie.items()})
            else:
                headers[name.lower()] = value.strip()
        if 'location' in headers and redirects:
            return self.request(headers['location'], redirects=redirects - 1)
        assert not result.stderr, result.stderr.decode(errors='replace')
        assert 'Fatal error' not in body and 'Warning:' not in body, body[:2500]
        return headers, body


def check(condition, description):
    assert condition, description
    print(f'PASS {description}')


def main():
    binary = os.environ.get('PHP_CGI') or shutil.which('php-cgi')
    if not binary:
        candidates = sorted(Path('/Applications/MAMP/bin/php').glob('php8.*/bin/php-cgi'))
        binary = str(candidates[-1]) if candidates else None
    if not binary:
        raise SystemExit('Serve php-cgi: impostare PHP_CGI=/percorso/php-cgi.')

    original_hashes = {name: hashlib.sha256((ROOT / 'adminer' / name).read_bytes()).hexdigest()
                       for name in ('index.php', 'adminer.php', 'adminer-mssql.php')}
    # A test-only password, hashed by the same PHP runtime family as the CGI.
    php = str(Path(binary).with_name('php'))
    password_hash = subprocess.check_output([php, '-r', 'echo password_hash("smoke-test-only", PASSWORD_DEFAULT);']).decode()
    with tempfile.TemporaryDirectory(prefix='adminermyadmin-') as directory:
        db = Path(directory) / 'smoke.sqlite'
        with sqlite3.connect(db) as connection:
            connection.executescript('CREATE TABLE persone (id INTEGER PRIMARY KEY, nome TEXT NOT NULL); INSERT INTO persone VALUES (1, \'Alice\'); CREATE VIEW nomi AS SELECT nome FROM persone;')
        browser = Browser(binary, directory, password_hash)

        headers, body = browser.request()
        check('name="auth[driver]"' in body and 'Microsoft SQL Server' in body, 'Pagina accesso e selettore SQL Server')
        check('no-store' in headers.get('cache-control', '') and headers.get('x-frame-options') == 'deny', 'Header riservatezza e protezione frame')

        # Missing driver/authentication errors must render, rather than fatal in bodyClass().
        _, error_page = browser.request('/?mssql=invalid.example&username=smoke')
        check('am-login' in error_page and 'am-login-aside' in error_page, 'Pagina errore connessione MSSQL')

        context = {'sqlite': '', 'username': 'tester', 'db': str(db)}
        def url(**params):
            return '/?' + urlencode(context | params)

        headers, body = browser.request('/', data={'auth[driver]': 'sqlite', 'auth[server]': '',
            'auth[username]': 'tester', 'auth[password]': 'smoke-test-only', 'auth[db]': str(db)})
        check('am-connected' in body and 'am-table-tree' in body and 'persone' in body, 'Login, sessione e navigazione database')
        check('am-topbar' in body and 'Struttura' in body and 'Esporta' in body, 'Schede e barra del workspace')

        _, body = browser.request(url(select='persone'))
        check('Alice' in body and 'am-current' in body, 'Lettura dati e tabella attiva')
        _, body = browser.request(url(table='persone'))
        check('nome' in body and 'integer' in body.lower(), 'Struttura colonne')

        _, sql_page = browser.request(url(sql=''))
        check('name=\'query\'' in sql_page and 'Ctrl /' in sql_page, 'Editor SQL nativo')
        headers, body = browser.request(url(sql=''), data={'query': "INSERT INTO persone (nome) VALUES ('Èlia'); SELECT * FROM persone;", 'token': Forms(sql_page).token(), 'limit': '100'})
        with sqlite3.connect(db) as connection:
            inserted = connection.execute('SELECT COUNT(*) FROM persone').fetchone()[0]
        check(inserted == 2 and 'Èlia' in body, 'Esecuzione SQL e risultati Unicode')

        _, body = browser.request(url(sql=''), data={'query': "DELETE FROM persone;", 'token': 'invalid'})
        with sqlite3.connect(db) as connection:
            remaining = connection.execute('SELECT COUNT(*) FROM persone').fetchone()[0]
        check(remaining == 2, 'CSRF: scrittura rifiutata con token non valido')

        _, edit_page = browser.request(url(edit='persone'))
        _, body = browser.request(url(edit='persone'), data={'fields[nome]': 'Bruno', 'function[nome]': '',
            'fields[id]': '', 'function[id]': 'orig', 'save': 'Salva', 'token': Forms(edit_page).token()})
        with sqlite3.connect(db) as connection:
            names = [row[0] for row in connection.execute('SELECT nome FROM persone')]
        check('Bruno' in names, 'Inserimento record dal form nativo')

        _, edit_page = browser.request(url(edit='persone', **{'where[id]': '1'}))
        _, body = browser.request(url(edit='persone', **{'where[id]': '1'}), data={
            'fields[nome]': 'Alice aggiornata', 'function[nome]': '',
            'fields[id]': '1', 'function[id]': '', 'save': 'Salva', 'token': Forms(edit_page).token()})
        with sqlite3.connect(db) as connection:
            updated = connection.execute('SELECT nome FROM persone WHERE id=1').fetchone()[0]
        check(updated == 'Alice aggiornata', 'Modifica record dal form nativo')

        _, delete_page = browser.request(url(edit='persone', **{'where[id]': '2'}))
        _, body = browser.request(url(edit='persone', **{'where[id]': '2'}), data={
            'delete': 'Elimina', 'token': Forms(delete_page).token()})
        with sqlite3.connect(db) as connection:
            deleted = connection.execute('SELECT COUNT(*) FROM persone WHERE id=2').fetchone()[0]
        check(deleted == 0, 'Eliminazione record dal form nativo')

        _, dump_page = browser.request(url(dump=''))
        headers, dump = browser.request(url(dump=''), data={'output': 'file', 'format': 'sql',
            'db_style': '', 'table_style': 'CREATE', 'data_style': 'INSERT', 'tables[]': 'persone',
            'data[]': 'persone', 'token': Forms(dump_page).token()})
        check('attachment' in headers.get('content-disposition', '') and 'INSERT INTO' in dump and 'Alice aggiornata' in dump, 'Esportazione SQL scaricabile')

        _, import_page = browser.request(url(import_='').replace('import_=', 'import='))
        boundary = '----AdminerMyAdminSmokeBoundary'
        fields = [('token', Forms(import_page).token())]
        parts = []
        for name, value in fields:
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n')
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="sql_file[]"; filename="smoke.sql"\r\nContent-Type: application/sql\r\n\r\nINSERT INTO persone (nome) VALUES (\'Importata\');\r\n--{boundary}--\r\n')
        _, body = browser.request(url(import_='').replace('import_=', 'import='), raw=''.join(parts).encode(), content_type=f'multipart/form-data; boundary={boundary}')
        with sqlite3.connect(db) as connection:
            imported = connection.execute("SELECT COUNT(*) FROM persone WHERE nome='Importata'").fetchone()[0]
        check(imported == 1, 'Importazione file SQL')

        _, workspace = browser.request(url())
        _, body = browser.request(url(), data={'logout': 'Disconnetti', 'token': Forms(workspace).token()})
        _, protected = browser.request(url(select='persone'))
        check('am-login' in protected and 'Alice' not in protected, 'Logout invalida accesso ai dati')

        denied = Browser(binary, directory, password_hash)
        _, body = denied.request('/', data={'auth[driver]': 'sqlite', 'auth[server]': '',
            'auth[username]': 'tester', 'auth[password]': 'wrong-password', 'auth[db]': str(db)})
        check('am-connected' not in body and 'am-login' in body, 'Password SQLite errata rifiutata')

        _, body = browser.request('/?mssql=%3Cscript%3Ealert(1)%3C%2Fscript%3E&username=%3Cimg%20src=x%20onerror=alert(1)%3E')
        check('<img src=x onerror=alert(1)>' not in body and '<script>alert(1)</script>' not in body, 'Escaping dei parametri di connessione')

    for name, expected in original_hashes.items():
        assert hashlib.sha256((ROOT / 'adminer' / name).read_bytes()).hexdigest() == expected
    check(True, 'File originali Adminer invariati')
    print('Tutti i test CGI superati. SQL Server reale e Docker richiedono verifica separata.')


if __name__ == '__main__':
    main()
