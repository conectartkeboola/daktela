<?php
// TRANSFORMACE DAT Z LIBOVOLNÉHO POČTU INSTANCÍ DAKTELA

require_once "vendor/autoload.php";

// načtení konfiguračního souboru
$ds         = DIRECTORY_SEPARATOR;
$dataDir    = getenv("KBC_DATADIR").$ds;

// pro případ importu parametrů zadaných JSON kódem v definici PHP aplikace v KBC
$configFile = $dataDir."config.json";
$config     = json_decode(file_get_contents($configFile), true);
// ==============================================================================================================================================================================================
// proměnné a konstanty

// seznam instancí Daktela
$instances = [  1   =>  "https://ilinky.daktela.com",
                2   =>  "https://dircom.daktela.com"
];
$instancesIDs = array_keys($instances);

// struktura tabulek
$tabsInOut = [
 // "název_tabulky"     =>  ["název_sloupce" => 0/1 ~ neprefixovat/prefixovat hodnoty ve sloupci identifikátorem instance]    
    "loginSessions"     =>  ["idloginsession" => 1, "start_time" => 0, "end_time" => 0, "duration" => 0, "iduser" => 1],
    "pauseSessions"     =>  ["idpausesession" => 1, "start_time" => 0, "end_time" => 0, "duration" => 0, "idpause" => 1, "iduser" => 1],
    "queueSessions"     =>  ["idqueuesession" => 1, "start_time" => 0, "end_time" => 0, "duration" => 0, "idqueue" => 1, "iduser" => 1],
    "users"             =>  ["iduser" => 1, "title" => 0, "idinstance" => 0, "email" => 0],
    "pauses"            =>  ["idpause" => 1, "title" => 0, "idinstance" => 0, "type" => 0, "paid" => 0],
    "queues"            =>  ["idqueue" => 1, "title" => 0, "idinstance" => 0, "idgroup" => 0],  // "idgroup je v IN tabulce NÁZEV → neprefixovat
    "statuses"          =>  ["idstatus" => 1, "title" => 0],    
    "recordSnapshots"   =>  ["idrecordsnapshot"=> 1, "iduser"=> 1, "idrecord"=> 1, "idstatus"=> 1, "call_id"=> 1, "created"=> 0, "created_by"=> 1],
    "fields"            =>  ["idfield" => 1, "title" => 0, "idinstance"  => 0, "name" => 0],    
    "records"           =>  ["idrecord"=>1,"iduser"=>1,"idqueue"=>1,"idstatus"=>1,"number"=>0,"call_id"=>1,"edited"=>0,"created"=>0,"idinstance"=>0,"form"=>0]
];
$tabsOutOnly = [
    "fieldValues"       =>  ["idfieldvalue" => 1, "idrecord" => 1, "idfield" => 1, "value" => 0],
    "groups"            =>  ["idgroup" => 1, "title" => 0],
    "instances"         =>  ["idinstance" => 0, "url" => 0]    
];
// nutno dodržet pořadí tabulek:
// - 'records' a 'recordSnapshots' se odkazijí na 'statuses'.'idstatus' → musí být uvedeny až za 'statuses' (pro případ použití commonStatuses)
// - 'records' a 'fieldValues' se tvoří pomocí pole $fields vzniklého z tabulky 'fields' → musí být uvedeny až za 'fields' (kvůli foreach)

$colsInOnly = [         // seznam sloupců, které se nepropíší do výstupních tabulek (slouží jen k internímu zpracování)
 // "název_tabulky"     =>  ["název_sloupce_1", "název_sloupce_2, ...]
    "fields"            =>  ["name"],
    "records"           =>  ["form"]
];
$tabsAll        = array_merge($tabsInOut, $tabsOutOnly);
$tabsInOutList  = array_keys ($tabsInOut);
$tabsAllList    = array_keys ($tabsAll);

