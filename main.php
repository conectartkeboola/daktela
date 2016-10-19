<?php
require_once "vendor/autoload.php";

// načtení konfiguračního souboru
$ds         = DIRECTORY_SEPARATOR;
$dataDir    = getenv("KBC_DATADIR").$ds;

// pro případ importu parametrů zadaných JSON kódem v definici PHP aplikace v KBC
$configFile = $dataDir."config.json";
$config     = json_decode(file_get_contents($configFile), true);

// seznam instancí Daktela
$instances = [  1   =>  "https://ilinky.daktela.com",
                2   =>  "https://dircom.daktela.com"
];
$instancesIDs = array_keys($instances);

// struktura tabulek
$tabsInOut = [
 // "název_tabulky"     =>  ["názec_sloupce" => 0/1 ~ neprefixovat/prefixovat hodnoty ve sloupci číslem instance]    
    "recordSnapshots"   =>  ["idrecordsnapshot"=> 1, "iduser"=> 1, "idrecord"=> 1, "idstatus"=> 1, "call_id"=> 1, "created"=> 0, "created_by"=> 1],
    "loginSessions"     =>  ["idloginsession" => 1, "start_time" => 0, "end_time" => 0, "duration" => 0, "iduser" => 1],
    "pauseSessions"     =>  ["idpausesession" => 1, "start_time" => 0, "end_time" => 0, "duration" => 0, "idpause" => 1, "iduser" => 1],
    "queueSessions"     =>  ["idqueuesession" => 1, "start_time" => 0, "end_time" => 0, "duration" => 0, "idqueue" => 1, "iduser" => 1],
    "users"             =>  ["iduser" => 1, "title" => 0, "idinstance" => 0, "email" => 0],
    "pauses"            =>  ["idpause" => 1, "title" => 0, "idinstance" => 0, "type" => 0, "paid" => 0],
    "queues"            =>  ["idqueue" => 1, "title" => 0, "idinstance" => 0, "idgroup" => 0],  // "idgroup je v IN tabulce NÁZEV → neprefixovat
    "statuses"          =>  ["idstatus" => 1, "title" => 0],    
    "fields"            =>  ["idfield" => 1, "title" => 0, "idinstance"  => 0, "name" => 0],    
    "records"           =>  ["idrecord"=>1,"iduser"=>1,"idqueue"=>1,"idstatus"=>1,"number"=>0,"call_id"=>1,"edited"=>0,"created"=>0,"idinstance"=>0,"form"=>0]    
];
$tabsOutOnly = [
    "fieldValues"       =>  ["idfieldvalue" => 1, "idrecord" => 1, "idfield" => 1, "value" => 0],
    "groups"            =>  ["idgroup" => 1, "title" => 0],
    "instances"         =>  ["idinstance" => 0, "url" => 0]
    // 'records' a 'fieldValues' se tvoří pomocí pole $fields vzniklého z tabulky 'fields' → musí být uvedeny až za 'fields' (kvůli foreach)
];
$colsInOnly = [         // seznam sloupců, které se nepropíší do výstupních tabulek (slouží jen k internímu zpracování)
 // "název_tabulky"     =>  ["název_sloupce_1", "název_sloupce_2, ...]
    "fields"            =>  ["name"],
    "records"           =>  ["form"]
];
$tabsAll        = array_merge($tabsInOut, $tabsOutOnly);
$tabsInOutList  = array_keys ($tabsInOut);
$tabsAllList    = array_keys ($tabsAll);

// vytvoření výstupních souborů
foreach ($tabsAllList as $file) {
    ${"out_".$file} =   new \Keboola\Csv\CsvFile($dataDir."out".$ds."tables".$ds."out_".$file.".csv");
}
// zápis hlaviček do výstupních souborů
foreach ($tabsAll as $tabName => $columns) {
    $colsOut = array_key_exists($tabName, $colsInOnly) ? array_diff(array_keys($columns), $colsInOnly[$tabName]) : array_keys($columns);
    ${"out_".$tabName} -> writeRow($colsOut);
}
// načtení vstupních souborů
foreach ($instancesIDs as $instId) {
    foreach ($tabsInOutList as $file) {
        ${"in_".$file."_".$instId} = new Keboola\Csv\CsvFile($dataDir."in".$ds."tables".$ds."in_".$file."_".$instId.".csv");
    }
}

