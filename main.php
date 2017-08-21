<?php
// TRANSFORMACE DAT Z LIBOVOLNÉHO POČTU INSTANCÍ DAKTELA

require_once "vendor/autoload.php";

$ds      = DIRECTORY_SEPARATOR;
$dataDir = getenv("KBC_DATADIR");
$homeDir = __DIR__;

require_once $homeDir.$ds."kbcparams.php";                                      // načtení parametrů importovaných z konfiguračního JSON řetězce v definici PHP aplikace v KBC
require_once $homeDir.$ds."variables.php";                                      // načtení definic proměnných a konstant
require_once $homeDir.$ds."functions.php";                                      // načtení definic funkcí

logInfo("PROMĚNNÉ A FUNKCE ZAVEDENY");                                          // volitelný diagnostický výstup do logu
logInfo("ZPRACOVÁVANÝ DATUMOVÝ ROZSAH:  ".$processedDates["start"]." ÷ ".$processedDates["end"]);
// ==============================================================================================================================================================================================
// načtení vstupních souborů
foreach ($instances as $instId => $inst) {
    foreach ($tabsList_InOut_InOnly[$inst["ver"]] as $file) {
        ${"in_".$file."_".$instId} = new Keboola\Csv\CsvFile($dataDir."in".$ds."tables".$ds."in_".$file."_".$instId.".csv");
    }
}
logInfo("VSTUPNÍ SOUBORY NAČTENY");     // volitelný diagnostický výstup do logu
// ==============================================================================================================================================================================================
logInfo("ZAHÁJENO NAČÍTÁNÍ DEFINICE DATOVÉHO MODELU");                          // volitelný diagnostický výstup do logu
$jsonList = $tiList = $fkList = [];
/* struktura polí:  $jsonList = [$instId => [$tab => [$colName => <0~jen rozparsovat / 1~rozparsovat a pokračovat ve zpracování hodnoty>]]] ... pole sloupců obsahujících JSON                    
                    $tiList   = [$instId => [$tab => <ID_časového_atributu>]]                 ... pole indexů sloupců pro časovou restrikci záznamů
                    $fkList   = [$instId => [$tab => [$colName => <název_nadřazené_tabulky>]]]... pole názvů nadřazených tabulek pro každý sloupec, který je FK
*/
foreach ($instances as $instId => $inst) {                                      // iterace instancí
    logInfo("NAČÍTÁNÍ DEFINICE INSTANCE ".$instId);                             // volitelný diagnostický výstup do logu        
    
    foreach ($tabs_InOut_InOnly[$inst["ver"]] as $tab => $cols) {               // iterace tabulek; $tab - název tabulky, $cols - pole s parametry sloupců
        logInfo("NAČÍTÁNÍ DEFINICE TABULKY ".$instId."_".$tab);                 // volitelný diagnostický výstup do logu   
        
        $colId   = 0;                                                           // počitadlo sloupců (číslováno od 0)
        $tiColId = NULL;                                                        // ID sloupce, který je v dané tabulce atributem pro datumovou restrikci (číslováno od 0)
        foreach ($cols as $colName => $colAttrs) {                              // iterace sloupců
            if (array_key_exists("json", $colAttrs)) {                          // nalezen sloupec, který je JSON
                $jsonList[$instId][$tab][$colName] = $colAttrs["json"];         // uložení příznaku způsobu zpracování JSONu (0/1) do pole $jsonList                                         //
                logInfo("TABULKA ".$instId."_".$tab." - NALEZEN JSON ".$colName."; DALŠÍ ZPRACOVÁNÍ PO PARSOVÁNÍ = ".$colAttrs["json"]);
            }
            if (array_key_exists("ti", $colAttrs)) {                            // nalezen sloupec, který je atributem pro časovou restrikci záznamů
                $tiColId = $colId;
                $tiList[$instId][$tab] = $colId;                                // uložení indexu sloupce (0, 1, 2, ...) do pole $tiList                                         //
                logInfo("TABULKA ".$instId."_".$tab." - ATRIBUT PRO ČASOVOU RESTRIKCI ZÁZNAMŮ: SLOUPEC #".$colId." (".$colName.")");
            }
            if (array_key_exists("fk", $colAttrs)) {                            // nalezen sloupec, který je PK
                $fkList[$instId][$tab][$colName] = $colAttrs["fk"];             // uložení názvu nadřezené tabulky do pole $fkList
                logInfo("TABULKA ".$instId."_".$tab." - NALEZEN FK DO TABULKY ".$colAttrs["fk"]." (SLOUPEC ".$colName.")");
            }
            $colId ++;                                                          // přechod na další sloupec            
        }        
    }
}
logInfo("DOKONČENO NAČTENÍ DEFINICE DATOVÉHO MODELU");
$expectedDigs = $idFormat["instId"] + $idFormat["idTab"];
logInfo("PŘEDPOKLÁDANÁ DÉLKA INDEXŮ VE VÝSTUPNÍCH TABULKÁCH JE ".$expectedDigs." ČÍSLIC");  // volitelný diagnostický výstup do logu
// ==============================================================================================================================================================================================

