<?php
// TRANSFORMACE DAT Z LIBOVOLNÉHO POČTU INSTANCÍ DAKTELA

require_once "vendor/autoload.php";

// načtení konfiguračního souboru
$ds         = DIRECTORY_SEPARATOR;
$dataDir    = getenv("KBC_DATADIR").$ds;

// pro případ importu parametrů zadaných JSON kódem v definici PHP aplikace v KBC
$configFile = $dataDir."config.json";
$config     = json_decode(file_get_contents($configFile), true);
// ==========================================================================================================================================================
// konstanty

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

// delimitery názvu skupiny v queues.idgroup
$delim = [ "L" => "[[" , "R" => "]]" ];

// klíčová slova pro validaci a konverzi obsahu formulářových polí
$keywords = [
    "dateEq" => ["od", "do"],
    "date"   => ["datum"],    
    "name"   => ["jméno", "jmeno", "příjmení", "prijmeni", "řidič", "ceo", "makléř", "předseda"],
    "addr"   => ["adresa", "address", "město", "mesto", "obec", "část obce", "ulice", "čtvrť", "okres"],
    "addrPrf"=> ["do", "k", "ke", "nad", "pod", "před", "u", "ve", "za", "č.o.", "č.p."],   // místopisné předložky
    "roman"  => ["i", "ii", "iii", "iv", "vi", "vii", "viii", "ix", "x", "xi", "xii", "xiii", "xiv", "xv", "xvi", "xvii", "xviii", "xix", "xx"],
    "noConv" => ["v"],                                                      // nelze rozhodnout mezi místopis. předložkou a řím. číslem → nekonvertovat case
    "mailEq" => ["mail", "email", "e-mail"],
    "psc"    => ["psč", "psc"]
];
// ==========================================================================================================================================================
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
// ==========================================================================================================================================================
// funkce

