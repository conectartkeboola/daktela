<?php
require_once "vendor/autoload.php";

// načtení konfiguračního souboru
$ds         = DIRECTORY_SEPARATOR;
$dataDir    = getenv("KBC_DATADIR").$ds;
$configFile = $dataDir."config.json";
$config     = json_decode(file_get_contents($configFile), true);

// seznam instancí Daktela
$instances  = array(1,2);   // ID instancí Daktela
$startId    = array(13,13); // ID sloupce v tabulce 'records' jednotlivých instancí, kde začínají hodnoty formulářových polí (číslováno od 0)

// struktura tabulek
$tabs = array(
    "fields"            =>  array ("idfield", "title", "idinstance"),
    "fieldValues"       =>  array ("idfieldvalue", "idrecord", "idfield", "value"),
    "records"           =>  array ("idrecord", "iduser", "idqueue", "idstatus", "number", "call_id", "edited", "created", "idinstance"),
    "recordSnapshots"   =>  array ("idrecordsnapshot", "iduser", "idrecord", "idstatus", "call_id", "created", "created_by"),
    "loginSessions"     =>  array ("idloginsession", "start_time", "end_time", "duration", "iduser"),
    "pauseSessions"     =>  array ("idpausesession", "start_time", "end_time", "duration", "idpause", "iduser"),
    "queueSessions"     =>  array ("idqueuesession", "start_time", "end_time", "duration", "idqueue", "iduser"),
    "users"             =>  array ("iduser", "title", "idinstance", "email"),
    "pauses"            =>  array ("idpause", "title", "idinstance", "type", "paid"),
    "queues"            =>  array ("idqueue", "title", "idinstance", "idgroup"),
    "statuses"          =>  array ("idstatus", "title", "status_call")
);

// seznam vstupních + výstupních CSV souborů, s kterými se pracuje individuálně pro každou instanci Daktely
$filesForInstances = array_keys($tabs);
// seznam vstupních + výstupních CSV souborů společných pro všechny instance Daktely
$filesCommon = array("groups", "instances");
// seznam pouze vstupních CSV souborů
$filesInOnly = array("queue_group");

// vytvoření výstupních souborů
foreach (array_merge($filesForInstances, $filesCommon) as $file) {
    ${"out_".$file} =   new \Keboola\Csv\CsvFile($dataDir."out".$ds."tables".$ds."out_".$file.".csv");
}

// zápis hlaviček do výstupních souborů
foreach ($tabs as $tabName => $columns) {
    ${"out_".$tabName} -> writeRow($columns);
}

// načtení vstupních souborů
foreach ($instances as $idInst) {
    // A) vstupní soubory generované individuálně pro každou instanci Daktely (názvy souborů: in_records_1, in_records_2, ... apod.)
    foreach ($filesForInstances as $file) {
        ${"in_".$file."_".$idInst} = new Keboola\Csv\CsvFile($dataDir."in".$ds."tables".$ds."in_".$file."_".$idInst.".csv");
    }
}
// B) vstupní soubory společné pro všechny instance Daktely
foreach (array_merge($filesCommon, $filesInOnly) as $file) {
    ${"in_".$file} = new Keboola\Csv\CsvFile($dataDir."in".$ds."tables".$ds."in_".$file.".csv");
}

// ==========================================================================================================================================================
// zápis záznamů do výstupních souborů

function addInstPref ($idInst, $string) {                           // funkce prefixuje hodnotu atributu (string) 4-ciferným identifikátorem instance
    if (!strlen($string)) {return "";} else {return sprintf("%04s", $idInst)."-".$string;} 
}