logInfo("ZAHÁJENO ZPRACOVÁNÍ DAT");     // volitelný diagnostický výstup do logu
$idFormatIdEnoughDigits = false;        // příznak potvrzující, že počet číslic určený proměnnou $idFormat["idTab"] dostačoval k indexaci záznamů u všech tabulek (vč. out-only položek)
$tabItems = [];                         // pole počitadel záznamů v jednotlivých tabulkách (ke kontrole nepřetečení počtu číslic určeném proměnnou $idFormat["idTab"])

while (!$idFormatIdEnoughDigits) {      // dokud není potvrzeno, že počet číslic určený proměnnou $idFormat["idTab"] dostačoval k indexaci záznamů u všech tabulek (vč. out-only položek)
    
    foreach ($tabs_InOut_OutOnly[6] as $tab => $cols) {        
        $tabItems[$tab] = 0;                                // úvodní nastavení nulových hodnot počitadel počtu záznamů všech OUT tabulek
        // vytvoření výstupních souborů    
        ${"out_".$tab} = new \Keboola\Csv\CsvFile($dataDir."out".$ds."tables".$ds."out_".$tab.".csv");
        // zápis hlaviček do výstupních souborů
        $colsOut = array_key_exists($tab, $colsInOnly) ? array_diff(array_keys($cols), $colsInOnly[$tab]) : array_keys($cols);
        $colPrf  = strtolower($tab)."_";                    // prefix názvů sloupců ve výstupní tabulce (např. "loginSessions" → "loginsessions_")
        $colsOut = preg_filter("/^/", $colPrf, $colsOut);   // prefixace názvů sloupců ve výstupních tabulkách názvy tabulek kvůli rozlišení v GD (např. "title" → "groups_title")
        ${"out_".$tab} -> writeRow($colsOut); 
    }
    logInfo("VÝSTUPNÍ SOUBORY VYTVOŘENY, ZÁHLAVÍ VLOŽENA"); // volitelný diagnostický výstup do logu

    // vytvoření záznamů s umělým ID v tabulkách definovaných proměnnou $tabsFakeRow (kvůli JOINu tabulek v GoodData) [volitelné]
    if ($emptyToNA) {
        /*foreach ($tabsFakeRow as $ftab) {
            $frow = array_merge([$fakeId, $fakeTitle], array_fill(2, $outTabsColsCount[$ftab] - 2, ""));
            ${"out_".$ftab} -> writeRow($frow);
            logInfo("VLOŽEN UMĚLÝ ZÁZNAM S ID \"".$fakeId."\" A NÁZVEM \"".$fakeTitle."\" DO VÝSTUPNÍ TABULKY ".$ftab); // volitelný diag. výstup do logu
        }               // umělý řádek do aktuálně iterované tabulky ... ["0", "", "", ... , ""]          
        */
        foreach ($tabsFakeRow as $ftab) {
            $frow = [];
            foreach ($tabs_InOut_OutOnly[6] + $tabs_InOut_OutOnly[5] as $tabName => $col) {
                foreach ($col as $colName => $colAttrs) {
                    if ($tabName == $ftab) {
                        if (array_key_exists($tabName, $colsInOnly)) {
                            if (in_array($colName, $colsInOnly[$tabName])) {continue;}                  // sloupec je in-only → přeskočit 
                        }
                        if (array_key_exists("fk", $colAttrs) || array_key_exists("pk", $colAttrs)) {   // do sloupců typu FK nebo PK ...
                            $frow[] = $fakeId;                                                          // ... se vloží $fakeId, ...
                        } elseif (array_key_exists("tt", $colAttrs)) {
                            $frow[] = $fakeTitle;                                                       // ... do sloupců obsahujících title se vloží $fakeTitle, ...
                        } else {
                            $frow[] = "";                                                               // ... do ostatních sloupců se vloží ptázdná hodnota
                        }
                    }
                }
            }
            ${"out_".$ftab} -> writeRow($frow);
            logInfo("VLOŽEN UMĚLÝ ZÁZNAM S ID \"".$fakeId."\" A NÁZVEM \"".$fakeTitle."\" DO VÝSTUPNÍ TABULKY ".$ftab); // volitelný diag. výstup do logu
        }
        // $out_groups -> writeRow([$fakeId, $fakeTitle]);  // není třeba
    }
    // ==========================================================================================================================================================================================
    // zápis záznamů do výstupních souborů

    // [A] tabulky sestavené ze záznamů více instancí (záznamy ze všech instancí se zapíší do stejných výstupních souborů

    setFieldsShift();                                       // výpočet konstant posunu indexování formulářových polí
    initStatuses();                                         // nastavení výchozích hodnot proměnných popisujících stavy
    initGroups();                                           // nastavení výchozích hodnot proměnných popisujících skupiny
    initDbGroups();                                         // nastavení výchozích hodnot proměnných popisujících skupiny databází

    foreach ($instCommonOuts as $tab => $common) {
        switch ($common) {
            case 0: ${"common".ucfirst($tab)}=false; break; // záznamy v tabulce budou indexovány pro každou instanci zvlášť
            case 1: ${"common".ucfirst($tab)}=true;         // záznamy v tabulce budou indexovány pro všechny instance společně
        }
    }
    
    // iterace instancí -------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    foreach ($instances as $instId => $inst) {              // procházení tabulek jednotlivých instancí Daktela
        initFields();                                       // nastavení výchozích hodnot proměnných popisujících formulářová pole         
        if (!$commonStatuses)       {initStatuses();    }   // ID a názvy v tabulce 'statuses' požadujeme uvádět pro každou instanci zvlášť    
        if (!$commonGroups)         {initGroups();      }   // ID a názvy v out-only tabulce 'groups' požadujeme uvádět pro každou instanci zvlášť
        if (!$commonDatabaseGroups) {initDbGroups();    }   // ID a názvy v out-only tabulce 'databaseGroups' požadujeme uvádět pro každou instanci zvlášť
        if (!$commonFieldValues)    {initFieldValues(); }   // ID a titles v tabulce 'fieldValues' požadujeme uvádět pro každou instanci zvlášť
        logInfo("ZAHÁJENO ZPRACOVÁNÍ INSTANCE ".$instId);   // volitelný diagnostický výstup do logu
        
        // iterace tabulek dané instance --------------------------------------------------------------------------------------------------------------------------------------------------------
        foreach ($tabs_InOut_InOnly[$inst["ver"]] as $tab => $cols) {               // iterace tabulek dané instance
            logInfo("ZAHÁJENO ZPRACOVÁNÍ TABULKY ".$instId."_".$tab);               // volitelný diagnostický výstup do logu           
            // iterace řádků dané tabulky -------------------------------------------------------------------------------------------------------------------------------------------------------
            foreach (${"in_".$tab."_".$instId} as $rowNum => $row) {                // načítání řádků vstupních tabulek [= iterace řádků]
                if ($rowNum == 0) {continue;}                                       // vynechání hlavičky tabulky
                // ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
                // při inkrementáním módu pro všechny nestatické tabulky (tj. nejen "calls" a "activities") přeskočení záznamů ležících mimo zpracovávaný datumový rozsah 
                if (!$incrCallsOnly) {                                              // inkrementálně zpracováváme všechny nestatické tabulky, nejen "calls" a "activities"
                    $dateRestrictColId = dateRestrictColId($instId, $tab);          // ID sloupce, který je v dané tabulce atributem pro datumovou restrikci (0,1,...), pokud v tabulce existuje
                    if (!is_null($dateRestrictColId)) {                             // sloupec pro datumovou restrikci záznamů v tabulce existuje
                        if (!dateRngCheck($row[$dateRestrictColId])) {continue;}    // hodnota atributu pro datumovou restrikci leží mimo zpracovávaný datumový rozsah → přechod na další řádek           
                    }          
                } 
                // ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
                $tabItems[$tab]++;                                                  // inkrement počitadla záznamů v tabulce
                if (checkIdLengthOverflow($tabItems[$tab])) {                       // došlo k přetečení délky ID určené proměnnou $idFormat["idTab"]
                    continue 4;                                                     // zpět na začátek cyklu 'while' (začít plnit OUT tabulky znovu, s delšími ID)
                }
          
                $colVals = $callsVals = $fieldRow = [];                             // řádek obecné výstupní tabulky | řádek výstupní tabulky 'calls' | záznam do pole formulářových polí     
                unset($idFieldSrcRec, $idqueue, $iduser, $type);                    // reset indexu zdrojového záznamu do out-only tabulky hodnot formulářových polí + ID front, uživatelů a typu aktivity                               

                $colId = 0;                                                         // index sloupce (v každém řádku číslovány sloupce 0,1,2,...) 
       
                foreach ($cols as $colName => $colAttrs) {                          // konstrukce řádku výstupní tabulky (vložení hodnot řádku) [= iterace sloupců]                    
                    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
                    switch ($colAttrs["instPrf"]) {                                 // prefixace hodnoty číslem instance (je-li požadována)
                        case 0: $hodnota = $row[$colId]; break;                     // hodnota bez prefixu instance
                        case 1: $hodnota = setIdLength($instId, $row[$colId]);      // hodnota s prefixem instance
                    }
                    // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
                    $afterJsonProc = jsonProcessing($instId,$tab,$colName,$hodnota);// jsonProcessing - test, zda je ve sloupci JSON; když ano, rozparsuje se
                    if (!$afterJsonProc) {$colId++; continue;}                      // přechod na další sloupec
                    
                    $colParentTab = colParentTab($instId, $tab, $colName);          // test, zda je daný sloupec FK; když ano, aplikuje se na hodnotu fce emptyToNA (u FK vrátí název nadřazené tabulky, u ne-FK NULL)
                    $hodnota = is_null($colParentTab) ? $hodnota : emptyToNA($hodnota); // emptyToNA - prázdné hodnoty nahradí $fakeId kvůli integritní správnosti
                    
                    switch ([$tab, $colName]) {
                        // TABULKY V5+6
                        case ["pauses", "paid"]:    $colVals[] = boolValsUnify($hodnota);                       // dvojici bool. hodnot ("",1) u v6 převede na dvojici hodnot (0,1) používanou u v5                                 
                                                    break;
                        case ["queues", "idgroup"]: $groupName = groupNameParse($hodnota);                      // název skupiny parsovaný z queues.idgroup pomocí delimiterů
                                                    if (empty($groupName)) {                                    // název skupiny ve vstupní tabulce 'queues' nevyplněn ...
                                                        $colVals[] = emptyToNA($groupName);  break;             // ... → ID skupiny se do výstupní tabulky 'queues' nezapíše
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
                                                    $colVals[] = $idGroupFormated;                              // vložení formátovaného ID skupiny do konstruovaného řádku výstupní tabulky 'queues'
                                                    break;
                        case ["calls", "call_time"]:if (!dateRngCheck($hodnota)) {                              // 'call_time' není z požadovaného rozsahu -> ...
                                                        continue 3;                                             // ... řádek z tabulky 'calls' přeskočíme
                                                    } else {                                                    // 'call_time' je z požadovaného rozsahu -> ...
                                                        $colVals[] = $hodnota; break;                           // ... 'call_time' použijeme a normálně pokračujeme v konstrukci řádku...
                                                    }
                        case ["calls", "answered"]: $colVals[] = boolValsUnify($hodnota);                       // dvojici bool. hodnot ("",1) u v6 převede na dvojici hodnot (0,1) používanou u v5                                 
                                                    break;
                        case ["calls", "clid"]:     $colVals[] = phoneNumberCanonic($hodnota);                  // veřejné tel. číslo v kanonickém tvaru (bez '+')
                                                    break;
                        case ["calls", "contact"]:  $colVals[] = "";                                            // zatím málo využívané pole, obecně objekt (JSON), pro použití by byl nutný json_decode
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
                        case ["fields", "idfield"]: $hodnota_shift = (int)$hodnota + $formFieldsIdShift;
                                                    $colVals[] = $fieldRow["idfield"] = $hodnota_shift;         // hodnota záznamu do pole formulářových polí
                                                    break;
                        case ["fields", "title"]:   $colVals[] = $fieldRow["title"]= $hodnota;                  // hodnota záznamu do pole formulářových polí
                                                    break;
                        case ["fields", "name"]:    $fieldRow["name"] = $hodnota;               // název klíče záznamu do pole formulářových polí
                                                    break;                                      // sloupec "name" se nepropisuje do výstupní tabulky "fields"                
                        case ["records","idrecord"]:$idFieldSrcRec = $colVals[] = $hodnota;     // uložení hodnoty 'idrecord' pro následné použití ve 'fieldValues'
                                                    break;
                        case ["records","idstatus"]:$colVals[] = $commonStatuses ? setIdLength(0, iterStatuses($hodnota), false) : $hodnota;
                                                    break;
                        case ["records", "number"]: $colVals[] = phoneNumberCanonic($hodnota);  // veřejné tel. číslo v kanonickém tvaru (bez '+')
                                                    break;
                        case ["records", "action"]: $colVals[] = actionCodeToName($hodnota);    // číselný kód akce převedený na název akce
                                                    break;                                               
                        case [$tab,"idinstance"]:   $colVals[] = $instId;  break;               // hodnota = $instId    
                        // ----------------------------------------------------------------------------------------------------------------------------------------------------------------------                                          
                        // TABULKY V6 ONLY
                        case ["databases", "description"]:
                                                    $dbGroupName = groupNameParse($hodnota);                    // název skupiny databází parsovaný z databases.description pomocí delimiterů
                                                    if (!strlen($dbGroupName)) {                                // název skupiny databází ve vstupní tabulce 'databases' nevyplněn ...
                                                        $colVals[] = "";  break;                                // ... → ID skupiny databází se do výstupní tabulky 'databases' nezapíše
                                                    }  
                                                    if (!array_key_exists($dbGroupName, $dbGroups)) {           // skupina databází daného názvu dosud není uvedena v poli $dbGroups 
                                                        $idDbGroup++;                                           // inkrement umělého ID skupiny databází   
                                                        if (checkIdLengthOverflow($idDbGroup)) {                // došlo k přetečení délky ID určené proměnnou $idDbGroup
                                                                continue 6;                                     // zpět na začátek cyklu 'while' (začít plnit OUT tabulky znovu, s delšími ID)
                                                            }
                                                        $idDbGroupFormated = setIdLength($instId,$idDbGroup,!$commonDatabaseGroups);// $commonDatabaseGroups → neprefixovat $idDbGroup identifikátorem instance
                                                        $dbGroups[$dbGroupName] = $idDbGroupFormated;           // zápis skupiny databází do pole $dbGroups
                                                        $out_databaseGroups -> writeRow([$idDbGroupFormated,$dbGroupName]); // zápis řádku do out-only tabulky 'databaseGroups' (řádek má tvar iddatabasegroup | dbGroupName)
                                                    } else {
                                                        $idDbGroupFormated = $dbGroups[$dbGroupName];           // získání iddatabasegroup dle názvu skupiny databází z pole $dbGroups
                                                    }                                                
                                                    $colVals[] = $idDbGroupFormated;                            // vložení formátovaného ID skupiny do konstruovaného řádku výstupní tabulky 'databases'
                                                    break;
                        case ["contacts","idcontact"]:$idFieldSrcRec = $colVals[]= $hodnota;    // uložení hodnoty 'idcontact' pro následné použití v 'contFieldVals'
                                                    break;
                        case ["contacts", "form"]:  // parsování "number" (veřejného tel. číslo) pro potřeby CRM records reportu
                                                    $telNum = "";
                                                    $numArr = getJsonItem($hodnota, "number");  // obecně vrací 1D-pole tel. čísel → beru jen první číslo
                                                    if (is_array($numArr)) {
                                                        if (array_key_exists(0, $numArr)) {
                                                            $telNum = phoneNumberCanonic($numArr[0]); // uložení tel. čísla do proměnné $telNum
                                                        }
                                                    }
                                                    break;                          // sloupec "form" se nepropisuje do výstupní tabulky "contacts"  
                        case ["contacts","number"]: $colVals[] = $telNum;           // hodnota vytvořená v case ["contacts", "form"]
                                                    break;
                        case ["tickets","idticket"]:$idFieldSrcRec = $colVals[] = $hodnota; // uložení hodnoty 'idticket' pro následné použití v 'tickFieldVals'
                                                    break;
                        case ["tickets", "email"]:  $colVals[] = convertMail($hodnota);
                                                    break;
                        case ["tickets","idstatus"]:$colVals[] = $commonStatuses ? setIdLength(0, iterStatuses($hodnota), false) : $hodnota;
                                                    break;
                        case ["crmRecords", "idcrmrecord"]:$idFieldSrcRec = $colVals[]= $hodnota;   // uložení hodnoty 'idcrmrecord' pro následné použití v 'crmFieldVals'
                                                    break;
                        case ["crmRecords", "idstatus"]:
                                                    $colVals[] = $commonStatuses ? setIdLength(0, iterStatuses($hodnota), false) : $hodnota;
                                                    break;
                        case ["crmRecordSnapshots", "idstatus"]:
                                                    $colVals[] = $commonStatuses ? setIdLength(0, iterStatuses($hodnota), false) : $hodnota;
                                                    break;
                        case ["activities", "idqueue"]:
                                                    $colVals[]= $idqueue= $hodnota; // $idqueue ... pro použití v case ["activities", "item"]
                                                    break;
                        case ["activities", "iduser"]:
                                                    $colVals[]= $iduser = $hodnota; // $iduser  ... pro použití v case ["activities", "item"]
                                                    break;
                        case ["activities", "idstatus"]:
                                                    $colVals[] = $commonStatuses ? setIdLength(0, iterStatuses($hodnota), false) : $hodnota;
                                                    break;
                        case ["activities", "type"]:$colVals[]= $type = $hodnota;   // $type ... pro použití v case ["activities", "item"]
                                                    break;                        
                        case ["activities", "item"]:$colVals[] = $hodnota;          // obecně objekt (JSON), propisováno do OUT bucketu i bez parsování (potřebuji 'duration' v performance reportu)
                                                    if ($type != "CALL") {break;}   // pro aktivity typu != CALL nepokračovat sestavením hodnot do tabulky 'calls'
                                                    
                                                    $item = json_decode($hodnota, true, JSON_UNESCAPED_UNICODE);
                                                    if (is_null($item)) {break;}    // hodnota dekódovaného JSONu je null → nelze ji prohledávat jako pole

                                                    // příprava hodnot do řádku výstupní tabulky 'calls':
                                                    if (!dateRngCheck($item["call_time"])) {continue 3;}    // 'call_time' není z požadovaného rozsahu -> řádek z tabulky 'activities' přeskočíme

                                                    $callsVals = [  setIdLength($instId, $item["id_call"]), // konstrukce řádku výstupní tabulky 'calls'
                                                                    $item["call_time"],
                                                                    $item["direction"],
                                                                    boolValsUnify($item["answered"]),
                                                                    emptyToNA(setIdLength($instId, $idqueue)),
                                                                    emptyToNA(setIdLength($instId, $iduser )),
                                                                    phoneNumberCanonic($item["clid"]),
                                                                    $item["contact"]["_sys"]["id"],//
                                                                    $item["did"],
                                                                    $item["wait_time"],
                                                                    $item["ringing_time"],
                                                                    $item["hold_time"],
                                                                    $item["duration"],
                                                                    $item["orig_pos"],
                                                                    $item["position"],
                                                                    $item["disposition_cause"],
                                                                    $item["disconnection_cause"],
                                                                    $item["pressed_key"],
                                                                    $item["missed_call"],
                                                                    $item["missed_call_time"],
                                                                    $item["score"],
                                                                    $item["note"],
                                                                    $item["attemps"],
                                                                    setIdLength($instId, $item["qa_user_id"]),
                                                                    $instId
                                                    ];
                                                    if (!empty($callsVals)) {                           // je sestaveno pole pro zápis do řádku výstupní tabulky 'calls'
                                                        $out_calls -> writeRow($callsVals);             // zápis sestaveného řádku do výstupní tabulky 'calls'
                                                    }
                                                    break; 
                        case ["crmFields", "idcrmfield"]:
                                                    $hodnota_shift = (int)$hodnota + $formCrmFieldsIdShift;
                                                    $colVals[] = $fieldRow["idfield"] = $hodnota_shift; // hodnota záznamu do pole formulářových polí
                                                    break;
                        case ["crmFields", "title"]:$colVals[] = $fieldRow["title"] = $hodnota;         // hodnota záznamu do pole formulářových polí
                                                    break;
                        case ["crmFields", "name"]: $fieldRow["name"] = $hodnota;                       // název klíče záznamu do pole formulářových polí
                                                    break;                                              // sloupec "name" se nepropisuje do výstupní tabulky "fields"                      
                        // ----------------------------------------------------------------------------------------------------------------------------------------------------------------------                                                  
                        default:                    $colVals[] = $hodnota;          // propsání hodnoty ze vstupní do výstupní tabulky bez úprav (standardní mód)
                    }
                    $colId++;                                                       // přechod na další sloupec (buňku) v rámci řádku                
                }   // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------              
                // operace po zpracování dat v celém řádku

                // přidání řádku do pole formulářových polí $fields (struktura pole je <idfield> => ["name" => <hodnota>, "title" => <hodnota>] )
                if ( !(!strlen($fieldRow["name"]) || !strlen($fieldRow["idfield"]) || !strlen($fieldRow["title"])) ) {  // je-li známý název, title i hodnota záznamu do pole form. polí...          
                        /*if ($instId == "3" && ($tab == "crmFields" || $tab == "fields")) {
                        echo "do pole 'fields' přidán záznam (idfield ".$fieldRow["idfield"].", name ".$fieldRow["name"].", title ".$fieldRow["title"].")\n";
                        } */
                    $fields[$fieldRow["idfield"]]["name"]  = $fieldRow["name"];     // ... provede se přidání prvku <idfield>["name"] => <hodnota> ...
                    $fields[$fieldRow["idfield"]]["title"] = $fieldRow["title"];    // ... a prvku <idfield>["title"] => <hodnota>
                }    
                $tabOut = ($tab != "crmFields") ? $tab : "fields";                  // záznamy z in-only tabulky 'crmFields' zapisujeme do in-out tabulky 'fields' 

                if (!empty($colVals)) {                                             // je sestaveno pole pro zápis do řádku výstupní tabulky
                    ${"out_".$tabOut} -> writeRow($colVals);                        // zápis sestaveného řádku do výstupní tabulky
                }
            }   // ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
            // operace po zpracování dat v celé tabulce
            logInfo("DOKONČENO ZPRACOVÁNÍ TABULKY ".$instId."_".$tab);              // volitelný diagnostický výstup do logu
        }
        logInfo("DOKONČENO ZPRACOVÁNÍ INSTANCE ".$instId);                      // volitelný diagnostický výstup do logu
        // operace po zpracování dat ve všech tabulkách jedné instance
                //echo "pole 'fields' instance ".$instId.":\n"; print_r($fields); echo "\n";        
    }
    // operace po zpracování dat ve všech tabulkách všech instancí

    // diagnostická tabulka - výstup pole $statuses
    $out_arrStat = new \Keboola\Csv\CsvFile($dataDir."out".$ds."tables".$ds."out_arrStat.csv");
    $out_arrStat -> writeRow(["id_status_internal", "title", "id_statuses_orig"]);
    foreach ($statuses as $statId => $statVals) {
        $colStatusesVals = [$statId, $statVals["title"], json_encode($statVals["statusIdOrig"])];
        $out_arrStat -> writeRow($colStatusesVals);
    }
    
    $idFormatIdEnoughDigits = true;         // potvrzení, že počet číslic určený proměnnou $idFormat["idTab"] dostačoval k indexaci záznamů u všech tabulek (vč. out-only položek)
}
// ==============================================================================================================================================================================================
// [B] tabulky společné pro všechny instance (nesestavené ze záznamů více instancí)
// instances
foreach ($instances as $instId => $inst) {
    $out_instances -> writeRow([$instId, $inst["url"]]);
}
logInfo("TRANSFORMACE DOKONČENA");          // volitelný diagnostický výstup do logu