// seznam výstupních tabulek, u kterých požadujeme mít ID a hodnoty společné pro všechny instance
                // "název_tabulky" => 0/1 ~ vypnutí/zapnutí volitelného požadavku na indexaci záznamů v tabulce společnou pro všechny instance
$instCommonOuts = ["statuses" => 1, "groups" => 1, "fieldValues" => 1];

// počty číslic, na které jsou doplňovány ID's (kvůli řazení v GoodData je výhodné mít konst. délku ID's) a oddělovač prefixu od hodnoty
$idFormat = [
    "separator" =>  "",                                 // znak oddělující ID instance od inkrementálního ID dané tabulky ("", "-" apod.)
    "instId"    =>  ceil(log10(count($instancesIDs))),  // počet číslic, na které je doplňováno ID instance (hodnota před oddělovačem) - určuje se dle počtu instancí
    "id"        =>  8                                   // výchozí počet číslic, na které je doplňováno inkrementální ID dané tabulky (hodnota za oddělovačem);
                                                        // příznakem potvrzujícím, že hodnota dostačovala k indexaci záznamů u všech tabulek, je proměnná $idFormatIdEnoughDigits;
                                                        // nedoplňovat = "" / 0 / NULL / []  (~ hodnota, kterou lze vyhodnotit jako empty)    
];

// delimitery názvu skupiny v queues.idgroup
$delim = [ "L" => "[[" , "R" => "]]" ];

// klíčová slova pro identifikaci typů formulářových polí a pro validaci + konverzi obsahu formulářových polí
$keywords = [
    "dateEq" => ["od", "do"],
    "mailEq" => ["mail", "email", "e-mail"],
    "date"   => ["datum"],
    "name"   => ["jméno", "jmeno", "příjmení", "prijmeni", "řidič", "ceo", "makléř", "předseda"],
    "addr"   => ["adresa", "address", "město", "mesto", "obec", "část obce", "ulice", "čtvrť", "ctvrt", "okres"],
    "psc"    => ["psč", "psc"],
    "addrVal"=> ["do","k","ke","mezi","na","nad","pod","před","při","pri","u","ve","za","čtvrť","ctvrt","sídliště","sidliste","sídl.","sidl.",
                 "ulice","ul.","třída","trida","tř.","tr.","nábřeží","nábř.","nabrezi","nabr.","alej","sady","park","provincie","svaz","území","uzemi",
                 "království","kralovstvi","republika","stát","stat","ostrovy", "okr.","okres","kraj", "kolonie","č.o.","c.o.","č.p.","c.p."],
                 // místopisné předložky a označení
    "romnVal"=> ["i", "ii", "iii", "iv", "vi", "vii", "viii", "ix", "x", "xi", "xii", "xiii", "xiv", "xv", "xvi", "xvii", "xviii", "xix", "xx"],
    "noConv" => ["v"]   // nelze rozhodnout mezi místopis. předložkou a řím. číslem → nekonvertovat case    
];
// ==============================================================================================================================================================================================
// funkce