function addInstPref ($instId, $string) {               // prefixování hodnoty atributu (string) 4-ciferným identifikátorem instance
    return !strlen($string) ? "" : sprintf("%04s", $instId)."-".$string;    // prefixují se jen vyplněné hodnoty (strlen > 0)
}
function groupNameParse ($string) {                     // separace názvu skupiny jako podřetězce ohraničeného definovanými delimitery z daného řetězce
    global $delim;
    $match = [];                                        // "match array"
    preg_match("/".preg_quote($delim["L"])."(.*?)".preg_quote($delim["R"])."/s", $string, $match);
    return empty($match[1]) ?  "" : $match[1];          // $match[1] obsahuje podřetězec ohraničený delimitery ($match[0] dtto včetně delimiterů)
}
function phoneNumberCanonic ($str) {                    // veřejná tel. čísla omezená na číslice 0-9 (48-57D = 30-39H), bez úvodních nul (ltrim)
    $strConvert = ltrim(preg_replace("/[\\x00-\\x2F\\x3A-\\xFF]/", "", $str), "0");
    return (strlen($strConvert) == 9 ? "420" : "") . $strConvert;
}
function trim_all ($str, $what = NULL, $thrownWith = " ", $replacedWith = "| ") {      // odebrání nadbytečných mezer a formátovacích znaků z řetězce
    if ($what === NULL) {
        //  character   dec     hexa    use
        //  "\0"         0      \\x00   Null Character
        //  "\t"         9      \\x09   Tab
        //  "\n"        10      \\x0A   New line
        //  "\x0B"      11      \\x0B   Vertical Tab
        //  "\r"        13      \\x0D   New Line in Mac
        //  " "         32      \\x20   Space       
        $charsToThrow   = "\\x00-\\x09\\x0B-\\x20\\xFF";// all white-spaces and control chars (hexa)
        $charsToReplace = "\\x0A";                      // new line
    }
    $str = preg_replace("/[".$charsToThrow . "]+/", $thrownWith,   $str);       // náhrada prázdných a řídicích znaků mezerou
    $str = preg_replace("/[".$charsToReplace."]+/", $replacedWith, $str);       // náhrada odřádkování znakem "|" (vyskytují se i vícenásobná odřádkování)
    $str = str_replace ("|  ", "", $str);                                       // odebrání mezer oddělených "|" zbylých po vícenásobném odřádkování
    $str = str_replace ("\N" , "", $str);                   // zbylé "\N" způsobují chybu importu CSV do výst. tabulek ("Missing data for not-null field")
    return $str;
}
function substrInStr ($str, $substr) {                                          // test výskytu podřetězce v řetězci
    return strlen(strstr($str, $substr)) > 0;                                   // vrací true / false
}
function mb_ucwords ($str) {                                                    // ucwords pro multibyte kódování
    return mb_convert_case($str, MB_CASE_TITLE, "UTF-8");
}
function convertAddr ($str) {                                                   // nastavení velikosti písmen u adresy (resp. částí dresy)
    global $keywords;
    $addrArrIn  = explode(" ", $str);                                           // vstupní pole slov
    $addrArrOut = [];                                                           // výstupní pole slov
    foreach($addrArrIn as $id => $slovo) {                                      // iterace slov ve vstupním poli
        switch ($id) {                                                          // $id ... pořadí slova
            case 0:     $addrArrOut[] =  mb_ucwords($slovo); break;             // u 1. slova jen nastavit velké 1. písmeno a ostatní písmena malá
            default:    if (in_array(strtolower($slovo), $keywords["noConv"])) {
                            $addrArrOut[] = $slovo;                             // nelze rozhodnout mezi místopis. předložkou a řím. číslem → bez case konverze
                        } elseif (in_array(strtolower($slovo), $keywords["addrPrf"])) {
                            $addrArrOut[] = strtolower($slovo);                 // místopisné předložky malými písmeny
                        } elseif (in_array(strtolower($slovo), $keywords["roman"])) {
                            $addrArrOut[] = strtoupper($slovo);                 // římská čísla velkými znaky
                        } else {
                            $addrArrOut[] = mb_ucwords($slovo);                 // 2. a další slovo, pokud není uvedeno v $keywords
                        }
        }
    }
    return implode(" ", $addrArrOut);
}
function remStrDupl ($str, $delimiter = " ") {                                  // převod multiplicitních podřetězců v řetězci na jeden výskyt podřetězce
    return implode($delimiter, array_unique(explode($delimiter, $str)));
}
function convertDate ($datestr) {                                               // konverze data různého (i neznámého) formátu na požadovaný formát
    if (strlen($datestr) <= 12) {$datestr = str_replace(" ", "", $datestr);}    // odebrání mezer u data do délky dd. mm. rrrr (12 znaků)
    $datestr = preg_replace("/_/", "-", $datestr);                              // náhrada případných podtržítek pomlčkami
    try {
        $date = new DateTime($datestr);
    } catch (Exception $e) {                                                    // $datestr nevyhovuje konstruktoru třídy DateTime ...  
        return $datestr;                                                        // ... → vrátí původní datumový řetězec (nelze převést na požadovaný tvar)
    }  
    return $date -> format( (!strpos($datestr, "/") ? 'Y-m-d' : 'Y-d-m') )."\n";// vrátí rrrr-mm-dd (u delimiteru '/' je třeba prohodit m ↔ d)
}
function convertMail ($mail) {                                                  // validace e-mailové adresy a převod na malá písmena
    $mail = strtolower($mail);                                                  // převod e-mailové adresy na malá písmena
    $isValid = preg_match('/^[a-z\\.]+@[a-z]+\\.[a-z]+$/i', $mail);             // validace e-mailové adresy
    return $isValid ? $mail : "nevalidní e-mail ve formuláři";
}
function convertPSC ($str) {                                                    // vrátí buď PSČ ve tvaru xxx xx (validní), nebo "nevalidní PSČ ve formuláři"
    $str = str_replace(" ", "", $str);                                          // odebrání mezer => tvar validního PSČ je xxxxx
    return (is_numeric($str) && strlen($str) == 5) ? substr($str, 0, 3)." ".substr($str, 3, 2) : "nevalidní PSČ ve formuláři";
}
// ==========================================================================================================================================================
// zápis záznamů do výstupních souborů