// delimitery názvu skupiny v queues.idgroup
$groupNameL = "[[";
$groupNameR = "]]";
// ==========================================================================================================================================================
// funkce

function addInstPref ($instId, $string) {               // prefixování hodnoty atributu (string) 4-ciferným identifikátorem instance
    return !strlen($string) ? "" : sprintf("%04s", $instId)."-".$string;    // prefixují se jen vyplněné hodnoty (strlen > 0)
}
function groupNameParse ($string) {                     // separace názvu skupiny jako podřetězce ohraničeného definovanými delimitery z daného řetězce
    global $groupNameL, $groupNameR;
    $match = [];
    preg_match("/".preg_quote($groupNameL)."(.*?)".preg_quote($groupNameR)."/s", $string, $match);
    return empty($match[1]) ?  "" : $match[1];          // $match[1] obsahuje podřetězec ohraničený delimitery ($match[0] dtto včetně delimiterů)
}
function trim_all ($str, $what = NULL, $thrownWith = " ", $replacedWith = " | ") {      // odebrání nadbytečných mezer a formátovacích znaků z řetězce
    if ($what === NULL) {
        //  char    decimal     hexa    use
        //  "\0"         0      \\x00   Null Character
        //  "\t"         9      \\x09   Tab
        //  "\n"        10      \\x0A   New line
        //  "\x0B"      11      \\x0B   Vertical Tab
        //  "\r"        13      \\x0D   New Line in Mac
        //  " "         32      \\x20   Space       
        $charsToThrow   = "\\x00-\\x09\\x0B-\\x20\\xFF";// all white-spaces and control chars (hexa)
        $charsToReplace = "\\x0A";                      // new line
    }
    $str1 = preg_replace("/[".$charsToThrow . "]+/", $thrownWith,   $str );     // náhrada prázdných a řídicích znaků mezerou
    $str2 = preg_replace("/[".$charsToReplace."]+/", $replacedWith, $str1);     // náhrada odřádkování znakem "|" (vyskytují se i vícenásobná odřádkování)
    $str3 = str_replace("\N", "", $str2);               // reliktní "\N" způsobují chybu importu CSV do výst. tabulek ("Missing data for not-null field")
    return $str3;
}
// ==========================================================================================================================================================
// zápis záznamů do výstupních souborů