// A) tabulky sestavené ze záznamů více instancí (záznamy ze všech instancí se zapíší do stejných výstupních souborů)
foreach ($instances as $idInst) {                                   // procházení tabulek jednotlivých instancí Daktela
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // records → records fiefds, fieldValues
    $idFieldValue = 1;                  // inkrementální index pro hodnoty formulářových polí (pro každou instanci číslováno 1,2,3,...)
    foreach (${"in_records_".$idInst} as $rowNum => $row) {         // ${"in_records_".$idInst} ... $in_records_1, $in_records_2,...
        if ($rowNum == 0) {                                         // hlavička tabulky 'records'
            $colsNum = count($row);                                 // počet sloupců tabulky 'records'
            for ($i = $startId[$idInst -1]; $i < $colsNum; $i++) {
                $out_fields -> writeRow([
                    addInstPref($idInst, $i-$startId[$idInst-1]+1), // idfield = <idInstance>_1, <idInstance>_2, ...
                    ltrim(ltrim($row[$i], "form_"), "field_"),      // názvy formulářových polí (bez úvodního 'form_' resp. 'form_field')
                    $idInst
                ]);
            }
        } else {
            $out_records -> writeRow([
                addInstPref($idInst, $row[0]),                      // idrecord doplněný zleva o _ID instance v 3-ciferném tvaru
                addInstPref($idInst, $row[1]),                      // iduser               
                addInstPref($idInst, $row[2]),                      // idqueue
                addInstPref($idInst, $row[7]),                      // idstatus
                $row[5],                                            // number
                $row[9],                                            // call_id
                $row[10],                                           // edited
                $row[11],                                           // created
                $idInst                                             // idinstance
            ]);
            for ($i = $startId[$idInst -1]; $i < $colsNum; $i++) {  // hodnoty v tabulce 'records'
                if (!strlen($row[$i])) {continue;}                  // formulářové pole nemá vyplněnou hodnotu               
                $out_fieldValues -> writeRow([                      // formulářové pole má vyplněnou hodnotu  
                    addInstPref($idInst, $idFieldValue),            // uměle vytvořený inkrementální index (<idInstance>_1, <idInstance>_2, ...)
                    $row[0],                                        // idrecord
                    addInstPref($idInst, $i-$startId[$idInst-1]+1), // idfield = <idInstance>_1, <idInstance>_2, ...
                    $row[$i]                                        // value (hodnota ve formulářovém poli)
                ]);
                $idFieldValue++;
            }
        }
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // recordSnapshots
    foreach (${"in_recordSnapshots_".$idInst} as $rowNum => $row) { // ${"in_recordSnapshots_".$idInst} ... $in_recordSnapshots_1,...
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_recordSnapshots -> writeRow([
            addInstPref($idInst, $row[0]),                          // idrecordsnapshot doplněný zleva o _ID instance v 3-ciferném tvaru
            addInstPref($idInst, $row[2]),                          // iduser               
            addInstPref($idInst, $row[1]),                          // idrecord
            addInstPref($idInst, $row[8]),                          // idstatus
            "",                                                     // call_id - NUTNO STANOVIT ALGORITMUS URČENÍ (V DATECH EXTRAHOVANÝCH Z DAKTELY TENTO ATRIBUT NENÍ; POTŘEBUJEME HO K NĚČEMU?)
            $row[10],                                               // created
            addInstPref($idInst, $row[11])                          // created_by
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // loginSessions
    foreach (${"in_loginSessions_".$idInst} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_loginSessions -> writeRow([
            addInstPref($idInst, $row[0]),                          // idloginsession doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],                                                // start_time             
            $row[3],                                                // end_time
            $row[4],                                                // duration
            addInstPref($idInst, $row[1])                           // iduser
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // pauseSessions
    foreach (${"in_pauseSessions_".$idInst} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_pauseSessions -> writeRow([
            addInstPref($idInst, $row[0]),                          // idpausesession doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],                                                // start_time             
            $row[3],                                                // end_time
            $row[4],                                                // duration
            addInstPref($idInst, $row[5]),                          // idpause
            addInstPref($idInst, $row[1])                           // iduser
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // queueSessions
    foreach (${"in_queueSessions_".$idInst} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_queueSessions -> writeRow([
            addInstPref($idInst, $row[0]),                          // idqueuesession doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],                                                // start_time             
            $row[3],                                                // end_time
            $row[4],                                                // duration
            addInstPref($idInst, $row[5]),                          // idqueue
            addInstPref($idInst, $row[1])                           // iduser
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // users
    foreach (${"in_users_".$idInst} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_users -> writeRow([
            addInstPref($idInst, $row[0]),                          // iduser doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[4],                                                // title    
            $idInst,                                                // idinstance
            $row[8]                                                 // email
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // pauses
    foreach (${"in_pauses_".$idInst} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_pauses -> writeRow([
            addInstPref($idInst, $row[0]),                          // idpause doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],                                                // title    
            $idInst,                                                // idinstance
            $row[4],                                                // type
            $row[3]                                                 // paid
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // queues, queue-group → queues
    $queue_group = array();                                         // $queue_group     ... 2D-pole typu [n]=> $idqueue_idgroup;  n=1,2,3,...
    foreach ($in_queue_group as $rowNum => $idq_idg) {              // $idq_idg ... 1D-pole, 2-prvkové ([0]=> idqueue, [1]=> idgroup)
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $queue_group[$idq_idg[0]] = $idq_idg[1];                    // uložení tabulky queue-group do 1D-pole (IDQUEUE SE BERE JAKO UNIKÁTNÍ PRO VŠECHNY INSTANCE - NEROZLIŠUJE INSTANCI)
    }
    foreach (${"in_queues_".$idInst} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_queues -> writeRow([
            addInstPref($idInst, $row[0]),                          // idqueue doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],                                                // title    
            $idInst,                                                // idinstance
            addInstPref($idInst, $queue_group[$row[0]])             // idgroup
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // statuses
    foreach (${"in_statuses_".$idInst} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                               // vynechání hlavičky tabulky
        $out_statuses -> writeRow([
            addInstPref($idInst, $row[0]),                          // idstatus doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],                                                // title    
            ""                                                      // status_call - NUTNO STANOVIT ALGORITMUS URČENÍ (HOVOROVÝ / NEHOVOROVÝ STAV, NULL = SYSTÉMOVÁ HODNOTA)
        ]);
    }
}
// ==========================================================================================================================================================
// B) tabulky společné pro všechny instance (nesestavené ze záznamů více instancí)
// groups
foreach ($in_groups as $row) {
    $out_groups -> writeRow([
        $row[0],    // idgroup
        $row[1]     // title    
    ]);
}
// ------------------------------------------------------------------------------------------------------------------------------------------------------
// instances
foreach ($in_instances as $row) {
    $out_instances -> writeRow([
        $row[0],    // idinstance
        $row[1]     // url
    ]);
}