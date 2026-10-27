"""Rebuild the local Adminer engine from the untouched 5.4.2 source."""
from pathlib import Path

root = Path(__file__).resolve().parent.parent
source = (root / 'adminer/adminer.php').read_bytes()
patches = [
    (
        b'return$this->dsn("sqlsrv:Server=$Nd".($Rg?",$Rg":""),$V,$F);',
        b'$ssl=adminer()->connectSsl();$dsn="sqlsrv:Server=$Nd".($Rg?",$Rg":"");$db=adminer()->database();if($db!=="")$dsn.=";Database={".str_replace("}","}}",$db)."}";foreach(array("Encrypt","TrustServerCertificate")as$option){if(isset($ssl[$option]))$dsn.=";$option=".($ssl[$option]?"1":"0");}return$this->dsn($dsn,$V,$F);',
    ),
    (
        b'$tj=strlen($Q)!=strlen(utf8_decode($Q));',
        b'$tj=(bool)preg_match("~[\\x80-\\xFF]~",$Q);',
    ),
    # Row shortcuts are added inside the native selection column, preserving bulk editing.
    (
        b'.lang(272)."</a>"));reset($M);foreach($K',
        b'.lang(272)."</a>".(method_exists(adminer(),"rowActions")?adminer()->rowActions($a,$L[$Cf],$x):"")));reset($M);foreach($K',
    ),
    # A copy opens the native editor with source values, then saves via INSERT.
    (
        b'$_j=(isset($_GET["select"])?$_POST["edit"]:$Z);foreach($o',
        b'$_j=(isset($_GET["select"])?$_POST["edit"]:$Z);if(!empty($_GET["am_clone"]))$_j=false;foreach($o',
    ),
    (
        b'$ya=($_POST["clone"]&&$n["auto_increment"]?',
        b'$ya=(($_POST["clone"]||!empty($_GET["am_clone"]))&&$n["auto_increment"]?',
    ),
    # MSSQL MAX/precision metadata must produce valid types in search and structure forms.
    (
        b'$Re=(preg_match("~char|binary~",$U)?intval($K["max_length"])/($U[0]==\'n\'?2:1):($U=="decimal"?"$K[precision],$K[scale]":""));',
        b'$Re=(preg_match("~char|binary~",$U)?($K["max_length"]==-1?"max":intval($K["max_length"])/($U[0]==\'n\'?2:1)):(in_array($U,array("decimal","numeric"))?"$K[precision],$K[scale]":(in_array($U,array("time","datetime2","datetimeoffset"))?(string)$K["scale"]:"")));',
    ),
    (
        b'"full_type"=>$U.($Re?"($Re)":""),"type"=>$U,"length"=>$Re,"default"=>(preg_match("~^\\(\'(.*)\'\\)$~",$K["default"]',
        b'"full_type"=>$U.($Re!==""?"($Re)":""),"type"=>$U,"length"=>$Re,"default"=>(preg_match("~^\\(\'(.*)\'\\)$~",$K["default"]',
    ),
    # Do not mix indexes from equal-named tables in other schemas or included columns.
    (
        b'WHERE OBJECT_NAME(i.object_id) = ".q($R),$h)',
        b'WHERE i.object_id = OBJECT_ID(".q(table($R)).") AND ic.key_ordinal > 0",$h)',
    ),
    # Only the primary index determines the per-column primary-key flag.
    (
        b'LEFT JOIN sys.index_columns ic ON c.object_id = ic.object_id AND c.column_id = ic.column_id\nLEFT JOIN sys.indexes i ON ic.object_id = i.object_id AND ic.index_id = i.index_id',
        b'LEFT JOIN sys.index_columns ic ON c.object_id = ic.object_id AND c.column_id = ic.column_id AND ic.index_id IN (SELECT pki.index_id FROM sys.indexes pki WHERE pki.object_id = c.object_id AND pki.is_primary_key = 1)\nLEFT JOIN sys.indexes i ON ic.object_id = i.object_id AND ic.index_id = i.index_id',
    ),
    (
        b'WHERE c.object_id = ".q($Ci))as$K)',
        b'WHERE c.object_id = ".q($Ci)." ORDER BY c.column_id")as$K)',
    ),
]
for before, after in patches:
    if source.count(before) != 1:
        raise SystemExit('Sorgente Adminer inatteso: patch interrotta. Verificare la versione.')
    source = source.replace(before, after)
(root / 'vendor/adminer.php').write_bytes(source)
print('vendor/adminer.php aggiornato: TLS, Unicode, azioni record e copia record.')
