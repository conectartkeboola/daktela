<?php

$temp = new Temp();
    $temp->initRunFolder();
    $filename = $temp->createFile("table.csv");
    $csv = new CsvFile($filename->getPathname());
    $csv->writeRow(["Id", "Name"]);
    $csv->writeRow(["1", "test"]);
    
$client->createTable("out.c-daktela-reports", "test", $csv);