// [A] tabulky sestavené ze záznamů více instancí (záznamy ze všech instancí se zapíší do stejných výstupních souborů)
foreach ($instancesIDs as $instId) {    // procházení tabulek jednotlivých instancí Daktela
    $idGroup      = 1;                  // inkrementální index pro číslování skupin (pro každou instanci číslováno 1,2,3,...)
    $idFieldValue = 1;                  // inkrementální index pro číslování hodnot formulářových polí (pro každou instanci číslováno 1,2,3,...)
    $fields = [];                       // pole formulářových polí (prvek pole má tvar "name => idfield")
    foreach ($tabsInOut as $table => $columns) {
        foreach (${"in_".$table."_".$instId} as $rowNum => $row) {              // načítání řádků vstupních tabulek
            if ($rowNum == 0) {continue;}                                       // vynechání hlavičky tabulky
            $colVals   = [];                                                    // řádek výstupní tabulky
            $groupVals = [];                                                    // záznam do out-only tabulky 'groups'
            $fieldRow  = [];                                                    // záznam do pole formulářových polí            
            $fieldVals = [];                                                    // záznam do out-only tabulky 'fieldValues'
            unset($idRecord);                                                   // reset indexu záznamů do výstupní tabulky 'records'
            $columnId  = 0;                                                     // index sloupce (v každém řádku číslovány sloupce 0,1,2,...)
            foreach ($columns as $colName => $prefixVal) {                      // konstrukce řádku výstupní tabulky (vložení hodnot řádku)
                // -----------------------------------------------------------------------------------------------------------------------------------------
                switch ($prefixVal) {
                    case 0: $hodnota = $row[$columnId]; break;                  // hodnota bez prefixu instance
                    case 1: $hodnota = addInstPref($instId, $row[$columnId]);   // hodnota s prefixem instance
                }
                // -----------------------------------------------------------------------------------------------------------------------------------------
                switch ([$table, $colName]) {
                    case ["queues", "idgroup"]: $groupName = groupNameParse($hodnota);  // název skupiny parsovaný z queues.idgroup pomocí delimiterů
                                                if (!strlen($groupName)) {
                                                    $colVals[] = "";  break;    // název skupiny v tabulce 'queues' nevyplněn                                               
                                                }
                                                $idGroupPrefixed = addInstPref($instId, $idGroup);
                                                $groupVals = [
                                                    $idGroupPrefixed,           // idgroup
                                                    $groupName                  // title (= název skupiny)
                                                ];
                                                $$idGroup++;
                                                $colVals[] = $idGroupPrefixed;
                                                $out_groups -> writeRow($groupVals);   // zápis řádku do out-only tabulky 'groups'
                                                break;
                    case ["fields", "idfield"]: $colVals[] = $hodnota;
                                                $fieldRow["idfield"]= $hodnota; // hodnota záznamu do pole formulářových polí
                                                break;
                    case ["fields", "name"]:    $fieldRow["name"]   = $hodnota; // název klíče záznamu do pole formulářových polí
                                                break;                          // sloupec "name" se nepropisuje do výstupní tabulky "fields"                    
                    case ["records","idrecord"]:$idRecord = $hodnota;           // uložení hodnoty 'idrecord' pro následné použití ve 'fieldValues'
                                                $colVals[] = $hodnota;
                                                break;
                    case ["records", "number"]: $colVals[] = str_replace(" ", "", $hodnota);// veřejné tel. číslo (bez případných mezer)
                                                break;
                    case ["records", "form"]:   foreach (json_decode($hodnota, true, JSON_UNESCAPED_UNICODE) as $key => $valArr) {
                                                                                            // $valArr je pole, obvykle má jen klíč 0 (nebo žádný)
                                                    if (count($valArr) == 0) {continue;}    // nevyplněné form. pole neobsahuje žádný prvek
                                                    foreach ($valArr as $val) { // klíč = 0,1,... (nezajímavé); $val jsou hodnoty form. polí
                                                        $fieldVals = [
                                                            addInstPref($instId, $idFieldValue),    // idfieldvalue
                                                            $idRecord,          // idrecord
                                                            $fields[$key],      // idfield
                                                            trim_all($val)      // value (hodnota form. pole zbavená nadbyteč. mezer a formátovacích znaků)
                                                        ]; 
                                                        $idFieldValue++;
                                                        $out_fieldValues -> writeRow($fieldVals);   // zápis řádku do out-only tabulky 'fieldValues'
                                                    }    
                                                }                                                
                                                break;                          // sloupec "form" se nepropisuje do výstupní tabulky "records"    
                    case [$table,"idinstance"]: $colVals[] = $instId;  break;   // hodnota = $instId
                    default:                    $colVals[] = $hodnota;          // propsání hodnoty ze vstupní do výstupní tabulky bez úprav
                }
                $columnId++;                                                    // přechod na další sloupec (buňku) v rámci řádku
                // -----------------------------------------------------------------------------------------------------------------------------------------                
            }
            if ( !(!strlen($fieldRow["idfield"]) || !strlen($fieldRow["name"])) ) { // je-li známý název klíče i hodnota záznamu do pole form. polí...
                $fields[$fieldRow["name"]] = $fieldRow["idfield"];                  // ... provede se přidání záznamu ("name => idfield")
            }    
            ${"out_".$table} -> writeRow($colVals);                             // zápis sestaveného řádku do výstupní tabulky
        }
    }        
}
// ==========================================================================================================================================================
// [B] tabulky společné pro všechny instance (nesestavené ze záznamů více instancí)
// instances
foreach ($instances as $instId => $url) {
    $out_instances -> writeRow([$instId, $url]);
}
?>