function setIdLength ($instId =0,$str,$useInstPref =true) { // prefixování hodnoty atributu identifikátorem instance + nastavení požadované délky num. řetězců
    global $idFormat;
    switch (!strlen($str)) {
        case true:  return "";                              // vstupní hodnota je řetězec nulové délky
        case false: $idFormated = !empty($idFormat["id"]) ? sprintf('%0'.$idFormat["id"].'s', $str) : $str;
                    switch ($useInstPref) {                 // true = prefixovat hodnotu identifikátorem instance a oddělovacím znakem
                        case true:  return sprintf('%0'.$idFormat["instId"].'s', $instId) . $idFormat["separator"] . $idFormated;
                        case false: return $idFormated;    
                    }   
    }
}                                                       // prefixují se jen vyplněné hodnoty (strlen > 0)
function groupNameParse ($str) {                        // separace názvu skupiny jako podřetězce ohraničeného definovanými delimitery z daného řetězce
    global $delim;
    $match = [];                                        // "match array"
    preg_match("/".preg_quote($delim["L"])."(.*?)".preg_quote($delim["R"])."/s", $str, $match);
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
    foreach($addrArrIn as $id => $word) {                                       // iterace slov ve vstupním poli
        switch ($id) {                                                          // $id ... pořadí slova
            case 0:     $addrArrOut[] =  mb_ucwords($word); break;              // u 1. slova jen nastavit velké 1. písmeno a ostatní písmena malá
            default:    $wordLow = mb_strtolower($word, "UTF-8");               // slovo malými písmeny (pro test výskytu slova v poli $keywords aj.)
                        if (in_array($wordLow, $keywords["noConv"])) {
                            $addrArrOut[] = $word;                              // nelze rozhodnout mezi místopis. předložkou a řím. číslem → bez case konverze
                        } elseif (in_array($wordLow, $keywords["addrVal"])) {
                            $addrArrOut[] = $wordLow;                           // místopisné předložky a místopisná označení malými písmeny
                        } elseif (in_array($wordLow, $keywords["romnVal"])) {
                            $addrArrOut[] = strtoupper($word);                  // římská čísla velkými znaky
                        } else {
                            $addrArrOut[] = mb_ucwords($word);                  // 2. a další slovo, pokud není uvedeno v $keywords
                        }
        }
    }
    return implode(" ", $addrArrOut);
}
function remStrMultipl ($str, $delimiter = " ") {                               // převod multiplicitních podřetězců v řetězci na jeden výskyt podřetězce
    return implode($delimiter, array_unique(explode($delimiter, $str)));
}
function convertDate ($dateStr) {                                               // konverze data různého (i neznámého) formátu na požadovaný formát
    if (strlen($dateStr) <= 12) {$dateStr = str_replace(" ", "", $dateStr);}    // odebrání mezer u data do délky dd. mm. rrrr (12 znaků)
    $dateStr = preg_replace("/_/", "-", $dateStr);                              // náhrada případných podtržítek pomlčkami
    try {
        $date = new DateTime($dateStr);                                         // pokus o vytvoření objektu $date jako instance třídy DateTime z $dateStr
    } catch (Exception $e) {                                                    // $dateStr nevyhovuje konstruktoru třídy DateTime ...  
        return $dateStr;                                                        // ... vrátí původní datumový řetězec (nelze převést na požadovaný tvar)
    }                                                                           // $dateStr vyhovuje konstruktoru třídy DateTime ...  
    return $date -> format( (!strpos($dateStr, "/") ? 'Y-m-d' : 'Y-d-m') );     // ... vrátí rrrr-mm-dd (u delimiteru '/' je třeba prohodit m ↔ d)
}
function convertMail ($mail) {                                                  // validace e-mailové adresy a převod na malá písmena
    $mail = strtolower($mail);                                                  // převod e-mailové adresy na malá písmena
    $isValid = !(!filter_var($mail, FILTER_VALIDATE_EMAIL));                    // validace e-mailové adresy
    return $isValid ? $mail : "(nevalidní e-mail) ".$mail;                      // vrátí buď e-mail (lowercase), nebo e-mail s prefixem "(nevalidní e-mail) "
}
function convertPSC ($str) {                                                    // vrátí buď PSČ ve tvaru xxx xx (validní), nebo "nevalidní PSČ ve formuláři"
    $str = str_replace(" ", "", $str);                                          // odebrání mezer => pracovní tvar validního PSČ je xxxxx
    return (is_numeric($str) && strlen($str) == 5) ? substr($str, 0, 3)." ".substr($str, 3, 2) : "nevalidní PSČ ve formuláři";  // finální tvar PSČ je xxx xx
}
function convertFieldValue ($key, $val) {                                       // validace + případná korekce hodnot formulářových polí
    global $fields, $keywords;                                                  // $key = název klíče from. pole; $val = hodnota form. pole určená k validaci
    $titleLow = mb_strtolower($fields[$key]["title"], "UTF-8");                 // title malými písmeny (jen pro test výskytu klíčových slov v title)                                                                             
    if (in_array($titleLow, $keywords["dateEq"])) {return convertDate($val);}
    if (in_array($titleLow, $keywords["mailEq"])) {return convertMail($val);} 
    foreach (["date","name","addr","psc"] as $valType) {
        foreach ($keywords[$valType] as $substr) {
            switch ($valType) {
                case "date":    if (substrInStr($titleLow, $substr)) {return convertDate($val);}    continue;
                case "name":    if (substrInStr($titleLow, $substr)) {return mb_ucwords($val) ;}    continue;
                case "addr":    if (substrInStr($titleLow, $substr)) {return convertAddr($val);}    continue;
                case "psc" :    if (substrInStr($titleLow, $substr)) {return convertPSC($val) ;}    continue;
            }
        }
    }
    return $val;        // hodnota nepodléhající validaci a korekci (žádná část title form. pole není v $keywords[$valType]
}
function initGroups () {                // nastavení výchozích hodnot proměnných popisujících skupiny
    global $groups, $idGroup, $tabItems;
    $groups             = [];           // 1D-pole skupin - prvek pole má tvar groupName => idgroup
    $idGroup            = 0;            // umělý inkrementální index pro číslování skupin
    $tabItems["groups"] = 0;            // vynulování počitadla záznamů v tabulce 'groups'
}
function initStatuses () {              // nastavení výchozích hodnot proměnných popisujících stavy
    global $statuses, $idStatus, $idstatusFormated, $tabItems;
    $statuses = [];                     /* 3D-pole stavů - prvek pole má tvar  <statusId> => ["title" => <hodnota>, "statusIdOrig" => [pole hodnot]],
                                           kde statusId a title jsou unikátní, statusId jsou neformátované indexy (bez prefixu instance, který v commonStatus
                                           režimu nemá význam, a bez formátování na počet číslic požadovaný ve výstupních tabulkách)
                                           a v poli statusIdOrig jsou originální (prefixované) ID stejnojmenných stavů z různých instancí  */
    $idStatus             = 0;          // umělý inkrementální index pro číslování stavů (1, 2, ...)
    $tabItems["statuses"] = 0;          // vynulování počitadla záznamů v tabulce 'statuses'
    unset($idstatusFormated);           // formátovaný umělý index stavu ($idStatus doplněný na počet číslic požadovaný ve výstupních tabulkách)
}
function initFields () {                // nastavení výchozích hodnot proměnných popisujících formulářová pole
    global $fields;
    $fields = [];                       // 2D-pole formulářových polí - prvek pole má tvar <name> => ["idfield" => <hodnota>, "title" => <hodnota>]    
}
function initFieldValues (){
    global $idFieldValue;
    $idFieldValue = 0;                  // umělý inkrementální index pro číslování hodnot formulářových polí 
}
function iterStatuses ($val, $valType = "statusIdOrig") {   // prohledání 3D-pole stavů $statuses
    global $statuses;                   // $val = hledaná hodnota;  $valType = "title" / "statusIdOrig"
    foreach ($statuses as $statId => $statRow) {
        switch ($valType) {
            case "title":           // $statRow[$valType] je string
                                    if ($statRow[$valType] == $val) {   // zadaná hodnota v poli $statuses nalezena
                                        return $statId;                 // ... → vrátí id (umělé) položky pole $statuses, v níž se hodnota nachází
                                    }
                                    break;
            case "statusIdOrig":    // $statRow[$valType] je 1D-pole
                                    foreach ($statRow[$valType] as $statVal) {
                                        if ($statVal == $val) {     // zadaná hodnota v poli $statuses nalezena
                                            return $statId;         // ... → vrátí id (umělé) položky pole $statuses, v níž se hodnota nachází
                                        }
                                    }
        }        
    }
    return false;                       // zadaná hodnota v poli $statuses nenalezena
}
function checkIdLengthOverflow ($val) { // kontrola, zda došlo (true) nebo nedošlo (false) k přetečení délky ID určené proměnnou $idFormat["id"] ...
    global $idFormat;                   // ... nebo umělým ID (groups, statuses, fieldValues)
        if ($val > pow(10, $idFormat["id"])) {
            $idFormat["id"]++;
            return true;                // došlo k přetečení → je třeba začít plnit OUT tabulky znovu, s delšími ID
        }
    return false;                       // nedošlo k přetečení (OK)
}
// ==============================================================================================================================================================================================
// načtení vstupních souborů
    foreach ($instancesIDs as $instId) {
        foreach ($tabsInOutList as $file) {
            ${"in_".$file."_".$instId} = new Keboola\Csv\CsvFile($dataDir."in".$ds."tables".$ds."in_".$file."_".$instId.".csv");
        }
    }
