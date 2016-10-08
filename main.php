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
$out_pauses         =   new \Keboola\Csv\CsvFile($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_puses.csv");
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
foreach ($instances as $idInstance) {
    // názvy vstupních souborů:  in_records_1, in_records_2, ... apod.
    ${"in_records_".$idInstance}        = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_records_"          .$idInstance.".csv");
    ${"in_recordSnapshots_".$idInstance}= new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_recordSnapshots_"  .$idInstance.".csv");
    ${"in_loginSessions_".$idInstance}  = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_loginSessions_"    .$idInstance.".csv");
    ${"in_pauseSessions_".$idInstance}  = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_pauseSessions_"    .$idInstance.".csv");
    ${"in_queueSessions_".$idInstance}  = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_queueSessions_"    .$idInstance.".csv");
    ${"in_users_".$idInstance}          = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_users_"            .$idInstance.".csv");
    ${"in_pauses_".$idInstance}         = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_pauses_"           .$idInstance.".csv");
    ${"in_queues_".$idInstance}         = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_queues_"           .$idInstance.".csv");
    ${"in_statuses_".$idInstance}       = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_statuses_"         .$idInstance.".csv");
    $in_queue_group                     = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_queue_group.csv");
    $in_groups                          = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_groups.csv");
    $in_instances                       = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_instances.csv");
}   

// zápis záznamů do výstupních souborů (záznamy ze všech instancí se zapíší do stejných výstupních souborů)
foreach ($instances as $idInstance) {                                   // procházení tabulek jednotlivých instancí Daktela
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // records → records fiefds, fieldValues
    $idFieldValue = 1;                  // inkrementální index pro hodnoty formulářových polí (pro každou instanci číslováno 1,2,3,...)
    foreach (${"in_records_".$idInstance} as $rowNum => $row) {         // ${"in_records_".$idInstance} ... $in_records_1, $in_records_2,...
        if ($rowNum == 0) {                                             // hlavička tabulky 'records'
            $colsNum = count($row);                                     // počet sloupců tabulky 'records'
            for ($i = $startId[$idInstance -1]; $i < $colsNum; $i++) {
                $out_fields -> writeRow([
                    $i - $startId[$idInstance -1] + 1,                  // idfield = 1,2,3,...
                    ltrim(ltrim($row[$i], "form_"), "field_"),          // názvy formulářových polí (bez úvodního 'form_' resp. 'form_field')
                    $idInstance
                ]);
            }
        } else {
            $out_records -> writeRow([
                $row[0]."_".sprintf("%03s", $idInstance),   // idrecord doplněný zleva o _ID instance v 3-ciferném tvaru
                $row[1],    // iduser               
                $row[2],    // idqueue
                $row[7],    // idstatus
                $row[5],    // number
                $row[9],    // call_id
                $row[10],   // edited
                $row[11],   // created
                $idInstance // idinstance
            ]);
            for ($i = $startId[$idInstance -1]; $i < $colsNum; $i++) {  // hodnoty v tabulce 'records'
                if (!strlen($row[$i])) {continue;}                      // formulářové pole nemá vyplněnou hodnotu               
                $out_fieldValues -> writeRow([                          // formulářové pole má vyplněnou hodnotu  
                    $idFieldValue,                                      // uměle vytvořený inkrementální index (1,2,3,...)
                    $row[0],                                            // idrecord
                    $i - $startId[$idInstance -1] + 1,                  // idfield = 1,2,3,...
                    $row[$i]                                            // value (hodnota ve formulářovém poli)
                ]);
                $idFieldValue++;
            }
        }
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // recordSnapshots
    foreach (${"in_recordSnapshots_".$idInstance} as $rowNum => $row) { // ${"in_recordSnapshots_".$idInstance} ... $in_recordSnapshots_1,...
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_recordSnapshots -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // idrecordsnapshot doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],    // iduser               
            $row[1],    // idrecord
            $row[8],    // idstatus
            "",         // call_id - NUTNO STANOVIT ALGORITMUS URČENÍ (V DATECH EXTRAHOVANÝCH Z DAKTELY TENTO ATRIBUT NENÍ; POTŘEBUJEME HO K NĚČEMU?)
            $row[10],   // created
            $row[11]    // created_by
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // loginSessions
    foreach (${"in_loginSessions_".$idInstance} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_loginSessions -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // idloginsession doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],    // start_time             
            $row[3],    // end_time
            $row[4],    // duration
            $row[1]     // iduser
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // pauseSessions
    foreach (${"in_pauseSessions_".$idInstance} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_pauseSessions -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // idpausesession doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],    // start_time             
            $row[3],    // end_time
            $row[4],    // duration
            $row[5],    // idpause
            $row[1]     // iduser
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // queueSessions
    foreach (${"in_queueSessions_".$idInstance} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_queueSessions -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // idqueuesession doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],    // start_time             
            $row[3],    // end_time
            $row[4],    // duration
            $row[5],    // idqueue
            $row[1]     // iduser
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // users
    foreach (${"in_users_".$idInstance} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_users -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // iduser doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[4],    // title    
            $idInstance,// idinstance
            $row[8]     // email
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // pauses
    foreach (${"in_pauses_".$idInstance} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_pauses -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // idpause doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],    // title    
            $idInstance,// idinstance
            $row[4],    // type
            $row[3]     // paid
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // queues, queue-group → queues
    foreach ($in_queue_group as $idqueue => $idgroup) {
        $queue_group[$idqueue] = $idgroup;                  // uložení tabulky queue-group do 1D-pole (IDQUEUE SE BERE JAKO UNIKÁTNÍ PRO VŠECHNY INSTANCE - NEROZLIŠUJE INSTANCI)
    }
    foreach (${"in_queues_".$idInstance} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_queues -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // idqueue doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],                // title    
            $idInstance,            // idinstance
            $queue_group[$row[0]]   // idgroup
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
    // statuses
    foreach (${"in_statuses_".$idInstance} as $rowNum => $row) {
        if ($rowNum == 0) {continue;}                                   // vynechání hlavičky tabulky
        $out_statuses -> writeRow([
            $row[0]."_".sprintf("%03s", $idInstance),       // idstatus doplněný zleva o _ID instance v 3-ciferném tvaru
            $row[2],    // title    
            ""          // status_call - NUTNO STANOVIT ALGORITMUS URČENÍ (HOVOROVÝ / NEHOVOROVÝ STAV, NULL = SYSTÉMOVÁ HODNOTA)
        ]);
    }
    // ------------------------------------------------------------------------------------------------------------------------------------------------------
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
}