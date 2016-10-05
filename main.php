<?php
require_once "vendor/autoload.php";

// načtení konfiguračního souboru
$dataDir    = getenv("KBC_DATADIR").DIRECTORY_SEPARATOR;
$configFile = $dataDir."config.json";
$config     = json_decode(file_get_contents($configFile), true);
//$konstanta = $config["parameters"]["konstanta"];

// definice instancí Daktela
$instances  = array(1,2);   // ID instancí Daktela
$startId    = array(13,13); // ID sloupce v tabulce 'records' jednotlivých instancí, kde začínají hodnoty formulářových polí (číslováno od 0)

// vytvoření výstupních souborů
$out_fields = new \Keboola\Csv\CsvFile
    ($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_fields.csv");
$out_fieldValues = new \Keboola\Csv\CsvFile
    ($dataDir."out".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."out_fieldValues.csv");

// zápis hlaviček do výstupních souborů
$out_fields      -> writeRow(["idfield", "title", "idinstance"]);
$out_fieldValues -> writeRow(["idfieldvalue", "idrecord", "idfield", "value"]);

// načtení vstupních souborů
foreach ($instances as $idInstance) {
    ${"in_records_".$idInstance} = new Keboola\Csv\CsvFile($dataDir."in".DIRECTORY_SEPARATOR."tables".DIRECTORY_SEPARATOR."in_records_".$idInstance.".csv");
}   // názvy vstupních souborů:  in_records_1, in_records_2, ...

// zápis záznamů do výstupních souborů (záznamy ze všech instancí se zapíší do stejných výstupních souborů)
foreach ($instances as $idInstance) {                           // procházení tabulek 'records' jednotlivých instancí Daktela
    $idFieldValue = 1;          // inkrementální index pro hodnoty formulářových polí (pro každou instanci číslováno 1,2,3,...)
    foreach (${"in_records_".$idInstance} as $rowNum => $row) { // ${"in_records_".$idInstance} ... $in_records_1, $in_records_2,...
        if ($rowNum == 0) {                                     // hlavička tabulky 'records'
            $colsNum = count($row);                             // počet sloupců tabulky 'records'
            for ($i = $startId[$idInstance -1]; $i < $colsNum; $i++) {
                $out_fields -> writeRow([
                    $i - $startId[$idInstance -1] + 1,          // idfield = 1,2,3,...
                    $row[$i],                                   // názvy formulářových polí
                    $idInstance
                ]);
            }
        } else {        
            for ($i = $startId; $i < $colsNum; $i++) {          // hodnoty v tabulce 'records'
                if (!strlen($row[$i])) {continue;}              // formulářové pole nemá vyplněnou hodnotu               
                $out_fieldValues -> writeRow([                  // formulářové pole má vyplněnou hodnotu  
                    $idFieldValue,                              // uměle vytvořený inkrementální index (1,2,3,...)
                    $row[0],                                    // idrecord
                    $i - $startId[$idInstance -1] + 1,          // idfield = 1,2,3,...
                    $row[$i]                                    // value (hodnota ve formulářovém poli)
                ]);
                $idFieldValue++;

            }
        }
    }
}