// ==============================================================================================================================================================================================
$idFormatIdEnoughDigits = false;        // příznak potvrzující, že počet číslic určený proměnnou $idFormat["id"] dostačoval k indexaci záznamů u všech tabulek (false = počáteční hodnota)
$tabItems = [];                         // pole počitadel záznamů v jednotlivých tabulkách (ke kontrole nepřetečení počtu číslic určeném proměnnou $idFormat["id"])

while (!$idFormatIdEnoughDigits) {      // dokud není potvrzeno, že počet číslic určený proměnnou $idFormat["id"] dostačoval k indexaci záznamů u všech tabulek
    foreach ($tabsInOutList as $tab) {
        $tabItems[$tab] = 0;            // úvodní nastavení nulových hodnot počitadel počtu záznamů všech OUT tabulek
    }
    
    // vytvoření výstupních souborů

    foreach ($tabsAllList as $file) {
        ${"out_".$file} = new \Keboola\Csv\CsvFile($dataDir."out".$ds."tables".$ds."out_".$file.".csv");
    }
    // zápis hlaviček do výstupních souborů
    foreach ($tabsAll as $tab => $cols) {
        $colsOut = array_key_exists($tab, $colsInOnly) ? array_diff(array_keys($cols), $colsInOnly[$tab]) : array_keys($cols);
        ${"out_".$tab} -> writeRow($colsOut);
    }    
    // ==========================================================================================================================================================================================
    // zápis záznamů do výstupních souborů

    // [A] tabulky sestavené ze záznamů více instancí (záznamy ze všech instancí se zapíší do stejných výstupních souborů)

    initStatuses();                                         // nastavení výchozích hodnot proměnných popisujících stavy
    initGroups();                                           // nastavení výchozích hodnot proměnných popisujících skupiny
    
    foreach ($instCommonOuts as $tab => $common) {
        switch ($common) {
            case 0:     ${"common".ucfirst($tab)} = false;  // záznamy v tabulce budou indexovány pro každou instanci zvlášť
            case 1:     ${"common".ucfirst($tab)} = true;   // záznamy v tabulce budou indexovány pro všechny instance společně
        }
    }
    
    foreach ($instancesIDs as $instId) {                    // procházení tabulek jednotlivých instancí Daktela
        initFields();                                       // nastavení výchozích hodnot proměnných popisujících formulářová pole         
        if (!$commonStatuses)    {initStatuses();   }       // ID a názvy v tabulce 'statuses' požadujeme uvádět pro každou instanci zvlášť    
        if (!$commonGroups)      {initGroups();     }       // ID a názvy v out-only tabulce 'groups' požadujeme uvádět pro každou instanci zvlášť
        if (!$commonFieldValues) {initFieldValues();}       // ID a titles v tabulce 'fieldValues' požadujeme uvádět pro každou instanci zvlášť  

        foreach ($tabsInOut as $tab => $cols) {
            
            foreach (${"in_".$tab."_".$instId} as $rowNum => $row) {                // načítání řádků vstupních tabulek
                if ($rowNum == 0) {continue;}                                       // vynechání hlavičky tabulky
                
                $tabItems[$tab]++;                                                  // inkrement počitadla záznamů v tabulce
                if (checkIdLengthOverflow($tabItems[$tab])) {                       // došlo k přetečení délky ID určené proměnnou $idFormat["id"]
                    continue 4;                                                     // zpět na začátek cyklu 'while' (začít plnit OUT tabulky znovu, s delšími ID)
                }
                
                $colVals   = [];                                                    // řádek výstupní tabulky
                $fieldRow  = [];                                                    // záznam do pole formulářových polí           
                unset($idRecord);                                                   // reset indexu záznamů do výstupní tabulky 'records'
                $columnId  = 0;                                                     // index sloupce (v každém řádku číslovány sloupce 0,1,2,...)
                foreach ($cols as $colName => $prefixVal) {                         // konstrukce řádku výstupní tabulky (vložení hodnot řádku)
                    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
                    switch ($prefixVal) {
                        case 0: $hodnota = $row[$columnId]; break;                  // hodnota bez prefixu instance
                        case 1: $hodnota = setIdLength($instId, $row[$columnId]);   // hodnota s prefixem instance
                    }
                    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
                    switch ([$tab, $colName]) {
                        case ["queues", "idgroup"]: $groupName = groupNameParse($hodnota);                      // název skupiny parsovaný z queues.idgroup pomocí delimiterů
                                                    if (!strlen($groupName)) {                                  // název skupiny ve vstupní tabulce 'queues' nevyplněn ...
                                                        $colVals[] = "";  break;                                // ... → stav se do výstupní tabulky 'queues' nezapíše
                                                    }  
                                                    if (!array_key_exists($groupName, $groups)) {               // skupina daného názvu dosud není uvedena v poli $groups 
                                                        $idGroup++;                                             // inkrement umělého ID skupiny   
                                                        if (checkIdLengthOverflow($idGroup)) {                  // došlo k přetečení délky ID určené proměnnou $idGroup
                                                                continue 6;                                     // zpět na začátek cyklu 'while' (začít plnit OUT tabulky znovu, s delšími ID)
                                                            }
                                                        $idGroupFormated = setIdLength($instId,$idGroup,!$commonGroups);// $commonGroups → neprefixovat $idGroup identifikátorem instance
                                                        $groups[$groupName] = $idGroupFormated;                 // zápis skupiny do pole $groups
                                                        $out_groups -> writeRow([$idGroupFormated,$groupName]); // zápis řádku do out-only tabulky 'groups' (řádek má tvar idgroup | groupName)                                                                                                                                                              
                                                    } else {
                                                        $idGroupFormated = $groups[$groupName];                 // získání idgroup dle názvu skupiny z pole $groups
                                                    }                                                
                                                    $colVals[] = $idGroupFormated;                              // vložení formátovaného ID skupiny jako prvního prvku do konstruovaného řádku 
                                                    break;
                        case["statuses","idstatus"]:if ($commonStatuses) {                                      // ID a názvy v tabulce 'statuses' požadujeme společné pro všechny instance  
                                                        $statIdOrig = $hodnota;                                 // uložení originálního (prefixovaného) ID stavu do proměnné $statIdOrig
                                                    } else {                                                    // ID a názvy v tabulce 'statuses' požadujeme uvádět pro každou instanci zvlášť
                                                        $colVals[]  = $hodnota;                                 // vložení formátovaného ID stavu jako prvního prvku do konstruovaného řádku
                                                    }              
                                                    break;
                        case ["statuses", "title"]: if ($commonStatuses) {                                      // ID a názvy v tabulce 'statuses' požadujeme společné pro všechny instance
                                                        $iterRes = iterStatuses($hodnota, "title");             // výsledek hledání title v poli $statuses (umělé ID stavu nebo false)
                                                        if (!$iterRes) {                                        // stav s daným title dosud v poli $statuses neexistuje
                                                            $idStatus++;                                        // inkrement umělého ID stavů
                                                            if (checkIdLengthOverflow($idStatus)) {             // došlo k přetečení délky ID určené proměnnou $idStatus
                                                                continue 6;                                     // zpět na začátek cyklu 'while' (začít plnit OUT tabulky znovu, s delšími ID)
                                                            }
                                                            $statuses[$idStatus]["title"]          = $hodnota;  // zápis hodnot stavu do pole $statuses
                                                            $statuses[$idStatus]["statusIdOrig"][] = $statIdOrig;
                                                            $colVals[] = setIdLength(0, $idStatus, false);      // vložení formátovaného ID stavu jako prvního prvku do konstruovaného řádku                                        
                                                            
                                                        } else {                                                // stav s daným title už v poli $statuses existuje
                                                            $statuses[$iterRes]["statusIdOrig"][] = $statIdOrig;// připsání orig. ID stavu jako dalšího prvku do vnořeného 1D-pole ve 3D-poli $statuses
                                                            break;                                              // aktuálně zkoumaný stav v poli $statuses už existuje
                                                        }
                                                        unset($statIdOrig);                                     // unset proměnné s uloženou hodnotou originálního (prefixovaného) ID stavu (úklid)
                                                    }                                             
                                                    $colVals[] = $hodnota;                                      // vložení title stavu jako druhého prvku do konstruovaného řádku                                           
                                                    break;
                        case ["recordSnapshots", "idstatus"]:
                                                    $colVals[] = $commonStatuses ? setIdLength(0, iterStatuses($hodnota), false) : $hodnota;
                                                    break;
                        case ["fields", "idfield"]: $colVals[] = $hodnota;
                                                    $fieldRow["idfield"]= $hodnota;             // hodnota záznamu do pole formulářových polí
                                                    break;
                        case ["fields", "title"]:   $colVals[] = $hodnota;
                                                    $fieldRow["title"]= $hodnota;               // hodnota záznamu do pole formulářových polí
                                                    break;
                        case ["fields", "name"]:    $fieldRow["name"] = $hodnota;               // název klíče záznamu do pole formulářových polí
                                                    break;                                      // sloupec "name" se nepropisuje do výstupní tabulky "fields"                    
                        case ["records","idrecord"]:$idRecord  = $hodnota;                      // uložení hodnoty 'idrecord' pro následné použití ve 'fieldValues'
                                                    $colVals[] = $hodnota;
                                                    break;
                        case ["records","idstatus"]:$colVals[] = $commonStatuses ? setIdLength(0, iterStatuses($hodnota), false) : $hodnota;
                                                    break;
                        case ["records", "number"]: $colVals[] = phoneNumberCanonic($hodnota);  // veřejné tel. číslo v kanonickém tvaru (bez '+')
                                                    break;
                        case ["records", "form"]:   foreach (json_decode($hodnota, true, JSON_UNESCAPED_UNICODE) as $key => $valArr) {
                                                                                                // $valArr je pole, obvykle má jen klíč 0 (nebo žádný)
                                                        if (empty($valArr)) {continue;}         // nevyplněné formulářové pole - neobsahuje žádný prvek
                                                        foreach ($valArr as $val) {             // klíč = 0,1,... (nezajímavé); $val jsou hodnoty form. polí
                                                            $fieldVals = [];                    // záznam do out-only tabulky 'fieldValues'

                                                            // optimalizace hodnot formulářových polí, vyřazení prázdných hodnot
                                                            $val = remStrMultipl($val);         // value (hodnota form. pole zbavená multiplicitního výskytu podřetězců)
                                                            $val = trim_all($val);              // value (hodnota form. pole zbavená nadbyteč. mezer a formátovacích znaků)                                                        
                                                            if (!strlen($val)) {continue;}      // prázdná hodnota prvku formulářového pole - kontrola před korekcemi                                                                                   
                                                            // ----------------------------------------------------------------------------------------------------------------------------------
                                                            // validace a korekce hodnoty formulářového pole + konstrukce řádku out-only tabulky 'fieldValues'
                                                            $idFieldValue++;                            // inkrement umělého ID hodnot formulářových polí
                                                            if (checkIdLengthOverflow($idFieldValue)) { // došlo k přetečení délky ID určené proměnnou $idFieldValue
                                                                continue 8;                             // zpět na začátek cyklu 'while' (začít plnit OUT tabulky znovu, s delšími ID)
                                                            }
                                                            // ----------------------------------------------------------------------------------------------------------------------------------  
                                                            $val = convertFieldValue($key, $val);       // je-li část názvu klíče $key v klíčových slovech $keywords, ...
                                                                                                        // vrátí validovanou/konvertovanou hodnotu $val, jinak nezměněnou $val                                                            
                                                            if (!strlen($val)) {continue;}              // prázdná hodnota prvku formulářového pole - kontrola po korekcích
                                                            
                                                            $fieldVals = [
                                                                setIdLength($instId,$idFieldValue,!$commonFieldValues), // idfieldvalue
                                                                $idRecord,                                              // idrecord
                                                                $fields[$key]["idfield"],                               // idfield
                                                                $val                                                    // korigovaná hodnota formulářového pole
                                                            ];                                                                                                                                                                     
                                                            $out_fieldValues -> writeRow($fieldVals);   // zápis řádku do out-only tabulky 'fieldValues'
                                                        }    
                                                    }                                                
                                                    break;                          // sloupec "form" se nepropisuje do výstupní tabulky "records"    
                        case [$tab,"idinstance"]:   $colVals[] = $instId;  break;   // hodnota = $instId
                        default:                    $colVals[] = $hodnota;          // propsání hodnoty ze vstupní do výstupní tabulky bez úprav (standardní mód)
                    }
                    $columnId++;                                                    // přechod na další sloupec (buňku) v rámci řádku                
                }   // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------              
                // operace po zpracování dat v celém řádku

                // přidání řádku do pole formulářových polí $fields (struktura pole je <name> => ["idfield" => <hodnota>, "title" => <hodnota>] )
                if ( !(!strlen($fieldRow["name"]) || !strlen($fieldRow["idfield"]) || !strlen($fieldRow["title"])) ) { // je-li známý název, title i hodnota záznamu do pole form. polí...
                    $fields[$fieldRow["name"]]["idfield"] = $fieldRow["idfield"];   // ... provede se přidání prvku <name>["idfield"] => <hodnota> ...
                    $fields[$fieldRow["name"]]["title"]   = $fieldRow["title"];     // ... a prvku <name>["title"] => <hodnota>
                }    

                if (!empty($colVals)) {                                             // je sestaveno pole pro zápis do řádku výstupní tabulky
                    ${"out_".$tab} -> writeRow($colVals);                           // zápis sestaveného řádku do výstupní tabulky
                }
            }   // ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
            // operace po zpracování dat v celé tabulce
            // < ... nothing to do ... >    
        }        
    }
    $idFormatIdEnoughDigits = true;         // potvrzení, že počet číslic určený proměnnou $idFormat["id"] dostačoval k indexaci záznamů u všech tabulek
}
// ==============================================================================================================================================================================================
// [B] tabulky společné pro všechny instance (nesestavené ze záznamů více instancí)
// instances
foreach ($instances as $instId => $url) {
    $out_instances -> writeRow([$instId, $url]);
}
?>
