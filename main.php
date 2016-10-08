<?php
require_once "vendor/autoload.php";

// načtení konfiguračního souboru
$dataDir    = getenv("KBC_DATADIR").DIRECTORY_SEPARATOR;
$configFile = $dataDir."config.json";
$config     = json_decode(file_get_contents($configFile), true);

// definice instancí Daktela
$instances  = array(1,2);   // ID instancí Daktela
$startId    = array(13,13); // ID sloupce v tabulce 'records' jednotlivých instancí, kde začínají hodnoty formulářových polí (číslováno od 0)

// vytvoření výstupních souborů
$out_fields         =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_fields.csv");
$out_fieldValues    =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_fieldValues.csv");
$out_records        =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_records.csv");
$out_recordSnapshots=   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_recordSnapshots.csv");
$out_loginSessions  =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_loginSessions.csv");
$out_pauseSessions  =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_pauseSessions.csv");
$out_queueSessions  =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_queueSessions.csv");
$out_users          =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_users.csv");
$out_pauses         =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_pauses.csv");
$out_queues         =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_queues.csv");
$out_statuses       =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_statuses.csv");
$out_groups         =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_groups.csv");
$out_instances      =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_instances.csv");

// zápis hlaviček do výstupních souborů
$out_fields         -> writeRow(["idfield", "title", "idinstance"]);
$out_fieldValues    -> writeRow(["idfieldvalue", "idrecord", "idfield", "value"]);
$out_records        -> writeRow(["idrecord", "iduser", "idqueue", "idstatus", "number", "call_id", "edited", "created", "idinstance"]);
$out_recordSnapshots-> writeRow(["idrecordsnapshot", "iduser", "idrecord", "idstatus", "call_id", "created", "created_by"]);
$out_loginSessions  -> writeRow(["idloginsession", "start_time", "end_time", "duration", "iduser"]);
$out_pauseSessions  -> writeRow(["idpausesession", "start_time", "end_time", "duration", "idpause", "iduser"]);
$out_queueSessions  -> writeRow(["idqueuesession", "start_time", "end_time", "duration", "idqueue", "iduser"]);
$out_users          -> writeRow(["iduser", "title", "idinstance", "email"]);
$out_pauses         -> writeRow(["idpause", "title", "idinstance", "type", "paid"]);
$out_queues         -> writeRow(["idqueue", "title", "idinstance", "idgroup"]);
$out_statuses       -> writeRow(["idstatus", "title", "status_call"]);

// načtení vstupních souborů
foreach ($instances as $idInst) {
    // názvy vstupních souborů:  in_records_1, in_records_2, ... apod.
    ${"in_records_".$idInst}        = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_records_".$idInst.".csv");
    ${"in_recordSnapshots_".$idInst}= new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_recordSnapshots_".$idInst.".csv");
    ${"in_loginSessions_".$idInst}  = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_loginSessions_".$idInst.".csv");
    ${"in_pauseSessions_".$idInst}  = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_pauseSessions_".$idInst.".csv");
    ${"in_queueSessions_".$idInst}  = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_queueSessions_".$idInst.".csv");
    ${"in_users_".$idInst}          = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_users_".$idInst.".csv");
    ${"in_pauses_".$idInst}         = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_pauses_".$idInst.".csv");
    ${"in_queues_".$idInst}         = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_queues_".$idInst.".csv");
    ${"in_statuses_".$idInst}       = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_statuses_".$idInst.".csv");
    $in_queue_group                 = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_queue_group.csv");
    $in_groups                      = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_groups.csv");
    $in_instances                   = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_instances.csv");
}   

// ==========================================================================================================================================================
// zápis záznamů do výstupních souborů

function addInstPref ($idInst, $string) {                           // funkce prefixuje hodnotu atributu (string) 3-ciferným identifikátorem instance
    if (!strlen($string)) {return "";} else {return sprintf("%03s", $idInst)."_".$string;} 
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