// [A] tabulky sestavené ze záznamů více instancí (záznamy ze všech instancí se zapíší do stejných výstupních souborů)
foreach ($instancesIDs as $instId) {    // procházení tabulek jednotlivých instancí Daktela
    $idGroup      =  1;                 // inkrementální index pro číslování skupin (pro každou instanci číslováno 1,2,3,...)
    $groups       = [];                 // pole skupin (prvek pole má tvar groupName => idgroup)
    $idFieldValue =  1;                 // inkrementální index pro číslování hodnot formulářových polí (pro každou instanci číslováno 1,2,3,...)
    $fields       = [];                 // pole formulářových polí (prvek pole má tvar <name> => ["idfield" => <hodnota>, "title" => <hodnota>] )
    foreach ($tabsInOut as $table => $columns) {
        foreach (${"in_".$table."_".$instId} as $rowNum => $row) {              // načítání řádků vstupních tabulek
            if ($rowNum == 0) {continue;}                                       // vynechání hlavičky tabulky
            $colVals   = [];                                                    // řádek výstupní tabulky
            $fieldRow  = [];                                                    // záznam do pole formulářových polí           
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
                    case ["queues", "idgroup"]: $groupName = groupNameParse($hodnota);      // název skupiny parsovaný z queues.idgroup pomocí delimiterů
                                                if (!strlen($groupName)) {
                                                    $colVals[] = "";  break;                // název skupiny v tabulce 'queues' nevyplněn
                                                }
                                                if (!array_key_exists($groupName,$groups)) {// skupina daného názvu dosud není uvedena v poli $groups
                                                    $idGroupPrefixed = addInstPref($instId, $idGroup);
                                                    $groups[$groupName] = $idGroupPrefixed; // zápis skupiny do pole $groups
                                                    $out_groups -> writeRow([$idGroupPrefixed,$groupName]); // zápis řádku do out-only tabulky 'groups'
                                                    $idGroup++;                                             // (řádek má tvar idgroup | groupName)
                                                } else {
                                                    $idGroupPrefixed = $groups[$groupName]; // získání idgroup dle názvu skupiny z pole $groups
                                                }                                                
                                                $colVals[] = $idGroupPrefixed;
                                                break;
                    case ["fields", "idfield"]: $colVals[] = $hodnota;
                                                $fieldRow["idfield"]= $hodnota; // hodnota záznamu do pole formulářových polí
                                                break;
                    case ["fields", "title"]:   $colVals[] = $hodnota;
                                                $fieldRow["title"]= $hodnota;   // hodnota záznamu do pole formulářových polí
                                                break;
                    case ["fields", "name"]:    $fieldRow["name"]   = $hodnota; // název klíče záznamu do pole formulářových polí
                                                break;                          // sloupec "name" se nepropisuje do výstupní tabulky "fields"                    
                    case ["records","idrecord"]:$idRecord = $hodnota;           // uložení hodnoty 'idrecord' pro následné použití ve 'fieldValues'
                                                $colVals[] = $hodnota;
                                                break;
                    case ["records", "number"]: $colVals[] = phoneNumberCanonic($hodnota);  // veřejné tel. číslo v kanonickém tvaru (bez '+')
                                                break;
                    case ["records", "form"]:   foreach (json_decode($hodnota, true, JSON_UNESCAPED_UNICODE) as $key => $valArr) {
                                                                                            // $valArr je pole, obvykle má jen klíč 0 (nebo žádný)
                                                    if (!count($valArr)) {continue;}        // nevyplněné formulářové pole neobsahuje žádný prvek
                                                    foreach ($valArr as $val) { // klíč = 0,1,... (nezajímavé); $val jsou hodnoty form. polí
                                                        $fieldVals = [];                            // záznam do out-only tabulky 'fieldValues'
                                                        
                                                        // optimalizace hodnot formulářových polí, vyřazení prázdných hodnot
                                                        $val = remStrDupl($val);// value (hodnota form. pole zbavená multiplicitního výskytu podřetězců)
                                                        $val = trim_all($val);  // value (hodnota form. pole zbavená nadbyteč. mezer a formátovacích znaků)                                                        
                                                        if (!strlen($val)) {continue;}              // prázdná hodnota prvku formulářového pole - kontrola před korekcemi                                                                                   
                                                        // -------------------------------------------------------------------------------------------------
                                                        // validace a korekce hodnoty formulářového pole + konstrukce řádku out-only tabulky 'fieldValues'
                                                                                                        
                                                        $titleLow = mb_strtolower($fields[$key]["title"], "UTF-8"); // title malými písmeny (jen pro test výskytu klíč. slov v title)
                                                        
                                                        if (in_array($titleLow, $keywords["dateEq"])) {$val = convertDate($val);}
                                                        if (in_array($titleLow, $keywords["mailEq"])) {$val = convertMail($val);}
                                                        foreach ($keywords["date"] as $substr) {
                                                            if (substrInStr($titleLow, $substr)) {$val = convertDate($val);}
                                                        }
                                                        foreach ($keywords["name"] as $substr) {
                                                            if (substrInStr($titleLow, $substr)) {$val = mb_ucwords($val);}
                                                        }
                                                        foreach ($keywords["addr"] as $substr) {
                                                            if (substrInStr($titleLow, $substr)) {$val = convertAddr($val);}
                                                        }
                                                        foreach ($keywords["psc"] as $substr) {
                                                            if (substrInStr($titleLow, $substr)) {$val = convertPSC($val);}
                                                        }
                                                        if (!strlen($val)) {continue;}  // prázdná hodnota prvku formulářového pole - kontrola po korekcích
                                                        $fieldVals = [
                                                            addInstPref($instId, $idFieldValue),    // idfieldvalue
                                                            $idRecord,                              // idrecord
                                                            $fields[$key]["idfield"],               // idfield
                                                            $val                                    // korigovaná hodnota formulářového pole
                                                        ];
                                                        // -------------------------------------------------------------------------------------------------                                                                                                              
                                                        $idFieldValue++;
                                                        $out_fieldValues -> writeRow($fieldVals);   // zápis řádku do out-only tabulky 'fieldValues'
                                                    }    
                                                }                                                
                                                break;                          // sloupec "form" se nepropisuje do výstupní tabulky "records"    
                    case [$table,"idinstance"]: $colVals[] = $instId;  break;   // hodnota = $instId
                    default:                    $colVals[] = $hodnota;          // propsání hodnoty ze vstupní do výstupní tabulky bez úprav
                }
                $columnId++;                                                    // přechod na další sloupec (buňku) v rámci řádku                
            }   // -----------------------------------------------------------------------------------------------------------------------------------------                
            // operace po zpracování dat v celém řádku
            
            // přidání řádku do pole formulářových polí $fields (struktura pole je <name> => ["idfield" => <hodnota>, "title" => <hodnota>] )
            if ( !(!strlen($fieldRow["name"]) || !strlen($fieldRow["idfield"]) || !strlen($fieldRow["title"])) ) { // je-li známý název, title i hodnota záznamu do pole form. polí...
                $fields[$fieldRow["name"]]["idfield"] = $fieldRow["idfield"];   // ... provede se přidání prvku <name>["idfield"] => <hodnota> ...
                $fields[$fieldRow["name"]]["title"]   = $fieldRow["title"];     // ... a prvku <name>["title"] => <hodnota>
            }    
            
            ${"out_".$table} -> writeRow($colVals);                             // zápis sestaveného řádku do výstupní tabulky            
        }   // ---------------------------------------------------------------------------------------------------------------------------------------------
        // operace po zpracování dat v celé tabulce
        // ......        
    }        
}
// ==========================================================================================================================================================
// [B] tabulky společné pro všechny instance (nesestavené ze záznamů více instancí)
// instances
foreach ($instances as $instId => $url) {
    $out_instances -> writeRow([$instId, $url]);
}
?>
