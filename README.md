# AdminerMyAdmin

Un gestore database in PHP con interfaccia ispirata a phpMyAdmin e priorità a Microsoft SQL Server. Utilizza il motore Adminer 5.4.2 già presente nel progetto: [phpMyAdmin gestisce MySQL/MariaDB](https://www.phpmyadmin.net/) e non può essere usato direttamente come motore MSSQL.

## Funzioni

- Accesso a Microsoft SQL Server, MySQL/MariaDB e PostgreSQL; SQLite opzionale e protetto da password applicativa.
- Selettore database e schema, barra laterale con tabelle e viste, filtro immediato e preferiti.
- Schede per struttura, navigazione dei dati, ricerca, inserimento, SQL, importazione ed esportazione.
- Ricerca con una riga per ogni colonna, tipo visibile, operatore, valore e secondo estremo per BETWEEN. Supporta AND/OR, IN/NOT IN, NULL e stringhe vuote; i valori non compilati vengono ignorati, mentre `0` resta un criterio valido.
- Ricerca dello stesso testo nelle tabelle e viste selezionate dello schema, con conteggi e collegamenti ai risultati.
- Azioni Modifica/Copia/Elimina per ogni record con chiave primaria o univoca completa. La copia apre un editor precompilato e salva con INSERT, generando un nuovo valore identity/auto-increment. Restano disponibili selezione multipla e modifica dei dati di Adminer.
- Elenco tabelle con Sfoglia, Struttura, Cerca, Inserisci, Esporta, Operazioni, Svuota ed Elimina; selezione multipla per esportazione SQL, svuotamento ed eliminazione.
- Scheda Operazioni con rinomina, copia della struttura con o senza dati, svuotamento tramite TRUNCATE o DELETE, eliminazione, spostamento di schema su MSSQL/PostgreSQL e impostazione del contatore IDENTITY su MSSQL.
- Struttura con azioni per colonna: modifica, creazione di indice/univoco/primaria ed eliminazione. La selezione multipla crea indici composti o elimina più colonne; SQLite richiede la ricostruzione per aggiungere una chiave primaria a una tabella esistente.
- Trova e sostituisci su un campo testuale, limitabile con gli stessi filtri per colonna, con conteggio delle righe prima della conferma.
- Definizione CREATE consultabile, copiabile e apribile nell’editor SQL.
- Anteprima SQL e conferma valida una sola volta per le operazioni di modifica aggiunte. Piani legati alla sessione, alla connessione e allo schema, con scadenza di 10 minuti e protezione CSRF. Transazioni su MSSQL, PostgreSQL e SQLite; MySQL può effettuare commit impliciti delle istruzioni DDL.
- Editor SQL con evidenziazione, risultati, cronologia nativa di sessione, modelli T-SQL e scorciatoia Ctrl/⌘+Invio.
- Copia query e salvataggio esplicito di una bozza nel browser, distinto per server, utente, database e schema. Non vengono salvate automaticamente query o password nel browser.
- Connessioni SQL Server cifrate anche con certificati autofirmati, come nella personalizzazione originale, sia con `sqlsrv` sia con `pdo_sqlsrv`.
- Interfaccia italiana e navigazione adattabile a schermi piccoli.

Il motore Adminer è integrato con pagine e logica applicativa dedicate per ricerca, operazioni e strumenti rapidi. La parità completa con phpMyAdmin non è raggiunta: procedure, funzioni e diagnostica avanzata SQL Server restano accessibili dall’editor SQL e dai modelli T-SQL, senza editor visuale dedicato. Import/export usa i formati e le capacità del driver Adminer: i dump MySQL non sono convertiti in T-SQL. Alcune query di sistema richiedono privilegi aggiuntivi.

La copia guidata duplica colonne, default, vincoli e indici ordinari, senza trigger e permessi. Su MSSQL legge direttamente i metadati per conservare IDENTITY con seed/incremento, tipi MAX e precisione, colonne calcolate, CHECK, chiavi esterne e indici clustered/nonclustered con colonne incluse e filtro. Tabelle temporali, memory-optimized, FileTable, colonne avanzate e indici XML/spaziali/columnstore vengono rifiutati per evitare copie incomplete. Il posizionamento fisico, le opzioni avanzate degli indici e sequenze PostgreSQL con configurazioni particolari richiedono revisione separata.

Gli script SSMS che contengono `GO` richiedono adattamento: `GO` è un separatore del client e non è T-SQL eseguibile. Per inviare una procedura come unico blocco, usare il modello dedicato nell’editor con `DELIMITER ;;`, terminare il blocco con `;;` e ripristinare `DELIMITER ;`. Il modello `CREATE OR ALTER PROCEDURE` richiede SQL Server 2016 SP1 o successivo; sulle versioni precedenti usare `CREATE PROCEDURE` o `ALTER PROCEDURE`.

## Avvio nel progetto MAMP

Aprire `http://localhost/adminermyadmin/` (aggiungere la porta configurata in MAMP, se necessaria). Il collegamento porta alla nuova applicazione in `public/`.

Per SQL Server servono le estensioni Microsoft nella versione PHP usata da MAMP. `pdo_dblib` può connettersi, ma TLS/certificati sono gestiti da FreeTDS e non dalle opzioni dell’applicazione: usare `sqlsrv` o `pdo_sqlsrv` per riprodurre la configurazione originale.

## Ubuntu con Docker

Prerequisiti: Docker Engine e Docker Compose. Dalla cartella del progetto:

```sh
docker compose up -d --build
```

Aprire `http://127.0.0.1:8080`. Il container include PHP 8.3, Apache, Microsoft ODBC Driver 18, `sqlsrv`, `pdo_sqlsrv` e i driver MySQL/PostgreSQL. La build richiede accesso ai repository Debian, Microsoft e PECL. L’applicazione non ha un database proprio e non avvia SQL Server.

In accesso indicare `nome-server:1433`, nome utente, password e, facoltativamente, database. Per Azure SQL indicare il database in fase di accesso. Dopo l’accesso selezionare lo schema, ad esempio `dbo`. Se SQL Server gira sull’host o su un altro server, utilizzare un indirizzo raggiungibile dal container: `localhost` nel container indica il container stesso.

La porta è pubblicata solo sul loopback. Per l’accesso remoto configurare il proprio reverse proxy HTTPS verso `127.0.0.1:8080`, oppure un tunnel SSH:

```sh
ssh -L 8080:127.0.0.1:8080 utente@server-ubuntu
```

Per verificare lo stato o leggere gli errori:

```sh
docker compose ps
docker compose logs --tail=100 app
docker compose exec app php -r 'echo implode(", ", PDO::getAvailableDrivers()), PHP_EOL;'
```

## Ubuntu senza Docker

Usare PHP 8.2 o successivo con `session`, `mbstring`, `openssl` e almeno un driver database. Consigliato PHP 8.3 con Microsoft ODBC Driver 18 e `sqlsrv`/`pdo_sqlsrv` compatibili.

Seguire la [guida Microsoft per Linux](https://learn.microsoft.com/en-us/sql/connect/php/installation-tutorial-linux-mac) per installare ODBC ed estensioni nella stessa versione PHP utilizzata da FPM/Apache. La configurazione TLS è descritta nella [documentazione delle opzioni di connessione](https://learn.microsoft.com/en-us/sql/connect/php/connection-options).

1. Copiare il progetto in `/var/www/adminermyadmin`.
2. Impostare la document root su `/var/www/adminermyadmin/public`.
3. Configurare PHP-FPM o Apache con PHP. `deploy/nginx.example.conf` è un esempio FPM 8.3; adattare nome host, socket, percorsi e HTTPS. `deploy/php.ini` contiene limiti di upload e impostazioni di sessione suggeriti.
4. Assicurare che PHP possa scrivere nella propria directory delle sessioni.
5. Eseguire `php tools/check.php` e verificare che il web server usi le stesse estensioni.

Per lo sviluppo, dove è possibile aprire una porta locale:

```sh
php -S 127.0.0.1:8080 -t public
```

## Configurazione

Copiare `config.example.php` in `config.local.php` e modificare le opzioni. Il file è escluso da Git e dalla build Docker. Per Docker, le opzioni disponibili sono passate tramite ambiente in `compose.yaml`; un eventuale file locale può essere montato in sola lettura come `/var/www/app/config.local.php`.

Per conservare la compatibilità con il server originale, `Encrypt=true` e `TrustServerCertificate=true` sono attivi. Quest’ultima opzione accetta il certificato senza verificarne l’identità. Con un certificato attendibile usare `sqlserver_trust_certificate => false` nel file locale, oppure:

```sh
AM_SQLSERVER_TRUST_CERTIFICATE=false docker compose up -d
```

SQLite è disabilitato finché non viene configurato `sqlite_password_hash` (oppure `AM_SQLITE_PASSWORD_HASH`). Generare l’hash con:

```sh
php -r 'echo password_hash(readline("Password SQLite: "), PASSWORD_DEFAULT), PHP_EOL;'
```

Nel form selezionare SQLite, usare la password configurata e inserire il percorso assoluto del file nel campo Database. Nome utente è un identificativo della sessione; non è un utente SQLite. L’accesso ai file resta limitato dai permessi del processo PHP.

## Organizzazione e aggiornamenti

```text
adminer/             Originali forniti, lasciati intatti
public/              Unica cartella da esporre in produzione
app/                 Ricerca, operazioni, generazione SQL, UI e configurazione
vendor/adminer.php   Motore con patch riproducibili di compatibilità e integrazione
deploy/              Esempi di configurazione server
tools/               Diagnostica e ricostruzione delle patch
tests/               Test delle operazioni tramite PHP CGI
```

Il motore mantiene le note di copyright e la doppia licenza Apache 2.0/GPL v2 di Adminer. Le patch passano database/TLS al DSN PDO SQL Server, aggiornano il quoting Unicode, aggiungono il punto di estensione per le azioni record e il salvataggio delle copie tramite INSERT, correggono i metadati MSSQL per MAX/precisione e limitano la lettura degli indici allo schema corretto. Ricostruire la copia con `python3 tools/patch-adminer.py`; lo script interrompe l’operazione se non riconosce esattamente i frammenti attesi. Gli aggiornamenti del motore vanno verificati prima di applicare le patch.

## Verifiche

```sh
php -l app/MyAdmin.php
node --check public/assets/app.js
python3 tests/smoke.py
python3 tests/workflows.py
php tests/sqlserver-plans.php
```

La suite di base cerca `php-cgi` nel PATH o nelle installazioni MAMP; per i nuovi flussi impostare il binario nel PATH o esplicitamente con `PHP_CGI=/percorso/php-cgi python3 tests/workflows.py`. I test creano database SQLite e sessioni temporanei, senza porte di rete e senza toccare database esistenti. `tests/sqlserver-plans.php` verifica il generatore SQL usando metadati di esempio: non apre una connessione MSSQL e non prova l’esecuzione del T-SQL sul server. Le connessioni reali SQL Server e la build Docker richiedono una verifica nell’ambiente di destinazione.

Le suite coprono CRUD, ricerca per campo, copia record e tabelle, rinomina, indici composti, eliminazione colonne, sostituzione, export multiplo, conferme non riutilizzabili, CSRF e rollback delle operazioni multiple. Verifica visuale nel browser non eseguita a causa delle restrizioni del sandbox.

Risultato dell’ultima verifica: 19 controlli di base e 42 controlli sui nuovi flussi superati sia con PHP 8.3.28 sia con PHP 8.5.5; ulteriori 14 controlli del generatore SQL MSSQL superati con metadati di esempio.
